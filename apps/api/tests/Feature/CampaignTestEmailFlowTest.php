<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignTestMailAttempt;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CampaignTestEmailFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_save_immutable_draft_revisions_and_send_one_smtp_test_to_one_explicit_recipient(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->verifiedSenderDomain($workspace, $owner, 'synthetic.testdomain');
        $this->configureSymfonyArrayTransport();

        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $this->draftPayload())
            ->assertRedirect();

        $campaign = Campaign::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $revision = $campaign->latestRevision;
        $this->assertSame('Synthetic first test campaign', $campaign->name);
        $this->assertSame('A short synthetic test message.', $revision->payload['text_body']);
        $this->assertArrayNotHasKey('send_setup', $revision->payload);
        $this->assertSame(0, CampaignRecipientSnapshot::query()->count());
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());

        $this->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'subject' => 'Updated synthetic subject',
        ])->assertRedirect(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]));

        $campaign->refresh()->load('latestRevision');
        $revision = $campaign->latestRevision;
        $this->assertSame(2, $revision->revision_number);
        $this->assertSame('Updated synthetic subject', $revision->payload['subject']);

        $payload = [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $revision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ];
        $url = route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]);

        $this->post($url, $payload)
            ->assertRedirect(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertSessionHas('status', 'The SMTP transport accepted this one test email. Mailbox delivery is not confirmed.');

        $attempt = CampaignTestMailAttempt::query()->firstOrFail();
        $this->assertSame('accepted_by_transport', $attempt->status);
        $this->assertSame(hash('sha256', 'test-recipient@example.net'), $attempt->recipient_sha256);
        $this->assertFalse(Schema::hasColumn('campaign_test_mail_attempts', 'recipient'));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'campaign.test_email_transport_accepted',
            'subject_id' => $campaign->getKey(),
        ]);

        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame('Updated synthetic subject', $message->getSubject());
        $this->assertCount(1, $message->getTo());
        $this->assertSame('test-recipient@example.net', $message->getTo()[0]->getAddress());
        $this->assertSame('sender@synthetic.testdomain', $message->getFrom()[0]->getAddress());

        config(['mail.default' => 'log']);
        Mail::purge('smtp');
        $this->post($url, $payload)
            ->assertRedirect(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertSessionHas('status', 'SMTP already accepted this test submission; no duplicate was sent.');
        $this->assertCount(1, $transport->messages());
        $this->assertSame(1, CampaignTestMailAttempt::query()->count());
    }

    public function test_test_attempt_is_reserved_before_the_smtp_connection_starts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        $this->configureRealSmtpTransport();

        $recipient = 'authorized-test@example.net';
        $idempotencyKey = (string) Str::uuid();
        $idempotencyHash = hash('sha256', $idempotencyKey);
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once()->andReturnUsing(function () use ($idempotencyHash): void {
            $attempt = CampaignTestMailAttempt::query()
                ->where('idempotency_key_hash', $idempotencyHash)
                ->firstOrFail();

            $this->assertSame('preparing', $attempt->status);
            $this->assertDatabaseHas('audit_events', [
                'action' => 'campaign.test_email_started',
                'subject_id' => $attempt->campaign_id,
            ]);
        });
        $transport->shouldReceive('send')->once()->andReturn(null);
        $transport->shouldReceive('stop')->once();
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn($transport);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);

        $this->actingAs($owner)->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => $recipient,
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => $idempotencyKey,
            'confirm_authorized_recipient' => '1',
        ])->assertRedirect(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertSessionHas('status', 'The SMTP transport accepted this one test email. Mailbox delivery is not confirmed.');

        $this->assertDatabaseHas('campaign_test_mail_attempts', [
            'idempotency_key_hash' => $idempotencyHash,
            'recipient_sha256' => hash('sha256', $recipient),
            'status' => 'accepted_by_transport',
        ]);
    }

    public function test_campaign_register_exposes_a_transactional_test_draft_form_without_marketing_fields(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('New test message')
            ->assertSee(route('clean.console.campaigns.test-draft.create'));

        $this->get(route('clean.console.campaigns.test-draft.create'))
            ->assertOk()
            ->assertSee('name="kind" value="transactional"', false)
            ->assertSee('name="from_email"', false)
            ->assertSee('name="text_body"', false)
            ->assertSee('name="html_body"', false)
            ->assertSee(route('console.campaigns.store', $workspace->getKey()))
            ->assertDontSee('send_setup[sponsor_id]')
            ->assertDontSee('send_setup[offer_id]')
            ->assertDontSee('send_setup[isp_id]')
            ->assertSee('Saving creates a draft only.');

        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_campaign_page_shows_the_effective_symfony_smtp_destination_without_credentials(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.host' => 'mail-target.test',
            'mail.mailers.smtp.port' => 2588,
            'mail.mailers.smtp.username' => 'private-smtp-user',
            'mail.mailers.smtp.password' => 'private-smtp-password',
        ]);
        Mail::purge('smtp');

        $this->actingAs($owner)
            ->get(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertOk()
            ->assertSee('Effective Symfony SMTP destination')
            ->assertSee('mail-target.test:2588')
            ->assertSee('STARTTLS when offered by the server')
            ->assertDontSee('private-smtp-user')
            ->assertDontSee('private-smtp-password');

        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_test_draft_page_shows_verified_sender_domains_and_the_exact_pending_dns_proof(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->verifiedSenderDomain($workspace, $owner, 'ready.testdomain');
        $workspace->sendingDomains()->create([
            'name' => 'pending.testdomain',
            'state' => 'pending',
            'verification_token' => 'alphalink-verification=synthetic-pending-proof',
            'created_by' => $owner->getKey(),
        ]);

        $this->actingAs($owner)
            ->get(route('clean.console.campaigns.test-draft.create'))
            ->assertOk()
            ->assertSee('Transactional sender-domain readiness')
            ->assertSee('ready.testdomain')
            ->assertSee('sender@ready.testdomain')
            ->assertSee('pending.testdomain')
            ->assertSee('_alphalink-verify.pending.testdomain')
            ->assertSee('alphalink-verification=synthetic-pending-proof')
            ->assertSee('DMS Campaign Test uses the selected PMTA server');

        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_campaign_composer_keeps_the_dms_pmta_form_separate_from_the_symfony_test_message(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.campaigns.create', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Email Test addresses:')
            ->assertDontSee('Symfony one-recipient test message')
            ->assertDontSee('name="from_email"', false)
            ->assertDontSee('name="text_body"', false)
            ->assertDontSee('name="html_body"', false);

        $this->get(route('clean.console.campaigns.test-draft.create'))
            ->assertOk()
            ->assertSee('name="kind" value="transactional"', false)
            ->assertSee('name="from_email"', false)
            ->assertSee('Symfony Mailer');

        $this->assertSame(0, Campaign::query()->count());
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_marketing_campaign_cannot_use_the_transactional_symfony_test_route(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic DMS campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($owner)
            ->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
                'recipient' => 'authorized@example.net',
                'revision_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'confirm_authorized_recipient' => '1',
            ])
            ->assertStatus(409);

        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_campaign_test_mail_is_workspace_and_creator_scoped(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        $otherOwner = User::factory()->create();
        $workspace->users()->attach($otherOwner->getKey(), ['role' => 'operator']);
        $payload = [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ];

        $this->actingAs($otherOwner)
            ->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), $payload)
            ->assertNotFound();
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
    }

    public function test_stale_revision_is_rejected_before_any_smtp_attempt(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->configureSymfonyArrayTransport();
        $campaign = $this->createCampaign($workspace, $owner);
        $staleRevision = $campaign->latestRevision;
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'subject' => 'New saved version',
        ])->assertRedirect();

        $this->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $staleRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertStatus(409);

        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
        $this->assertCount(0, Mail::mailer('smtp')->getSymfonyTransport()->messages());
    }

    public function test_unreachable_smtp_is_reported_before_any_message_is_submitted(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        Mail::purge('smtp');

        $this->actingAs($owner)->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertRedirect()->assertSessionHasErrors('test_email');

        $this->assertDatabaseHas('campaign_test_mail_attempts', ['status' => 'transport_unavailable']);
        $this->assertDatabaseHas('audit_events', ['action' => 'campaign.test_email_transport_unavailable']);
    }

    public function test_reserved_example_sender_domain_is_blocked_before_any_smtp_attempt(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->configureSymfonyArrayTransport();
        $campaign = $this->createCampaign($workspace, $owner);
        $workspace->sendingDomains()->create([
            'name' => 'pending.testdomain',
            'state' => 'pending',
            'verification_token' => 'alphalink-verification='.Str::random(48),
            'created_by' => $owner->getKey(),
        ]);

        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'hello@example.test',
        ])->assertRedirect();

        $campaign->refresh()->load('latestRevision');
        $testPayload = [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ];

        $this->actingAs($owner)
            ->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), $testPayload)
            ->assertRedirect(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertSessionHasErrors('test_email');

        $this->get(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertOk()
            ->assertSee('reserved example/test sender domain')
            ->assertSee(route('clean.console.campaigns.test-draft.create'));
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
        $this->assertCount(0, Mail::mailer('smtp')->getSymfonyTransport()->messages());
    }

    public function test_sender_domain_must_be_verified_in_the_workspace_before_a_test_attempt(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->configureSymfonyArrayTransport();
        $campaign = $this->createCampaign($workspace, $owner);

        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'sender@pending.testdomain',
        ])->assertRedirect();

        $campaign->refresh()->load('latestRevision');
        $testUrl = route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]);
        $this->actingAs($owner)->post($testUrl, [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertRedirect(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertSessionHasErrors('test_email');

        $this->get(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertOk()
            ->assertSee('sender domain is not verified')
            ->assertSee(route('clean.console.campaigns.test-draft.create'));
        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
        $this->assertCount(0, Mail::mailer('smtp')->getSymfonyTransport()->messages());
    }

    public function test_verified_dms_imported_sender_domain_is_matched_case_insensitively(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $this->configureSymfonyArrayTransport();
        $campaign = $this->createCampaign($workspace, $owner);
        $workspace->sendingDomains()->create([
            'name' => 'IMPORTED-TESTDOMAIN.COM',
            'state' => 'verified',
            'verification_token' => 'alphalink-verification='.Str::random(48),
            'verified_at' => now(),
            'created_by' => $owner->getKey(),
        ]);

        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'sender@IMPORTED-TESTDOMAIN.COM',
        ])->assertRedirect();

        $campaign->refresh()->load('latestRevision');
        $this->actingAs($owner)->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertRedirect()->assertSessionHas('status', 'The SMTP transport accepted this one test email. Mailbox delivery is not confirmed.');

        $this->assertDatabaseHas('campaign_test_mail_attempts', ['status' => 'accepted_by_transport']);
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertCount(1, $transport->messages());
        $this->assertSame('sender@imported-testdomain.com', $transport->messages()[0]->getOriginalMessage()->getFrom()[0]->getAddress());
    }

    public function test_sender_domain_verified_in_another_workspace_is_not_authorized(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'owner');
        $this->verifiedSenderDomain($otherWorkspace, $otherOwner, 'other-workspace.testdomain');
        $this->configureSymfonyArrayTransport();
        $campaign = $this->createCampaign($workspace, $owner);

        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), [
            ...$this->draftPayload(),
            'expected_revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'sender@other-workspace.testdomain',
        ])->assertRedirect();

        $campaign->refresh()->load('latestRevision');
        $this->actingAs($owner)->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertRedirect(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertSessionHasErrors('test_email');

        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
        $this->assertCount(0, Mail::mailer('smtp')->getSymfonyTransport()->messages());
    }

    public function test_log_default_mailer_is_not_mistaken_for_a_ready_smtp_configuration(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 2525,
        ]);
        Mail::purge('smtp');

        $showUrl = route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]);
        $this->actingAs($owner)->get($showUrl)
            ->assertOk()
            ->assertSee('SMTP NOT CONFIGURED')
            ->assertSee('MAIL_MAILER=smtp')
            ->assertSee('the environment loaded by this AlphaLink runtime')
            ->assertDontSee('apps/api/.env');

        $this->post(route('console.campaigns.smtp-check', $workspace->getKey()))
            ->assertStatus(503);
        $this->post(route('console.campaigns.test-email', [$workspace->getKey(), $campaign->getKey()]), [
            'recipient' => 'test-recipient@example.net',
            'revision_id' => $campaign->latestRevision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ])->assertStatus(503);

        $this->assertSame(0, CampaignTestMailAttempt::query()->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'campaign.test_email_started']);
    }

    public function test_campaign_page_keeps_the_latest_no_email_smtp_check_visible_after_reload(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $this->createCampaign($workspace, $owner);
        $this->configureSymfonyArrayTransport();
        $checkedAt = now()->subMinutes(5);

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'campaign.smtp_connection_succeeded',
            'subject_type' => 'mail_transport',
            'request_id' => null,
            'properties' => [
                'transport' => 'smtp',
                'connected' => true,
                'message_sent' => false,
            ],
            'occurred_at' => $checkedAt,
        ]);

        $this->actingAs($owner)
            ->get(route('console.campaigns.show', [$workspace->getKey(), $campaign->getKey()]))
            ->assertOk()
            ->assertSee('LAST SMTP CHECK SUCCEEDED')
            ->assertSee('Latest no-email SMTP check: handshake succeeded')
            ->assertSee($checkedAt->format('Y-m-d H:i'))
            ->assertSee('a handshake does not confirm relay permission or delivery');
    }

    private function configureSymfonyArrayTransport(): void
    {
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail.mailers.smtp.host' => 'localhost',
            'mail.mailers.smtp.port' => 2525,
        ]);
        Mail::purge('smtp');
    }

    private function configureRealSmtpTransport(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => 'smtp.synthetic.test',
            'mail.mailers.smtp.port' => 25,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        Mail::purge('smtp');
    }

    private function draftPayload(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'name' => 'Synthetic first test campaign',
            'kind' => 'transactional',
            'subject' => 'Synthetic test subject',
            'from_name' => 'AlphaLink Test',
            'from_email' => 'sender@synthetic.testdomain',
            'reply_to' => 'reply@synthetic.testdomain',
            'header' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]",
            'text_body' => 'A short synthetic test message.',
            'html_body' => '<p>A short synthetic test message.</p>',
        ];
    }

    private function createCampaign(Workspace $workspace, User $owner): Campaign
    {
        $this->verifiedSenderDomain($workspace, $owner, 'synthetic.testdomain');
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $this->draftPayload())
            ->assertRedirect();

        return Campaign::query()->where('workspace_id', $workspace->getKey())->firstOrFail()->load('latestRevision');
    }

    private function workspaceFor(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Campaign workspace',
            'slug' => 'campaign-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function verifiedSenderDomain(Workspace $workspace, User $owner, string $name): void
    {
        $workspace->sendingDomains()->create([
            'name' => $name,
            'state' => 'verified',
            'verification_token' => 'alphalink-verification='.Str::random(48),
            'verified_at' => now(),
            'created_by' => $owner->getKey(),
        ]);
    }
}
