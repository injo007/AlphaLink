<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignSendRun;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignDeliveryAttempt;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRecipientSnapshotItemAddress;
use App\Models\CampaignRevision;
use App\Models\CampaignSendBatch;
use App\Models\CampaignSendRecipientClaim;
use App\Models\CampaignSendRun;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\OutboxMessage;
use App\Models\SendingDomain;
use App\Models\SuppressionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDropSuppression;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\AlphaLinkCampaignSendCatalog;
use App\Services\Campaigns\CampaignDeliveryAttemptLifecycle;
use App\Services\Campaigns\CampaignSendMessageBuilder;
use App\Services\Campaigns\ClaimCampaignSendBatch;
use App\Services\Campaigns\FinalizeCampaignSendRun;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Campaigns\PrepareCampaignSendRun;
use App\Services\Campaigns\ReclaimExpiredCampaignSendBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

class CampaignSendExecutionLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['alphalink.contact_lookup_key' => str_repeat('synthetic-contact-key-', 2)]);
    }

    public function test_run_batch_claim_and_delivery_attempt_are_scoped_to_the_immutable_snapshot_without_storing_addresses(): void
    {
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'],
            $fixture['revision'],
            $fixture['snapshot'],
            $fixture['owner'],
            (string) Str::uuid(),
        );
        $batch = CampaignSendBatch::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'batch_number' => 1,
            'claim_request_key' => (string) Str::uuid(),
            'claim_request_fingerprint' => hash('sha256', 'batch fixture'),
            'first_position' => 1,
            'last_position' => 1,
            'state' => 'ready',
        ]);
        $claim = CampaignSendRecipientClaim::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'batch_id' => $batch->getKey(),
            'position' => 1,
            'state' => 'claimed',
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinutes(5),
        ]);
        $attempt = CampaignDeliveryAttempt::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'batch_id' => $batch->getKey(),
            'attempt_number' => 1,
            'state' => 'started',
            'idempotency_key' => $run->getKey().':'.$fixture['item']->getKey().':1',
            'worker_id' => 'synthetic-fixture-worker',
            'transport_code' => 'synthetic',
        ]);

        $this->assertSame($fixture['campaign']->getKey(), $run->campaign->getKey());
        $this->assertSame($fixture['snapshot']->getKey(), $run->recipientSnapshot->getKey());
        $this->assertSame($batch->getKey(), $claim->batch->getKey());
        $this->assertSame($fixture['item']->getKey(), $attempt->snapshotItem->getKey());
        $this->assertSame(1, $run->recipientClaims()->count());
        $this->assertSame(1, $run->deliveryAttempts()->count());
        $this->assertSame('planned', $run->state);
        $this->assertSame(1, $run->recipient_count);
        $this->assertSame(1, $run->start_position);
        $this->assertDatabaseHas('outbox_messages', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'event_type' => 'campaign.send.plan_created',
        ]);
        $this->assertFalse(Schema::hasColumn('campaign_send_recipient_claims', 'email'));
        $this->assertFalse(Schema::hasColumn('campaign_delivery_attempts', 'recipient'));
        $this->assertArrayNotHasKey('execution_options', $run->toArray());
    }

    public function test_database_prevents_duplicate_recipient_claims_for_one_run(): void
    {
        $fixture = $this->sendFixture();
        $run = CampaignSendRun::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $fixture['revision']->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'send_type' => 'delay',
            'state' => 'planned',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'claim uniqueness'),
            'recipient_count' => 1,
        ]);
        $batch = CampaignSendBatch::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'batch_number' => 1,
            'claim_request_key' => (string) Str::uuid(),
            'claim_request_fingerprint' => hash('sha256', 'duplicate-claim fixture'),
            'first_position' => 1,
            'last_position' => 1,
            'state' => 'ready',
        ]);
        $claim = [
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
            'snapshot_item_id' => $fixture['item']->getKey(),
            'batch_id' => $batch->getKey(),
            'position' => 1,
            'state' => 'claimed',
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinutes(5),
        ];
        CampaignSendRecipientClaim::query()->create($claim);

        try {
            DB::transaction(fn () => CampaignSendRecipientClaim::query()->create($claim));
            $this->fail('The same recipient was claimed twice for one run.');
        } catch (QueryException) {
            $this->assertSame(1, CampaignSendRecipientClaim::query()->count());
        }
    }

    public function test_send_plan_is_idempotent_and_rejects_reusing_its_key_for_different_inputs(): void
    {
        $fixture = $this->sendFixture();
        $idempotencyKey = (string) Str::uuid();
        $planner = app(PrepareCampaignSendRun::class);

        $first = $planner->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], $idempotencyKey,
        );
        $replay = $planner->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], $idempotencyKey,
        );

        $this->assertSame($first->getKey(), $replay->getKey());
        $this->assertSame(1, CampaignSendRun::query()->count());
        $this->assertSame(1, OutboxMessage::query()->where('event_type', 'campaign.send.plan_created')->count());
        $this->assertSame(0, CampaignSendBatch::query()->count());
        $this->assertSame(0, CampaignDeliveryAttempt::query()->count());

        $otherRevision = CampaignRevision::query()->create([
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_number' => 2,
            'payload' => ['subject' => 'Different synthetic revision', 'send_setup' => ['send_type' => 'delay']],
            'content_sha256' => hash('sha256', 'different synthetic revision'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'different revision'),
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $otherSnapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'campaign_id' => $fixture['campaign']->getKey(),
            'revision_id' => $otherRevision->getKey(),
            'offer_id' => $fixture['offer']->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic'),
            'selection' => ['audience_segments' => ['synthetic-list_fresh']],
            'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', 'different synthetic recipient'),
            'created_at' => now(),
        ]);
        $this->expectException(ValidationException::class);
        $planner->create(
            $fixture['campaign'], $otherRevision, $otherSnapshot, $fixture['owner'], $idempotencyKey,
        );
    }

    public function test_campaign_send_action_accepts_a_snapshot_without_consent_records_and_dispatches_using_nested_reference_limits(): void
    {
        $serverId = (string) Str::uuid();
        $routeId = '81';
        $fixture = $this->sendFixture(recipientCount: 4, sendSetup: [
            'route_option' => 'ip',
            'ip_type' => 'ipv4',
            'server_ids' => [$serverId],
            'ip_ids' => [$routeId],
            'send_type' => 'delay',
            'parameters' => ['total_send' => 2, 'fraction_ip' => 1, 'sleep_email' => 0, 'sleep_ip' => 0],
        ]);
        $fixture['workspace']->users()->attach($fixture['owner']->getKey(), ['role' => 'owner']);
        $this->assertSame(0, DB::table('consent_records')->count());

        $catalog = Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('validateRevisionRouting')->twice()->andReturn([
            'server_ids' => [$serverId], 'ip_ids' => [$routeId],
        ]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);
        $builder = Mockery::mock(CampaignSendMessageBuilder::class);
        $builder->shouldReceive('assertReady')->once()->andReturn([]);
        $this->app->instance(CampaignSendMessageBuilder::class, $builder);
        Queue::fake();

        $this->actingAs($fixture['owner'])
            ->post(route('console.campaigns.send', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]), [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('console.mailer.campaigns'))
            ->assertSessionHas('status', 'Campaign send queued. Progress and SMTP outcomes will update in the campaign register.');

        $run = CampaignSendRun::query()->sole();
        $this->assertSame(2, $run->recipient_count);
        $this->assertSame(1, $run->start_position);
        $this->assertSame(1, $run->next_position);
        $this->assertSame(2, $run->execution_options['end_position']);
        Queue::assertPushedOn('campaign-sends', ProcessCampaignSendRun::class, fn (ProcessCampaignSendRun $job): bool => $job->sendRunId === $run->getKey());
        $this->actingAs($fixture['owner'])
            ->post(route('console.campaigns.send', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]), [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHas('status', 'This campaign send is already queued or running. Its latest progress is shown below.');
        Queue::assertPushed(ProcessCampaignSendRun::class, 1);
    }

    public function test_campaign_send_can_be_stopped_before_the_worker_starts(): void
    {
        $fixture = $this->sendFixture(recipientCount: 2);
        $fixture['workspace']->users()->attach($fixture['owner']->getKey(), ['role' => 'owner']);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );

        $stopUrl = route('console.campaigns.send-stop', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey(), $run->getKey()]);
        $this->actingAs($fixture['owner'])->patch(route('console.campaigns.status', [
            $fixture['workspace']->getKey(), $fixture['campaign']->getKey(),
        ]), ['is_active' => false])->assertStatus(409);
        $this->actingAs($fixture['owner'])->patch(route('console.campaigns.bulk-disable', $fixture['workspace']->getKey()), [
            'campaign_ids' => [$fixture['campaign']->getKey()],
        ])->assertStatus(409);
        $this->actingAs($fixture['owner'])->post($stopUrl)
            ->assertRedirect(route('console.mailer.campaigns'))
            ->assertSessionHas('status', 'Campaign send stopped. A message already in progress may finish; no new recipients will be started.');
        $this->assertSame('stopped', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertSame(0, $run->deliveryAttempts()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'campaign.send_stopped')->count());

        $this->actingAs($fixture['owner'])->post($stopUrl)->assertSessionHas('status', 'Campaign send was already stopped.');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'campaign.send_stopped')->count());
        $this->actingAs($fixture['owner'])->get(route('console.campaigns.send-progress', [
            $fixture['workspace']->getKey(), $fixture['campaign']->getKey(), $run->getKey(),
        ]))->assertOk()->assertJsonPath('state', 'stopped');
    }

    public function test_stopped_send_records_in_flight_outcome_and_blocks_another_recipient(): void
    {
        $fixture = $this->sendFixture(recipientCount: 2);
        $fixture['workspace']->users()->attach($fixture['owner']->getKey(), ['role' => 'owner']);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 2);
        $this->assertNotNull($batch);
        $leaseToken = (string) $batch->lease_token;
        $lifecycle = app(CampaignDeliveryAttemptLifecycle::class);
        $attempt = $lifecycle->start(
            $run->getKey(), $batch->getKey(), $fixture['items'][0]->getKey(), 'synthetic-worker', $leaseToken, (string) Str::uuid(),
        );

        $this->actingAs($fixture['owner'])->post(route('console.campaigns.send-stop', [
            $fixture['workspace']->getKey(), $fixture['campaign']->getKey(), $run->getKey(),
        ]))->assertRedirect();
        $lifecycle->recordOutcome($run->getKey(), $attempt->getKey(), 'synthetic-worker', $leaseToken, 'accepted');

        $this->assertSame('stopped', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->accepted_count);
        $this->assertSame('accepted', $attempt->fresh()->state);
        $this->expectException(ValidationException::class);
        $lifecycle->start(
            $run->getKey(), $batch->getKey(), $fixture['items'][1]->getKey(), 'synthetic-worker', $leaseToken, (string) Str::uuid(),
        );
    }

    public function test_recovery_requeues_stale_plan_once_and_respects_saved_recipient_delay(): void
    {
        Queue::fake();
        $fixture = $this->sendFixture(recipientCount: 2, sendSetup: [
            'send_type' => 'delay',
            'parameters' => ['total_send' => 2, 'fraction_ip' => 1, 'sleep_email' => 1000, 'sleep_ip' => 0],
        ]);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $run->created_at = now()->subMinutes(3);
        $run->save();

        $this->artisan('campaigns:recover-sends')->assertSuccessful();
        Queue::assertPushed(ProcessCampaignSendRun::class, 1);
        $this->artisan('campaigns:recover-sends')->assertSuccessful();
        Queue::assertPushed(ProcessCampaignSendRun::class, 1);

        $run->refresh();
        $run->state = 'running';
        $run->execution_options = [];
        $run->save();
        $batch = CampaignSendBatch::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'send_run_id' => $run->getKey(),
            'batch_number' => 1,
            'claim_request_key' => (string) Str::uuid(),
            'claim_request_fingerprint' => hash('sha256', 'recovery batch'),
            'first_position' => 1,
            'last_position' => 1,
            'state' => 'completed',
            'completed_at' => now(),
        ]);
        $this->artisan('campaigns:recover-sends')->assertSuccessful();
        Queue::assertPushed(ProcessCampaignSendRun::class, 1);

        $batch->completed_at = now()->subMinutes(20);
        $batch->save();
        $this->artisan('campaigns:recover-sends')->assertSuccessful();
        Queue::assertPushed(ProcessCampaignSendRun::class, 2);
    }

    public function test_background_sender_submits_snapshot_recipients_to_pmta_and_records_acceptance(): void
    {
        config(['cache.default' => 'array']);
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $server = new ManagedServer(['ip_address' => '203.0.113.240']);
        $context = ['port' => 2525, 'setup' => ['parameters' => ['sleep_email' => 0, 'sleep_ip' => 0, 'fraction_ip' => 1]]];
        $builder = Mockery::mock(CampaignSendMessageBuilder::class);
        $builder->shouldReceive('assertReady')->once()->withArgs(fn (CampaignSendRun $sendRun): bool => $sendRun->is($run))->andReturn($context);
        $builder->shouldReceive('build')->once()->withArgs(fn (array $messageContext, CampaignRecipientSnapshotItem $item, string $recipient): bool => $messageContext === $context && $item->is($fixture['item']) && $recipient === $fixture['contact']->email
        )->andReturn([
            'server' => $server,
            'header' => "From: sender@example.test\r\nTo: {$fixture['contact']->email}\r\nSubject: Synthetic send",
            'body' => '<p>Synthetic authorized content</p>',
            'envelope_from' => 'sender@example.test',
        ]);
        $this->app->instance(CampaignSendMessageBuilder::class, $builder);

        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once()->withArgs(fn ($message, $envelope): bool => str_contains($message->toString(), 'Subject: Synthetic send')
            && $envelope->getRecipients()[0]->getAddress() === $fixture['contact']->email
            && $envelope->getSender()->getAddress() === 'sender@example.test'
        );
        $transport->shouldReceive('stop')->once();
        $transportFactory = Mockery::mock(PmtaCampaignTestTransport::class);
        $transportFactory->shouldReceive('forServer')->once()->with($server, 2525)->andReturn($transport);

        app()->call([new ProcessCampaignSendRun((string) $run->getKey()), 'handle'], [
            'messageBuilder' => $builder,
            'transportFactory' => $transportFactory,
            'claimer' => app(ClaimCampaignSendBatch::class),
            'attemptLifecycle' => app(CampaignDeliveryAttemptLifecycle::class),
            'reclaimer' => app(ReclaimExpiredCampaignSendBatch::class),
            'finalizer' => app(FinalizeCampaignSendRun::class),
        ]);

        $this->assertSame('completed', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->accepted_count);
        $this->assertSame('accepted', $run->deliveryAttempts()->sole()->state);
    }

    public function test_production_send_message_uses_reference_decodable_tokens_for_click_and_open_urls(): void
    {
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $server = ManagedServer::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'Synthetic tracking server',
            'provider' => 'Fixture provider',
            'ip_address' => '203.0.113.241',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'install_parameters' => [
                'tracking' => true,
                'https' => true,
                'tracking_host' => 'tracking.example.test',
                'data' => [['main' => true, 'ip' => '203.0.113.241', 'is_our' => false]],
            ],
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'tracking-server'),
            'created_by' => $fixture['owner']->getKey(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'tracking.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-tracking-domain',
            'created_by' => $fixture['owner']->getKey(),
            'status' => 'used',
            'is_our' => false,
            'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $server->ip_address,
            'type' => 'ipv4',
            'dns' => $domain->name,
            'is_active' => true,
        ]);
        $route = [
            'id' => (string) $ip->getKey(),
            'server_id' => (string) $server->getKey(),
            'ip' => (string) $ip->ip,
            'managed_server_ip_id' => (string) $ip->getKey(),
            'sending_domain_id' => (string) $domain->getKey(),
            'dns' => (string) $ip->dns,
        ];
        $context = [
            'run' => $run,
            'campaign' => $fixture['campaign'],
            'revision' => $fixture['revision'],
            'snapshot' => $fixture['snapshot'],
            'offer' => $fixture['offer'],
            'workspace' => $fixture['workspace'],
            'setup' => ['isp_id' => 1, 'creative_asset_id' => 0, 'domain' => $domain->name],
            'route' => 'ip',
            'port' => 2525,
            'servers' => collect([$server]),
            'choices_by_server' => collect([(string) $server->getKey() => collect([$route])]),
            'from_assets' => [],
            'subject_assets' => [],
            'negative_path' => '',
            'placeholder_rows' => [],
            'header_template' => "MIME-Version: 1.0\nContent-Type: text/html; charset=UTF-8\nTo: [to]\nFrom: sender@example.test\nSubject: Tracked message",
            'body_template' => '<a href="[domain]/[rndUN_10]r[token]">Click</a><a href="https://[domain]/[rndUN_10]u[token]">Unsubscribe via tracker</a><img src="[domain]/[rndUN_10]o[token]" alt=""><p>Message domain: http://[domain]/landing</p><p>External link: http://outside.example.test/landing</p><img src="https://i.pinimg.com/736x/62/92/63/629263433ead2c24378e115fdb0e46e7.jpg">',
            'return_path' => '',
            'domain_fallback' => $domain->name,
        ];

        $message = app(CampaignSendMessageBuilder::class)->build($context, $fixture['item'], $fixture['contact']->email);
        preg_match_all('#http://tracking\.example\.test/[A-Z0-9]{10}o([^" ]+)#i', $message['body'], $openMatches);
        preg_match('#href="(http://tracking\.example\.test/[A-Z0-9]{10}r[^\"]+\?t=1)"#i', $message['body'], $clickMatch);
        preg_match('#href="(http://tracking\.example\.test/[A-Z0-9]{10}u[A-Za-z0-9_-]+)"#i', $message['body'], $unsubscribeMatch);

        $this->assertCount(1, $openMatches[1]);
        $this->assertArrayHasKey(1, $clickMatch);
        $this->assertArrayHasKey(1, $unsubscribeMatch);
        $clickUrl = html_entity_decode($clickMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str((string) parse_url($clickUrl, PHP_URL_QUERY), $clickQuery);
        $clickPath = explode('/', trim((string) parse_url($clickUrl, PHP_URL_PATH), '/'));
        $clickToken = substr($clickPath[0], 11);
        if (count($clickPath) > 1) {
            $clickToken .= '/'.implode('/', array_slice($clickPath, 1));
        }
        $this->assertSame(['t' => '1'], $clickQuery);
        $this->assertSame(10, strlen(substr($clickPath[0], 0, 10)));
        $this->assertStringNotContainsString('baseheavy.example.test', $clickUrl);
        $this->assertStringNotContainsString('http://203.0.113.241/', $message['body']);
        $this->assertStringContainsString('Message domain: http://tracking.example.test/landing', $message['body']);
        $this->assertStringContainsString('External link: http://outside.example.test/landing', $message['body']);
        $this->assertStringContainsString('https://i.pinimg.com/736x/62/92/63/629263433ead2c24378e115fdb0e46e7.jpg', $message['body']);
        $this->assertStringNotContainsString('https://tracking.example.test/', $message['body']);
        foreach (array_merge([$clickToken], $openMatches[1]) as $token) {
            $segments = explode('/', $token);
            $this->assertLessThanOrEqual(160, max(array_map('strlen', $segments)));
            $decoded = base64_decode(strtr(implode('', $segments), '-_', '+/'), true);
            $this->assertIsString($decoded);
            $parts = explode('-', $decoded);
            $this->assertCount(14, $parts);
            $this->assertSame(str_replace('-', '', (string) $fixture['campaign']->getKey()), $parts[0]);
            $this->assertSame(str_replace('-', '', (string) $fixture['snapshot']->getKey()), $parts[1]);
            $this->assertSame(str_replace('-', '', (string) $fixture['contact']->getKey()), $parts[2]);
            $this->assertSame('f', $parts[3]);
            $this->assertSame(hash('adler32', implode('_', array_slice($parts, 0, 3))), $parts[13]);
        }

        $server->forceFill(['install_parameters' => [
            'tracking' => true,
            'https' => true,
            'tracking_https_ready' => true,
            'tracking_host' => 'tracking.example.test',
            'data' => [['main' => true, 'ip' => '203.0.113.241', 'is_our' => false]],
        ]])->save();
        $verifiedHttpsMessage = app(CampaignSendMessageBuilder::class)->build($context, $fixture['item'], $fixture['contact']->email);
        $this->assertStringContainsString('Message domain: https://tracking.example.test/landing', $verifiedHttpsMessage['body']);
        $this->assertStringNotContainsString('http://tracking.example.test/', $verifiedHttpsMessage['body']);

        // A different, external primary domain must not override the selected
        // route's managed domain or rewrite its tracking links back to the IP.
        $server->forceFill(['install_parameters' => [
            'tracking' => true,
            'https' => true,
            'tracking_host' => '203.0.113.241',
            'data' => [['main' => true, 'ip' => '203.0.113.241', 'is_our' => false]],
        ]])->save();
        $domain->forceFill(['is_our' => true])->save();
        $ownedDomainMessage = app(CampaignSendMessageBuilder::class)->build($context, $fixture['item'], $fixture['contact']->email);
        $this->assertStringContainsString('/api/v1/campaigns/'.$fixture['campaign']->getKey().'/'.$fixture['item']->getKey().'/click?', $ownedDomainMessage['body']);
        $this->assertStringContainsString('signature=', $ownedDomainMessage['body']);
        $this->assertStringNotContainsString('http://203.0.113.241/', $ownedDomainMessage['body']);

        $selectedDomain = SendingDomain::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'name' => 'selected-campaign.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-selected-campaign-domain',
            'created_by' => $fixture['owner']->getKey(),
            'status' => 'redirect',
            'is_our' => true,
            'is_active' => true,
        ]);
        $context['setup']['domain'] = $selectedDomain->name;
        $context['domain_fallback'] = $selectedDomain->name;
        $selectedDomainMessage = app(CampaignSendMessageBuilder::class)->build($context, $fixture['item'], $fixture['contact']->email);

        $this->assertStringContainsString('Message domain: https://selected-campaign.example.test/landing', $selectedDomainMessage['body']);
        $this->assertStringContainsString('https://i.pinimg.com/736x/62/92/63/629263433ead2c24378e115fdb0e46e7.jpg', $selectedDomainMessage['body']);
        $this->assertStringContainsString('/api/v1/campaigns/'.$fixture['campaign']->getKey().'/'.$fixture['item']->getKey().'/click?', $selectedDomainMessage['body']);
        $this->assertStringNotContainsString('http://selected-campaign.example.test/', $selectedDomainMessage['body']);
        $this->assertStringNotContainsString('http://203.0.113.241/', $selectedDomainMessage['body']);
        $this->assertStringNotContainsString('http://tracking.example.test/', $selectedDomainMessage['body']);

        // Redirect domains are explicit campaign tracking hosts even when
        // their ownership flag is false and the server has a saved IP fallback.
        $selectedDomain->forceFill(['is_our' => false])->save();
        $externalRedirectMessage = app(CampaignSendMessageBuilder::class)->build($context, $fixture['item'], $fixture['contact']->email);

        $this->assertStringContainsString('Message domain: https://selected-campaign.example.test/landing', $externalRedirectMessage['body']);
        $this->assertStringContainsString('/api/v1/campaigns/'.$fixture['campaign']->getKey().'/'.$fixture['item']->getKey().'/click?', $externalRedirectMessage['body']);
        $this->assertStringNotContainsString('http://selected-campaign.example.test/', $externalRedirectMessage['body']);
        $this->assertStringNotContainsString('http://203.0.113.241/', $externalRedirectMessage['body']);
    }

    public function test_campaign_send_route_allows_normal_retry_room_without_changing_send_idempotency(): void
    {
        $middleware = app('router')->getRoutes()->getByName('console.campaigns.send')->gatherMiddleware();

        $this->assertContains('throttle:campaign-send', $middleware);
    }

    public function test_planner_rejects_a_snapshot_from_another_campaign_or_workspace(): void
    {
        $fixture = $this->sendFixture();
        $otherFixture = $this->sendFixture();

        $this->expectException(ValidationException::class);
        app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'],
            $fixture['revision'],
            $otherFixture['snapshot'],
            $fixture['owner'],
            (string) Str::uuid(),
        );
    }

    public function test_planner_rejects_legacy_snapshot_without_a_complete_captured_address_set(): void
    {
        $fixture = $this->sendFixture(captureAddresses: false);

        try {
            app(PrepareCampaignSendRun::class)->create(
                $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
            );
            $this->fail('A snapshot without its point-in-time email address must not be sendable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_snapshot', $exception->errors());
        }

        $this->assertSame(0, CampaignSendRun::query()->count());
    }

    public function test_planner_rejects_a_captured_address_that_does_not_match_its_snapshot_identity(): void
    {
        $fixture = $this->sendFixture(addressHashMatches: false);

        try {
            app(PrepareCampaignSendRun::class)->create(
                $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
            );
            $this->fail('A recipient address that does not match the immutable snapshot identity must not be sendable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_snapshot', $exception->errors());
        }

        $this->assertSame(0, CampaignSendRun::query()->count());
    }

    public function test_legacy_snapshot_address_backfill_recovers_only_the_original_hmac_verified_email(): void
    {
        $fixture = $this->sendFixture(captureAddresses: false);

        $this->artisan('app:backfill-campaign-snapshot-addresses', ['--batch' => 1])
            ->expectsOutputToContain('backfilled 1; unresolved 0')
            ->assertExitCode(0);

        $address = CampaignRecipientSnapshotItemAddress::query()->findOrFail($fixture['item']->getKey());
        $this->assertSame($fixture['contact']->email, $address->email);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $this->assertSame('planned', $run->state);
    }

    public function test_legacy_snapshot_address_backfill_skips_a_contact_email_changed_after_snapshot_creation(): void
    {
        $fixture = $this->sendFixture(captureAddresses: false);
        $changedEmail = 'changed-after-snapshot@example.test';
        $fixture['contact']->forceFill([
            'email' => $changedEmail,
            'email_lookup_hash' => hash_hmac('sha256', $changedEmail, (string) config('alphalink.contact_lookup_key')),
        ])->save();

        $this->artisan('app:backfill-campaign-snapshot-addresses', ['--batch' => 1])
            ->expectsOutputToContain('backfilled 0; unresolved 1')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('campaign_recipient_snapshot_item_addresses', [
            'snapshot_item_id' => $fixture['item']->getKey(),
        ]);
        try {
            app(PrepareCampaignSendRun::class)->create(
                $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
            );
            $this->fail('A changed current contact address must not replace the address captured by an older snapshot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_snapshot', $exception->errors());
        }
    }

    public function test_address_migration_refuses_to_drop_persisted_point_in_time_recipients(): void
    {
        $fixture = $this->sendFixture();
        $migration = require database_path('migrations/2026_10_28_150000_create_campaign_recipient_snapshot_item_addresses_table.php');

        try {
            $migration->down();
            $this->fail('The address migration must preserve point-in-time recipients on rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('discard immutable recipient data', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('campaign_recipient_snapshot_item_addresses'));
        $this->assertDatabaseHas('campaign_recipient_snapshot_item_addresses', [
            'snapshot_item_id' => $fixture['item']->getKey(),
        ]);
    }

    public function test_migration_refuses_to_drop_send_history_after_a_plan_exists(): void
    {
        $fixture = $this->sendFixture();
        app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'],
            $fixture['revision'],
            $fixture['snapshot'],
            $fixture['owner'],
            (string) Str::uuid(),
        );
        $migration = require database_path('migrations/2026_10_28_120000_create_campaign_send_execution_ledger.php');

        try {
            $migration->down();
            $this->fail('The migration must not delete persisted campaign send history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back without losing operational evidence', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('campaign_send_runs'));
        $this->assertSame(1, CampaignSendRun::query()->count());
    }

    public function test_batch_claim_rechecks_suppression_and_replays_the_same_request_without_double_claims(): void
    {
        $fixture = $this->sendFixture(4);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        SuppressionEvent::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'contact_id' => $fixture['contacts'][1]->getKey(),
            'event_type' => 'added',
            'reason' => 'unsubscribe',
            'scope_type' => 'workspace',
            'scope_key' => null,
            'source' => 'synthetic_test',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic unsubscribe'),
            'effective_at' => now(),
        ]);
        SuppressionEvent::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'contact_id' => $fixture['contacts'][2]->getKey(),
            'event_type' => 'added',
            'reason' => 'affiliate',
            'scope_type' => 'offer',
            'scope_key' => (string) $fixture['offer']->getKey(),
            'source' => 'synthetic_test',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic offer suppression'),
            'effective_at' => now(),
        ]);
        $fixture['contacts'][3]->forceFill(['status' => 'inactive'])->save();

        $requestKey = (string) Str::uuid();
        $claimer = app(ClaimCampaignSendBatch::class);
        $batch = $claimer->claim($run->getKey(), 'synthetic-worker', $requestKey, batchSize: 4);
        $replay = $claimer->claim($run->getKey(), 'synthetic-worker', $requestKey, batchSize: 4);

        $this->assertNotNull($batch);
        $this->assertSame($batch->getKey(), $replay?->getKey());
        $this->assertSame('leased', $batch->state);
        $this->assertSame(3, $batch->suppressed_count);
        $this->assertSame(4, $batch->recipientClaims()->count());
        $this->assertSame('claimed', $batch->recipientClaims()->where('snapshot_item_id', $fixture['items'][0]->getKey())->value('state'));
        foreach ([1, 2, 3] as $index) {
            $this->assertSame('suppressed', $batch->recipientClaims()->where('snapshot_item_id', $fixture['items'][$index]->getKey())->value('state'));
        }
        $this->assertSame(5, $run->fresh()->next_position);
        $this->assertSame(3, $run->fresh()->suppressed_count);
        $this->assertSame(1, CampaignSendBatch::query()->count());
        $this->assertSame(4, CampaignSendRecipientClaim::query()->count());

        $this->expectException(ValidationException::class);
        $claimer->claim($run->getKey(), 'different-worker', $requestKey, batchSize: 4);
    }

    public function test_offer_list_suppression_blocks_only_when_every_selected_source_list_is_suppressed(): void
    {
        $fixture = $this->sendFixture(2);
        $firstList = $this->audienceList($fixture, 'first');
        $secondList = $this->audienceList($fixture, 'second');

        $this->addSnapshotSource($fixture, 0, $firstList);
        $this->addSnapshotSource($fixture, 0, $secondList);
        $this->addSnapshotSource($fixture, 1, $firstList);

        $suppressionRun = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'sponsor_id' => $fixture['offer']->sponsor_id,
            'offer_id' => $fixture['offer']->getKey(),
            'isps' => [],
            'lists' => [$firstList->getKey(), $secondList->getKey()],
            'status' => 'complete',
        ]);
        DB::table('workspace_offer_list_suppressions')->insert([
            [
                'workspace_id' => $fixture['workspace']->getKey(),
                'offer_id' => $fixture['offer']->getKey(),
                'audience_list_id' => $firstList->getKey(),
                'contact_id' => $fixture['contacts'][0]->getKey(),
                'suppression_run_id' => $suppressionRun->getKey(),
                'suppression_sha256' => hash('sha256', 'first-list-only'),
                'created_at' => now(),
            ],
            [
                'workspace_id' => $fixture['workspace']->getKey(),
                'offer_id' => $fixture['offer']->getKey(),
                'audience_list_id' => $firstList->getKey(),
                'contact_id' => $fixture['contacts'][1]->getKey(),
                'suppression_run_id' => $suppressionRun->getKey(),
                'suppression_sha256' => hash('sha256', 'first-list-only'),
                'created_at' => now(),
            ],
        ]);

        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)
            ->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 2);

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->suppressed_count);
        $this->assertSame('claimed', $batch->recipientClaims()->where('snapshot_item_id', $fixture['items'][0]->getKey())->value('state'));
        $this->assertSame('suppressed', $batch->recipientClaims()->where('snapshot_item_id', $fixture['items'][1]->getKey())->value('state'));
    }

    public function test_batch_claim_starts_at_the_saved_campaign_start_from_position(): void
    {
        $fixture = $this->sendFixture(4, startFrom: 2);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );

        $this->assertSame(2, $run->recipient_count);
        $this->assertSame(3, $run->start_position);

        $claimer = app(ClaimCampaignSendBatch::class);
        $firstBatch = $claimer->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1);
        $secondBatch = $claimer->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1);

        $this->assertNotNull($firstBatch);
        $this->assertNotNull($secondBatch);
        $this->assertSame(3, $firstBatch->first_position);
        $this->assertSame(3, $firstBatch->last_position);
        $this->assertSame($fixture['items'][2]->getKey(), $firstBatch->recipientClaims()->value('snapshot_item_id'));
        $this->assertSame(4, $secondBatch->first_position);
        $this->assertSame($fixture['items'][3]->getKey(), $secondBatch->recipientClaims()->value('snapshot_item_id'));
        $this->assertSame(5, $run->fresh()->next_position);
    }

    public function test_all_suppressed_claims_complete_a_run_without_creating_a_lease(): void
    {
        $fixture = $this->sendFixture();
        $fixture['contact']->forceFill(['status' => 'inactive'])->save();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );

        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1,
        );

        $this->assertNotNull($batch);
        $this->assertSame('completed', $batch->state);
        $this->assertSame(1, $batch->suppressed_count);
        $this->assertNull($batch->lease_token);
        $this->assertSame('suppressed', $batch->recipientClaims()->value('state'));
        $this->assertSame('completed', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertSame(1, $run->fresh()->suppressed_count);
    }

    public function test_accepted_delivery_outcome_is_idempotent_and_keeps_transport_details_hashed(): void
    {
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1,
        );
        $this->assertNotNull($batch);
        $leaseToken = (string) $batch->lease_token;
        $itemId = (string) $fixture['item']->getKey();
        $attemptKey = (string) Str::uuid();
        $lifecycle = app(CampaignDeliveryAttemptLifecycle::class);

        $attempt = $lifecycle->start(
            $run->getKey(), $batch->getKey(), $itemId, 'synthetic-worker', $leaseToken, $attemptKey,
        );

        $this->assertSame('synthetic-worker', $attempt->worker_id);
        $this->assertSame('sending', $batch->recipientClaims()->value('state'));
        $this->assertSame(hash('sha256', $leaseToken), $attempt->lease_token_sha256);

        try {
            $lifecycle->start(
                $run->getKey(), $batch->getKey(), $itemId, 'synthetic-worker', $leaseToken, $attemptKey,
            );
            $this->fail('Replaying a started attempt must not authorize a second transport submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('send_attempt', $exception->errors());
        }

        try {
            $lifecycle->start(
                $run->getKey(), $batch->getKey(), $itemId, 'different-worker', $leaseToken, $attemptKey,
            );
            $this->fail('An attempt idempotency key must remain bound to its original worker.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        try {
            $lifecycle->recordOutcome(
                $run->getKey(), $attempt->getKey(), 'different-worker', $leaseToken, 'accepted',
            );
            $this->fail('A different worker must not record the outcome for this attempt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('worker_id', $exception->errors());
        }

        $outcome = [
            'transportCode' => 'smtp_250_data',
            'outcomeReference' => 'synthetic-queue-reference',
            'response' => '250 accepted for synthetic recipient',
        ];
        $finished = $lifecycle->recordOutcome(
            $run->getKey(), $attempt->getKey(), 'synthetic-worker', $leaseToken, 'accepted', ...$outcome,
        );
        $finishedReplay = $lifecycle->recordOutcome(
            $run->getKey(), $attempt->getKey(), 'synthetic-worker', $leaseToken, 'accepted', ...$outcome,
        );

        $this->assertSame($finished->getKey(), $finishedReplay->getKey());
        $this->assertSame('accepted', $finished->state);
        $this->assertSame(hash('sha256', $outcome['outcomeReference']), $finished->outcome_reference_sha256);
        $this->assertSame(hash('sha256', $outcome['response']), $finished->response_sha256);
        $this->assertArrayNotHasKey('worker_id', $finished->toArray());
        $this->assertArrayNotHasKey('response', $finished->toArray());
        $this->assertSame('accepted', $batch->recipientClaims()->value('state'));
        $this->assertSame('completed', $batch->fresh()->state);
        $this->assertNull($batch->fresh()->lease_token);
        $this->assertSame('completed', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->accepted_count);
        $this->assertSame(1, CampaignDeliveryAttempt::query()->count());
        $terminalReplay = $lifecycle->start(
            $run->getKey(), $batch->getKey(), $itemId, 'synthetic-worker', $leaseToken, $attemptKey,
        );
        $this->assertSame($finished->getKey(), $terminalReplay->getKey());
        $this->assertSame('accepted', $terminalReplay->state);

        try {
            $lifecycle->recordOutcome($run->getKey(), $attempt->getKey(), 'synthetic-worker', $leaseToken, 'rejected');
            $this->fail('A recorded acceptance must not be overwritten by a conflicting rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }
    }

    public function test_rejected_and_uncertain_outcomes_are_terminal_and_uncertain_run_requires_review(): void
    {
        $fixture = $this->sendFixture(3);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 3,
        );
        $this->assertNotNull($batch);
        $leaseToken = (string) $batch->lease_token;
        $lifecycle = app(CampaignDeliveryAttemptLifecycle::class);
        $outcomes = ['accepted', 'rejected', 'uncertain'];

        foreach ($batch->recipientClaims()->orderBy('position')->get() as $index => $claim) {
            $attempt = $lifecycle->start(
                $run->getKey(), $batch->getKey(), $claim->snapshot_item_id,
                'synthetic-worker', $leaseToken, (string) Str::uuid(),
            );
            $lifecycle->recordOutcome(
                $run->getKey(), $attempt->getKey(), 'synthetic-worker', $leaseToken,
                $outcomes[$index], errorCode: $outcomes[$index] === 'rejected' ? 'smtp_rejected' : null,
            );
        }

        $this->assertSame('needs_review', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->accepted_count);
        $this->assertSame(1, $run->fresh()->rejected_count);
        $this->assertSame(1, $run->fresh()->uncertain_count);
        $this->assertSame(1, $batch->fresh()->accepted_count);
        $this->assertSame(1, $batch->fresh()->rejected_count);
        $this->assertSame(1, $batch->fresh()->uncertain_count);
        $this->assertSame('uncertain', $batch->fresh()->state);
        $this->assertSame(['accepted', 'rejected', 'uncertain'], $batch->recipientClaims()->orderBy('position')->pluck('state')->all());

        try {
            $lifecycle->start(
                $run->getKey(), $batch->getKey(), (string) $fixture['items'][2]->getKey(),
                'synthetic-worker', $leaseToken, (string) Str::uuid(),
            );
            $this->fail('An uncertain outcome must never be automatically retried.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('send_run', $exception->errors());
        }
    }

    public function test_expired_batch_reassigns_only_unstarted_claims_and_rejects_the_old_lease(): void
    {
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(), 'original-worker', (string) Str::uuid(), batchSize: 1,
        );
        $this->assertNotNull($batch);
        $oldLeaseToken = (string) $batch->lease_token;
        $reclaimer = app(ReclaimExpiredCampaignSendBatch::class);

        $this->assertNull($reclaimer->reclaim($batch->getKey(), 'replacement-worker'));
        $batch->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $reclaimed = $reclaimer->reclaim($batch->getKey(), 'replacement-worker');

        $this->assertNotNull($reclaimed);
        $this->assertSame('leased', $reclaimed->state);
        $this->assertSame('replacement-worker', $reclaimed->lease_owner);
        $this->assertNotSame($oldLeaseToken, $reclaimed->lease_token);
        $claim = $reclaimed->recipientClaims()->firstOrFail();
        $this->assertSame('claimed', $claim->state);
        $this->assertSame($reclaimed->lease_token, $claim->lease_token);
        $this->assertSame(2, $claim->claim_count);
        $this->assertSame(2, $reclaimed->claim_count);

        try {
            app(CampaignDeliveryAttemptLifecycle::class)->start(
                $run->getKey(), $reclaimed->getKey(), $claim->snapshot_item_id,
                'original-worker', $oldLeaseToken, (string) Str::uuid(),
            );
            $this->fail('The expired worker lease must not authorize a new delivery attempt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lease', $exception->errors());
        }

        $attempt = app(CampaignDeliveryAttemptLifecycle::class)->start(
            $run->getKey(), $reclaimed->getKey(), $claim->snapshot_item_id,
            'replacement-worker', (string) $reclaimed->lease_token, (string) Str::uuid(),
        );
        $this->assertSame('started', $attempt->state);
        $this->assertSame('running', $run->fresh()->state);
    }

    public function test_expired_started_attempt_becomes_uncertain_and_only_unstarted_recipients_are_reclaimed(): void
    {
        $fixture = $this->sendFixture(2);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(), 'original-worker', (string) Str::uuid(), batchSize: 2,
        );
        $this->assertNotNull($batch);
        $oldLeaseToken = (string) $batch->lease_token;
        $claims = $batch->recipientClaims()->orderBy('position')->get();
        $lifecycle = app(CampaignDeliveryAttemptLifecycle::class);
        $uncertainAttempt = $lifecycle->start(
            $run->getKey(), $batch->getKey(), $claims[0]->snapshot_item_id,
            'original-worker', $oldLeaseToken, (string) Str::uuid(),
        );
        $batch->forceFill(['lease_expires_at' => now()->subSecond()])->save();

        $reclaimed = app(ReclaimExpiredCampaignSendBatch::class)->reclaim(
            $batch->getKey(), 'replacement-worker',
        );

        $this->assertNotNull($reclaimed);
        $this->assertSame('leased', $reclaimed->state);
        $this->assertSame(1, $reclaimed->uncertain_count);
        $this->assertSame('uncertain', $uncertainAttempt->fresh()->state);
        $this->assertSame('lease_expired_before_outcome', $uncertainAttempt->fresh()->error_code);
        $this->assertSame('uncertain', $reclaimed->recipientClaims()->where('snapshot_item_id', $claims[0]->snapshot_item_id)->value('state'));

        $newLeaseToken = (string) $reclaimed->lease_token;
        $pendingClaim = $reclaimed->recipientClaims()->where('snapshot_item_id', $claims[1]->snapshot_item_id)->firstOrFail();
        $this->assertSame('claimed', $pendingClaim->state);
        $this->assertSame($newLeaseToken, $pendingClaim->lease_token);

        try {
            $lifecycle->start(
                $run->getKey(), $reclaimed->getKey(), $claims[0]->snapshot_item_id,
                'replacement-worker', $newLeaseToken, (string) Str::uuid(),
            );
            $this->fail('A recipient with an uncertain attempt must not be resent under a replacement lease.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_claim', $exception->errors());
        }

        $acceptedAttempt = $lifecycle->start(
            $run->getKey(), $reclaimed->getKey(), $claims[1]->snapshot_item_id,
            'replacement-worker', $newLeaseToken, (string) Str::uuid(),
        );
        $lifecycle->recordOutcome(
            $run->getKey(), $acceptedAttempt->getKey(), 'replacement-worker', $newLeaseToken, 'accepted',
        );

        $this->assertSame('needs_review', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->accepted_count);
        $this->assertSame(1, $run->fresh()->uncertain_count);
        $this->assertSame('uncertain', $reclaimed->fresh()->state);
        $this->assertSame(2, CampaignDeliveryAttempt::query()->count());

        try {
            $lifecycle->recordOutcome(
                $run->getKey(), $uncertainAttempt->getKey(), 'original-worker', $oldLeaseToken, 'accepted',
            );
            $this->fail('A late response must not overwrite a lease-recovery uncertainty decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }
    }

    public function test_each_new_batch_rejects_a_changed_offer_suppression_digest(): void
    {
        $fixture = $this->sendFixture(2);
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        $claimer = app(ClaimCampaignSendBatch::class);
        $firstBatch = $claimer->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1);
        $fixture['offer']->forceFill(['suppression_sha256' => hash('sha256', 'new suppression contents')])->save();

        $this->assertNotNull($firstBatch);
        try {
            $claimer->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid(), batchSize: 1);
            $this->fail('A refreshed offer suppression file must not continue an old immutable snapshot run.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('suppression', $exception->errors());
        }

        $this->assertSame(1, CampaignSendBatch::query()->count());
        $this->assertSame(1, CampaignSendRecipientClaim::query()->count());
        $this->assertSame(2, $run->fresh()->next_position);
    }

    private function audienceList(array $fixture, string $suffix): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'name' => "Synthetic {$suffix} audience",
            'slug' => "synthetic-{$suffix}-".Str::lower(Str::random(8)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', "synthetic-{$suffix}-list"),
            'allowed_users' => [],
            'allowed_sponsors' => [$fixture['offer']->sponsor_id],
        ]);
    }

    private function addSnapshotSource(array $fixture, int $itemIndex, AudienceList $list): void
    {
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $fixture['contacts'][$itemIndex]->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        DB::table('campaign_recipient_snapshot_item_sources')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $fixture['workspace']->getKey(),
            'snapshot_item_id' => $fixture['items'][$itemIndex]->getKey(),
            'source_membership_id' => $membership->getKey(),
            'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh',
            'source_key' => hash('sha256', $fixture['items'][$itemIndex]->getKey().':'.$membership->getKey()),
            'created_at' => now(),
        ]);
    }

    public function test_batch_claim_rejects_a_suppression_asset_that_no_longer_matches_its_reviewed_digest(): void
    {
        $fixture = $this->sendFixture();
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'], $fixture['revision'], $fixture['snapshot'], $fixture['owner'], (string) Str::uuid(),
        );
        Storage::disk('local')->put('synthetic.txt', "tampered@example.test\n");

        try {
            app(ClaimCampaignSendBatch::class)
                ->claim($run->getKey(), 'synthetic-worker', (string) Str::uuid());
            $this->fail('The altered suppression asset must block recipient claims.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('suppression', $exception->errors());
        }

        $this->assertSame('planned', $run->fresh()->state);
        $this->assertSame(1, $run->fresh()->next_position);
        $this->assertSame(0, CampaignSendBatch::query()->count());
        $this->assertSame(0, CampaignSendRecipientClaim::query()->count());
    }

    public function test_campaign_send_claim_does_not_require_consent_records(): void
    {
        $fixture = $this->sendFixture();
        $this->assertSame(0, DB::table('consent_records')->count());
        $run = app(PrepareCampaignSendRun::class)->create(
            $fixture['campaign'],
            $fixture['revision'],
            $fixture['snapshot'],
            $fixture['owner'],
            (string) Str::uuid(),
        );

        $batch = app(ClaimCampaignSendBatch::class)->claim(
            $run->getKey(),
            'synthetic-consent-worker',
            (string) Str::uuid(),
            batchSize: 10,
        );

        $this->assertNotNull($batch);
        $this->assertSame('leased', $batch->state);
        $this->assertSame('claimed', $batch->recipientClaims->sole()->state);
        $this->assertSame(0, $run->fresh()->suppressed_count);
        $this->assertSame('running', $run->fresh()->state);
        $this->assertSame(0, $run->deliveryAttempts()->count());
    }

    /** @return array{owner: User, workspace: Workspace, campaign: Campaign, revision: CampaignRevision, offer: WorkspaceOffer, snapshot: CampaignRecipientSnapshot, item: CampaignRecipientSnapshotItem, items: list<CampaignRecipientSnapshotItem>, contact: Contact, contacts: list<Contact>} */
    private function sendFixture(
        int $recipientCount = 1,
        int $startFrom = 0,
        bool $captureAddresses = true,
        bool $addressHashMatches = true,
        ?array $sendSetup = null,
    ): array {
        if ($recipientCount < 1 || $recipientCount > 10 || $startFrom < 0 || $startFrom >= $recipientCount) {
            throw new \InvalidArgumentException('Synthetic send fixtures require 1 to 10 recipients and a valid start position.');
        }

        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Send ledger fixture',
            'slug' => 'send-ledger-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['subject' => 'Synthetic only', 'send_setup' => $sendSetup ?? ['send_type' => 'delay']],
            'content_sha256' => hash('sha256', 'synthetic only'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'revision'),
            'created_by' => $owner->getKey(),
        ]);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture',
            'password' => 'synthetic-secret',
            'api_key' => 'synthetic-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $suppressionContent = "excluded-from-snapshot@example.test\n";
        Storage::disk('local')->put('synthetic.txt', $suppressionContent);
        $suppressionSha256 = hash('sha256', $suppressionContent);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic offer',
            'type' => 'CPA',
            'vertical' => 'general',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
            'suppression_path' => 'synthetic.txt',
            'suppression_sha256' => $suppressionSha256,
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => $suppressionSha256,
            'selection' => ['audience_segments' => ['synthetic-list_fresh']],
            'recipient_count' => $recipientCount,
            'start_from' => $startFrom,
            'recipients_sha256' => hash('sha256', 'synthetic-recipient-'.$recipientCount),
            'created_at' => now(),
        ]);
        $contacts = [];
        $items = [];
        $sourceListId = (string) Str::uuid();
        for ($position = 1; $position <= $recipientCount; $position++) {
            $email = "synthetic-recipient-{$position}@example.test";
            $contact = Contact::query()->create([
                'workspace_id' => $workspace->getKey(),
                'email' => $email,
                'email_lookup_hash' => hash_hmac('sha256', $email, (string) config('alphalink.contact_lookup_key')),
                'lookup_key_version' => 1,
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $contacts[] = $contact;
            $items[] = CampaignRecipientSnapshotItem::query()->create([
                'workspace_id' => $workspace->getKey(),
                'snapshot_id' => $snapshot->getKey(),
                'contact_id' => $contact->getKey(),
                'position' => $position,
                'email_lookup_hash' => $contact->email_lookup_hash,
                'source_list_id' => $sourceListId,
                'source_email_type' => 'fresh',
                'created_at' => now(),
            ]);
            if ($captureAddresses) {
                CampaignRecipientSnapshotItemAddress::query()->create([
                    'snapshot_item_id' => $items[array_key_last($items)]->getKey(),
                    'workspace_id' => $workspace->getKey(),
                    'email_lookup_hash' => $addressHashMatches
                        ? $contact->email_lookup_hash
                        : hash('sha256', 'mismatched address identity'),
                    'email' => $email,
                    'created_at' => now(),
                ]);
            }
        }

        return [
            'owner' => $owner,
            'workspace' => $workspace,
            'campaign' => $campaign,
            'revision' => $revision,
            'offer' => $offer,
            'snapshot' => $snapshot,
            'item' => $items[0],
            'items' => $items,
            'contact' => $contacts[0],
            'contacts' => $contacts,
        ];
    }
}
