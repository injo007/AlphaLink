<?php

namespace Tests\Feature;

use App\Jobs\PrepareAndStartDropSend;
use App\Jobs\ProcessWarmupSendRun;
use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\WarmupSendRun;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSeedList;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\AlphaLinkCampaignSendCatalog;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Campaigns\WarmupSendMessageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

class WorkspaceMailerOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_and_warmup_sidebar_items_open_workspace_pages(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)->get(route('console.drops.drafts.index', $workspace->getKey()))
            ->assertOk()->assertSee('My Drop Drafts')->assertSee('DROP DRAFTS')
            ->assertSee('No Drop drafts found')
            ->assertSee(route('clean.console.drops.drafts.create'), false)
            ->assertSee(route('clean.console.drops.monitoring'), false);
        $dropComposer = $this->actingAs($owner)->get(route('console.drops.drafts.create', $workspace->getKey()));
        $dropComposer->assertOk()->assertSee('DROP : NEW')->assertSee('Sponsors:')->assertSee('MAP BODY')
            ->assertSee('Duplicate Data:')->assertSee('Delay (MS):')->assertSee('Lists:')->assertSee('AlphaLink draft')
            ->assertSee('USABLE TAGS:')->assertSee('mailer-tags-help')->assertSee('Show tag examples')
            ->assertSee('Available IPs or MTAs')->assertSee('Selected IPs or MTAs')->assertSee('Add selected IPs');
        $dropComposerHtml = $dropComposer->getContent();
        $this->assertSame(1, substr_count($dropComposerHtml, ' id="mailer-header"'));
        $this->assertSame(1, substr_count($dropComposerHtml, ' id="mailer-body"'));
        $this->assertStringContainsString('data-name-prefix="send_setup[placeholder_inputs]"', $dropComposerHtml);
        $this->assertStringContainsString('data-placeholder-combination', $dropComposerHtml);
        $this->assertStringContainsString('data-placeholder-all-combination', $dropComposerHtml);
        $this->assertStringContainsString('campaign-placeholder-editor.js?v=', $dropComposerHtml);
        $this->assertStringContainsString('id="send-ips" name="send_setup[ip_ids][]"', $dropComposerHtml);
        $this->assertStringContainsString('id="available-send-ips"', $dropComposerHtml);
        $this->assertStringContainsString('id="selected-send-ips"', $dropComposerHtml);
        $this->assertStringContainsString('createMailerEditor(\'mailer-header-editor\', \'mailer-header\', \'text\', 30)', $dropComposerHtml);
        $this->assertStringNotContainsString('for="mailer-draft-name"', $dropComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-name" name="name" value="New Drop"', $dropComposerHtml);
        $this->assertStringContainsString('id="mailer-page-actions" role="menu"', $dropComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-context-menu"', $dropComposerHtml);
        $this->assertStringContainsString('mailer-draft-navigation.js?v=', $dropComposerHtml);
        $this->assertStringContainsString('href="'.route('clean.console.pmta-admin.index').'"', $dropComposerHtml);
        $this->assertStringContainsString('data-pmta-panel-link', $dropComposerHtml);
        $this->assertStringContainsString('TEST — SAVE DRAFT FIRST', $dropComposerHtml);
        $this->assertStringContainsString('Back to Drop Drafts', $dropComposerHtml);
        $this->assertStringContainsString('aria-label="Open page actions" aria-expanded="false"', $dropComposerHtml);
        $warmupIndex = $this->actingAs($owner)->get(route('console.warmups.index', $workspace->getKey()));
        $warmupIndex->assertOk()->assertSee('My Warmups')->assertSee('TOTAL SEND')->assertSee('New Warmup')
            ->assertSee('warmup-register-page')->assertSee('warmup-register-overview')
            ->assertSee('Choose warmup date range')->assertDontSee('Date from')
            ->assertSee(route('clean.console.warmups.seeds.index'), false)
            ->assertSee(route('clean.console.warmups.statistics'), false);
        $warmupComposer = $this->actingAs($owner)->get(route('console.warmups.create', $workspace->getKey()));
        $warmupComposer
            ->assertOk()->assertSee('NEW WARMUP')->assertSee('Return Path:')->assertSee('Saved Emails:')
            ->assertSee('warmup-composer-page')->assertSee('Message content')->assertSee('Sending &amp; pacing', false)
            ->assertSee('Seeds:')->assertSee('Sleep Btw Email')->assertSee('Test After:')
            ->assertSee('USABLE TAGS:')->assertSee('mailer-tags-help')
            ->assertSee('Available IPs or MTAs')->assertSee('Selected IPs or MTAs');
        $warmupComposerHtml = $warmupComposer->getContent();
        $this->assertSame(1, substr_count($warmupComposerHtml, ' id="mailer-header"'));
        $this->assertSame(1, substr_count($warmupComposerHtml, ' id="mailer-body"'));
        $this->assertStringContainsString('data-name-prefix="placeholder_inputs"', $warmupComposerHtml);
        $this->assertStringContainsString('name="placeholder_combination"', $warmupComposerHtml);
        $this->assertStringContainsString('name="placeholder_all_combination"', $warmupComposerHtml);
        $this->assertStringContainsString('campaign-placeholder-editor.js?v=', $warmupComposerHtml);
        $this->assertStringContainsString('id="send-ips" name="ip_ids[]"', $warmupComposerHtml);
        $this->assertStringContainsString('id="available-send-ips"', $warmupComposerHtml);
        $this->assertStringContainsString('id="selected-send-ips"', $warmupComposerHtml);
        $this->assertStringContainsString('createMailerEditor(\'mailer-header-editor\', \'mailer-header\', \'text\', 30)', $warmupComposerHtml);
        $this->assertStringNotContainsString('for="mailer-draft-name"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-name" name="name" value="New Warmup"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-page-actions" role="menu"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-context-menu"', $warmupComposerHtml);
        $this->assertStringContainsString('mailer-draft-navigation.js?v=', $warmupComposerHtml);
        $this->assertStringContainsString('href="'.route('clean.console.pmta-admin.index').'"', $warmupComposerHtml);
        $this->assertStringContainsString('TEST — SAVE DRAFT FIRST', $warmupComposerHtml);
        $this->assertStringContainsString('Back to My Warmups', $warmupComposerHtml);
        $this->actingAs($owner)->get(route('console.warmups.statistics', $workspace->getKey()))
            ->assertOk()->assertSee('Warmup Statistics')->assertSee('No Warmup actions in this date range');
    }

    public function test_pmta_menu_opens_admin_and_selected_server_panel_in_new_tabs(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.36',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $owner->getKey(),
        ]);
        $secondServer = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic PMTA Second',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.37',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('e', 64),
            'created_by' => $owner->getKey(),
        ]);

        $html = $this->actingAs($owner)
            ->withSession(['_old_input' => ['send_setup' => ['server_ids' => [(string) $server->getKey(), (string) $secondServer->getKey()]]]])
            ->get(route('console.warmups.create', $workspace->getKey()))
            ->assertOk()
            ->getContent();

        $adminUrl = route('clean.console.pmta-admin.index', ['server_ids' => [$server->getKey(), $secondServer->getKey()]]);
        $this->assertStringContainsString('href="'.htmlspecialchars($adminUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-pmta-admin-link target="_blank" rel="noopener noreferrer"><span>PMTA ADMIN', $html);
        $this->assertStringContainsString("url.searchParams.append('server_ids[]', id)", $html);
        $this->assertStringContainsString("window.open(pmtaAdminLink.href, '_blank', 'noopener,noreferrer')", $html);
        $this->assertStringContainsString('data-pmta-panel-link', $html);
        preg_match('/data-selected-panel-urls=\'([^\']*)\'/u', $html, $selectedPanelUrlsMatch);
        $selectedPanelUrls = json_decode(htmlspecialchars_decode($selectedPanelUrlsMatch[1] ?? '', ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $selectedPanelUrls);
        $this->assertStringContainsString((string) $server->getKey(), $selectedPanelUrls[0]);
        $this->assertStringContainsString((string) $secondServer->getKey(), $selectedPanelUrls[1]);
        $this->assertStringContainsString("urls.forEach(url => window.open(url, '_blank', 'noopener,noreferrer'))", $html);
        $navigationScript = file_get_contents(public_path('alphalink/mailer-draft-navigation.js'));
        $this->assertStringContainsString('link.hasAttribute(\'data-pmta-admin-link\')', $navigationScript);
        $this->assertStringContainsString("window.open(url, '_blank', 'noopener,noreferrer')", $navigationScript);
        $this->assertStringContainsString("quickNav.dataset.alphalinkNavigationBound === 'true'", file_get_contents(public_path('alphalink/mailer-draft-navigation.js')));
        $this->assertStringContainsString("quickNav.dataset.alphalinkNavigationBound = 'true'", file_get_contents(public_path('alphalink/mailer-draft-navigation.js')));
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"><span>PMTA PANEL</span>', $html);

        $dropHtml = $this->actingAs($owner)
            ->withSession(['_old_input' => ['send_setup' => ['server_ids' => [(string) $server->getKey(), (string) $secondServer->getKey()]]]])
            ->get(route('console.drops.drafts.create', $workspace->getKey()))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-pmta-admin-link target="_blank" rel="noopener noreferrer"><span>PMTA ADMIN', $dropHtml);
        $this->assertStringContainsString('data-pmta-panel-link', $dropHtml);
        $this->assertStringContainsString("window.open(pmtaAdminLink.href, '_blank', 'noopener,noreferrer')", $dropHtml);
    }

    public function test_drop_drafts_save_workspace_selections_and_create_immutable_revisions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $isp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Synthetic ISP', 'is_active' => true]);
        $list = $this->audienceList($workspace, $owner, 'Synthetic US list', $isp->getKey(), 'US');
        $australiaList = $this->audienceList($workspace, $owner, 'Synthetic AU list', $isp->getKey(), 'AU');
        $idempotencyKey = (string) Str::uuid();
        $url = route('console.drops.drafts.store', $workspace->getKey());
        $payload = [
            'name' => 'Synthetic Drop', 'from' => 'Updates <mail@example.test>', 'subject' => 'First subject',
            'header' => 'Content-Type: text/html', 'html_body' => '<p>Original [domain] tag</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$list->getKey(), $australiaList->getKey()], 'countries' => ['US', 'AU'],
            'send_setup' => [
                'route_option' => 'ip', 'ip_type' => 'all', 'send_type' => 'delayp', 'total_send' => 50,
                'duplicate_data' => 3, 'delay' => 250, 'email_types' => ['open', 'click'],
                'placeholder_combination' => true,
                'placeholder_inputs' => [['data_text' => "one\ntwo", 'rotate_every' => 2]],
            ],
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($owner)->post($url, $payload)->assertRedirect();
        $draft = Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'drop')->firstOrFail();
        $revision = $draft->revisions()->firstOrFail();
        $this->assertSame('<p>Original [domain] tag</p>', $revision->payload['html_body']);
        $this->assertSame([(string) $list->getKey(), (string) $australiaList->getKey()], $revision->payload['list_ids']);
        $this->assertSame(['US', 'AU'], $revision->payload['countries']);
        $this->assertSame((int) $isp->getKey(), $revision->payload['send_setup']['isp_id']);
        $this->assertSame('delayp', $revision->payload['send_setup']['send_type']);
        $this->assertSame(3, $revision->payload['send_setup']['duplicate_data']);
        $this->assertSame(250, $revision->payload['send_setup']['delay']);
        $this->assertSame(['open', 'click'], $revision->payload['send_setup']['email_types']);
        $this->assertSame([['data' => ['one', 'two'], 'rotate_every' => 2]], $revision->payload['send_setup']['placeholders']);
        $this->assertTrue($revision->payload['send_setup']['placeholder_combination']);
        $this->assertSame(0, $draft->sendRuns()->count());

        $this->actingAs($owner)->put(route('console.drops.drafts.update', [$workspace->getKey(), $draft->getKey()]), [
            ...$payload,
            'name' => 'Synthetic Drop Updated',
            'subject' => 'Second subject',
            'html_body' => '<p>Edited without rewriting https://example.test/image.jpg</p>',
            'source_revision_id' => $revision->getKey(),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $draft->refresh();
        $this->assertSame('Synthetic Drop Updated', $draft->name);
        $this->assertSame(2, $draft->revisions()->count());
        $this->assertStringContainsString('https://example.test/image.jpg', $draft->latestRevision->payload['html_body']);
    }

    public function test_drop_draft_rejects_foreign_lists_and_isp_or_country_mismatches(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $isp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Selected ISP', 'is_active' => true]);
        $otherIsp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Other ISP', 'is_active' => true]);
        $otherIspList = $this->audienceList($workspace, $owner, 'Other ISP list', $otherIsp->getKey(), 'US');
        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'ISP mismatch', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$otherIspList->getKey()], 'countries' => ['US'],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('list_ids');

        $sameIspOtherCountry = $this->audienceList($workspace, $owner, 'Other country list', $isp->getKey(), 'AU');
        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'Country mismatch', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'isp_id' => $isp->getKey(), 'list_ids' => [$sameIspOtherCountry->getKey()], 'countries' => ['US'],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('countries');

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace($otherOwner, 'owner');
        $foreignList = $this->audienceList($otherWorkspace, $otherOwner, 'Foreign list');

        $this->actingAs($owner)->post(route('console.drops.drafts.store', $workspace->getKey()), [
            'name' => 'Synthetic Drop', 'subject' => 'Test', 'html_body' => '<p>Test</p>',
            'list_ids' => [$foreignList->getKey()], 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('list_ids');
        $this->assertDatabaseMissing('campaigns', ['workspace_id' => $workspace->getKey(), 'kind' => 'drop']);
    }

    public function test_warmup_drafts_validate_seed_ownership_and_save_configuration_without_sending(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $ownerList = WorkspaceSeedList::query()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Owner seeds', 'is_active' => true]);

        $this->actingAs($operator)->post(route('console.warmups.store', $workspace->getKey()), [
            'name' => 'Operator warmup', 'header' => 'Content-Type: text/html', 'html_body' => '<p>Warmup</p>',
            'seed_list_ids' => [$ownerList->getKey()], 'send_type' => 'delay', 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('seed_list_ids');

        $this->actingAs($owner)->post(route('console.warmups.store', $workspace->getKey()), [
            'name' => 'Synthetic warmup', 'return_path' => 'reply@example.test',
            'email_test_addresses_text' => "qa@example.test\n   \nops@example.test\n",
            'header' => 'Content-Type: text/html', 'html_body' => '<p>Warmup body</p>',
            'seed_list_ids' => [$ownerList->getKey()], 'send_type' => 'delayp',
            'total_send' => 500, 'fraction_ip' => 25, 'sleep_email' => 2, 'sleep_ip' => 5,
            'seed_every' => 10, 'bounce_limit' => 15, 'seed_accounts_text' => "warmup@example.test\n",
            'placeholder_combination' => true,
            'placeholder_inputs' => [['data_text' => "first\nsecond", 'rotate_every' => 3]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $draft = Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'warmup')->firstOrFail();
        $revision = $draft->latestRevision;
        $this->assertSame(['qa@example.test', 'ops@example.test'], $revision->payload['email_test_addresses']);
        $this->assertSame([(int) $ownerList->getKey()], $revision->payload['seed_list_ids']);
        $this->assertSame('delayp', $revision->payload['send_type']);
        $this->assertSame('warmup@example.test', $revision->payload['seed_accounts_text']);
        $this->assertSame([['data' => ['first', 'second'], 'rotate_every' => 3]], $revision->payload['send_setup']['placeholders']);
        $this->assertSame(0, $draft->sendRuns()->count());

        $this->actingAs($owner)->get(route('console.warmups.index', $workspace->getKey()))
            ->assertOk()->assertSee('Synthetic warmup')->assertSee('TOTAL SEND')->assertSee('DRAFT');
    }

    public function test_drop_send_queues_snapshot_preparation_instead_of_claiming_it_started(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $serverId = (string) Str::uuid();
        $ipId = (string) Str::uuid();
        $catalog = \Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('validateRevisionRouting')->once()->andReturn([
            'server_ids' => [$serverId],
            'ip_ids' => [$ipId],
        ]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);

        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Queued Drop fixture', 'kind' => 'drop', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['send_setup' => [
                'offer_id' => 7, 'audience_segments' => [(string) Str::uuid().'_fresh'],
                'route_option' => 'ip', 'ip_type' => 'ipv4', 'server_ids' => [$serverId], 'ip_ids' => [$ipId],
            ]],
            'content_sha256' => hash('sha256', 'queued drop content'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'queued drop request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('console.drops.send', [$workspace->getKey(), $campaign->getKey()]), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('clean.console.drops.drafts.index'));

        $this->assertSame('preparing_snapshot', $campaign->fresh()->status);
        Queue::assertPushedOn('drop-suppression', PrepareAndStartDropSend::class, fn (PrepareAndStartDropSend $job): bool => $job->workspaceId === (string) $workspace->getKey()
            && $job->campaignId === (string) $campaign->getKey()
            && $job->revisionId === (string) $revision->getKey());
    }

    public function test_warmup_send_snapshots_selected_seed_recipients_and_queues_the_worker(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $selectedList = WorkspaceSeedList::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Selected synthetic seeds', 'is_active' => true,
        ]);
        $unselectedList = WorkspaceSeedList::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Unselected synthetic seeds', 'is_active' => true,
        ]);
        $selectedList->emails()->createMany([
            ['email' => 'first@example.test'],
            ['email' => 'first@example.test'],
            ['email' => 'second@example.test'],
        ]);
        $unselectedList->emails()->create(['email' => 'unselected@example.test']);
        $serverId = (string) Str::uuid();
        $ipId = (string) Str::uuid();
        $catalog = \Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('validateRevisionRouting')->once()->andReturn([
            'server_ids' => [$serverId],
            'ip_ids' => [$ipId],
        ]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);

        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Queued Warmup fixture', 'kind' => 'warmup', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => [
                'send_type' => 'delayp', 'seed_list_ids' => [(int) $selectedList->getKey()], 'start_from' => 1, 'total_send' => 1,
                'header' => 'Content-Type: text/html', 'html_body' => '<p>Warmup fixture</p>',
                'send_setup' => ['route_option' => 'ip', 'ip_type' => 'ipv4', 'server_ids' => [$serverId], 'ip_ids' => [$ipId]],
            ],
            'content_sha256' => hash('sha256', 'queued warmup content'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'queued warmup request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('console.warmups.send', [$workspace->getKey(), $campaign->getKey()]), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('clean.console.warmups.index'));

        $run = WarmupSendRun::query()->where('campaign_id', $campaign->getKey())->firstOrFail();
        $recipient = $run->recipients()->firstOrFail();
        $this->assertSame('planned', $run->state);
        $this->assertSame('delayp', $run->send_type);
        $this->assertSame(1, $run->recipient_count);
        $this->assertNotSame('second@example.test', $recipient->recipient_ciphertext);
        $this->assertSame('second@example.test', Crypt::decryptString($recipient->recipient_ciphertext));
        Queue::assertPushedOn('campaign-sends', ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $run->getKey());

        $server = $workspace->managedServers()->create([
            'name' => 'Synthetic Warmup PMTA', 'provider' => 'Fixture provider', 'ip_address' => '203.0.113.180',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified', 'is_active' => true,
            'is_installed' => true, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'warmup worker server'), 'created_by' => $owner->getKey(),
        ]);
        $messageBuilder = \Mockery::mock(WarmupSendMessageBuilder::class);
        $messageBuilder->shouldReceive('context')->once()->andReturn(['port' => 2525]);
        $messageBuilder->shouldReceive('build')->once()->withArgs(fn (array $context, int $position, string $email, WarmupSendRun $activeRun): bool => $context['port'] === 2525
            && $position === 1 && $email === 'second@example.test' && $activeRun->is($run))
            ->andReturn([
                'server' => $server, 'header' => "To: second@example.test\nSubject: Warmup test",
                'body' => '<p>Warmup test body</p>', 'envelope_from' => 'warmup@example.test',
                'recipient' => 'second@example.test',
            ]);
        $transport = \Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once()->withArgs(fn ($message, $envelope): bool => str_contains($message->toString(), '<p>Warmup test body</p>')
            && $envelope->getRecipients()[0]->getAddress() === 'second@example.test');
        $transport->shouldReceive('stop')->once();
        $transportFactory = \Mockery::mock(PmtaCampaignTestTransport::class);
        $transportFactory->shouldReceive('forServer')->once()
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        (new ProcessWarmupSendRun((string) $run->getKey()))->handle($messageBuilder, $transportFactory);

        $run->refresh();
        $recipient->refresh();
        $this->assertSame('completed', $run->state);
        $this->assertSame(1, $run->accepted_count);
        $this->assertSame('accepted', $recipient->state);
    }

    public function test_drop_draft_register_lists_all_owned_drafts_and_supports_search(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($otherUser->getKey(), ['role' => 'operator']);
        foreach (['Older saved Drop', 'Latest saved Drop'] as $index => $name) {
            $campaign = Campaign::query()->create([
                'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
                'name' => $name, 'kind' => 'drop', 'status' => 'draft', 'is_active' => true,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            DB::table('campaigns')->where('id', $campaign->getKey())->update([
                'created_at' => now()->addSeconds($index)->toDateTimeString(),
                'updated_at' => now()->addSeconds($index)->toDateTimeString(),
            ]);
            CampaignRevision::query()->create([
                'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
                'payload' => ['draft_type' => 'drop', 'subject' => 'Stored', 'html_body' => '<p>Stored</p>'],
                'content_sha256' => hash('sha256', 'recent drop '.$index.' '.Str::random(8)),
                'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'recent '.Str::random(8)),
                'created_by' => $owner->getKey(), 'created_at' => now()->addSeconds($index),
            ]);
        }
        $foreignDraft = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $otherUser->getKey(),
            'name' => 'Another user Drop', 'kind' => 'drop', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        CampaignRevision::query()->create([
            'campaign_id' => $foreignDraft->getKey(), 'revision_number' => 1,
            'payload' => ['draft_type' => 'drop', 'subject' => 'Private', 'html_body' => '<p>Private</p>'],
            'content_sha256' => hash('sha256', 'foreign drop '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'foreign '.Str::random(8)),
            'created_by' => $otherUser->getKey(), 'created_at' => now(),
        ]);
        $this->actingAs($owner)->get(route('console.drops.drafts.index', $workspace->getKey()))
            ->assertOk()->assertSee('My Drop Drafts')->assertSee('Latest saved Drop')->assertSee('Older saved Drop')
            ->assertDontSee('Another user Drop')->assertSee('Edit Drop draft');
        $this->actingAs($owner)->get(route('console.drops.drafts.index', [$workspace->getKey(), 'q' => 'Older']))
            ->assertOk()->assertSee('Older saved Drop')->assertDontSee('Latest saved Drop');
        $this->assertSame(3, Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'drop')->count());
    }

    public function test_warmup_statistics_count_only_saved_unique_recipient_actions(): void
    {
        $fixture = $this->warmupTrackingFixture();
        foreach (['open', 'click', 'unsubscribe', 'bot'] as $action) {
            $event = CampaignEngagementEvent::query()->create([
                'workspace_id' => $fixture['workspace']->getKey(),
                'campaign_id' => $fixture['campaign']->getKey(),
                'revision_id' => $fixture['revision']->getKey(),
                'recipient_snapshot_id' => $fixture['snapshot']->getKey(),
                'snapshot_item_id' => $fixture['item']->getKey(),
                'contact_id' => $fixture['contact']->getKey(),
                'audience_list_id' => $fixture['list']->getKey(),
                'source_email_type' => 'fresh', 'action' => $action,
                'client_ip' => '192.0.2.15', 'user_agent' => 'Synthetic warmup test',
                'token_sha256' => hash('sha256', Str::random(20)), 'created_at' => now(),
            ]);
            DB::table('campaign_engagement_unique_actions')->insertOrIgnore([
                'workspace_id' => $event->workspace_id, 'campaign_id' => $event->campaign_id,
                'contact_id' => $event->contact_id, 'action' => $event->action,
                'engagement_event_id' => $event->getKey(), 'created_at' => $event->created_at,
            ]);
        }

        $this->actingAs($fixture['owner'])->get(route('console.warmups.statistics', $fixture['workspace']->getKey()))
            ->assertOk()->assertSee('Synthetic tracked warmup')
            ->assertSee('Unique opens')->assertSee('Automated actions')
            ->assertSee('>1</div>', false);
    }

    /** @return array{owner: User, workspace: Workspace} */
    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic mail operations workspace',
            'slug' => 'mailer-operations-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function audienceList(Workspace $workspace, User $creator, string $name, ?int $ispId = null, ?string $country = null): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $creator->getKey(),
            'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name.' '.Str::random(12)),
            'isp_id' => $ispId, 'country' => $country, 'allowed_users' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function warmupTrackingFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic warmup sponsor',
            'affiliate_id' => 'warmup-'.Str::random(8), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-key',
            'type' => 'hitpath', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'warmup-'.Str::random(8), 'name' => 'Synthetic warmup offer',
            'type' => 'cpa', 'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $list = $this->audienceList($workspace, $owner, 'Synthetic warmup audience');
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'private-warmup@example.test',
            'email_lookup_hash' => hash('sha256', 'private-warmup@example.test'), 'lookup_key_version' => 1,
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Synthetic tracked warmup', 'kind' => 'warmup', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1, 'payload' => ['draft_type' => 'warmup'],
            'content_sha256' => hash('sha256', 'warmup revision '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'warmup request '.Str::random(8)),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'synthetic warmup suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']], 'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', $contact->getKey()), 'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(), 'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash, 'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh', 'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'campaign', 'revision', 'snapshot', 'item', 'contact', 'list');
    }
}
