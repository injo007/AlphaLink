<?php

namespace Tests\Feature;

use App\Jobs\PrepareAndStartDropSend;
use App\Jobs\ProcessWarmupSendRun;
use App\Models\AudienceList;
use App\Models\AuditEvent;
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
use App\Services\Campaigns\SendRunPacing;
use App\Services\Campaigns\WarmupSendMessageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

class WorkspaceMailerOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmup_composer_offers_sending_domain_delivery(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $index = $this->actingAs($owner)->get(route('console.warmups.index', $workspace->getKey()));
        $index->assertOk()->assertSee('id="warmup-analytics-panel" class="campaign-intelligence warmup-analytics-panel" aria-label="Warmup analytics" hidden', false)
            ->assertDontSee('All Warmups');
        $this->assertLessThan(
            strpos($index->getContent(), '<aside id="warmup-analytics-panel"'),
            strpos($index->getContent(), '<main class="campaign-register-main"'),
            'Warmup analytics should follow the register in document order.',
        );

        $response = $this->actingAs($owner)->get(route('console.warmups.create', $workspace->getKey()));

        $response->assertOk()->assertSee('name="route_option"', false)
            ->assertSee('<option value="domain"', false)->assertSee('DOMAIN')
            ->assertSee('TEST CURRENT WARMUP')->assertSee('CREATE &amp; SEND WARMUP', false)
            ->assertSee('id="add-saved-email"', false)
            ->assertSee('id="campaign-test-address-dialog"', false)
            ->assertSee('data-store-url=', false)
            ->assertSee('Test uses the open Warmup form without saving first.');
    }

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
            ->assertSee('id="warmup-analytics-panel" class="campaign-intelligence warmup-analytics-panel" aria-label="Warmup analytics" hidden', false)
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
        $this->assertStringContainsString('<option value="domain">DOMAIN</option>', $warmupComposerHtml);
        $this->assertStringContainsString('createMailerEditor(\'mailer-header-editor\', \'mailer-header\', \'text\', 30)', $warmupComposerHtml);
        $this->assertStringNotContainsString('for="mailer-draft-name"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-name" name="name" value="New Warmup"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-page-actions" role="menu"', $warmupComposerHtml);
        $this->assertStringContainsString('id="mailer-draft-context-menu"', $warmupComposerHtml);
        $this->assertStringContainsString('mailer-draft-navigation.js?v=', $warmupComposerHtml);
        $this->assertStringContainsString('href="'.route('clean.console.pmta-admin.index').'"', $warmupComposerHtml);
        $this->assertStringContainsString('TEST CURRENT WARMUP', $warmupComposerHtml);
        $this->assertStringContainsString('CREATE &amp; SEND WARMUP', $warmupComposerHtml);
        $this->assertStringContainsString('name="submission_intent" value="save"', $warmupComposerHtml);
        $this->assertStringContainsString(route('console.campaigns.manual-test', $workspace->getKey()), $warmupComposerHtml);
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
        ])->assertRedirect(route('console.drops.drafts.edit', [$workspace->getKey(), $draft->getKey()]));

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

        $response = $this->actingAs($owner)->post(route('console.warmups.store', $workspace->getKey()), [
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

    public function test_warmup_save_updates_latest_revision_and_returns_to_register(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Editable Warmup', 'kind' => 'warmup', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['draft_type' => 'warmup', 'send_type' => 'delay', 'header' => 'Subject: Before', 'html_body' => '<p>Before</p>', 'send_setup' => []],
            'content_sha256' => hash('sha256', 'warmup original '.Str::random(8)),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'warmup original request '.Str::random(8)),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);

        $saveKey = (string) Str::uuid();
        $savePayload = [
            'name' => 'Editable Warmup', 'return_path' => 'reply@example.test',
            'email_test_addresses_text' => 'qa@example.test',
            'header' => 'Subject: Latest edits', 'html_body' => '<p>Latest warmup body</p>',
            'send_type' => 'delayp', 'total_send' => 12, 'fraction_ip' => 3,
            'sleep_email' => 250, 'sleep_ip' => 900, 'seed_every' => 4, 'bounce_limit' => 6,
            'route_option' => 'ip', 'ip_type' => 'all', 'server_ids' => [], 'ip_ids' => [],
            'idempotency_key' => $saveKey, 'source_revision_id' => $revision->getKey(),
            'submission_intent' => 'save', 'send_idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($owner)->put(route('console.warmups.update', [$workspace->getKey(), $campaign->getKey()]), $savePayload)
            ->assertRedirect(route('clean.console.warmups.index'));

        // An identical browser retry carries the old source revision and the
        // same idempotency key. It must acknowledge the saved revision, not 409.
        $this->actingAs($owner)->put(route('console.warmups.update', [$workspace->getKey(), $campaign->getKey()]), $savePayload)
            ->assertRedirect(route('clean.console.warmups.index'));

        $staleEdit = $savePayload;
        $staleEdit['idempotency_key'] = (string) Str::uuid();
        $staleEdit['html_body'] = '<p>Preserved conflicting edit</p>';
        $this->actingAs($owner)->from(route('console.warmups.edit', [$workspace->getKey(), $campaign->getKey()]))
            ->put(route('console.warmups.update', [$workspace->getKey(), $campaign->getKey()]), $staleEdit)
            ->assertRedirect(route('clean.console.warmups.edit', $campaign->getKey()))
            ->assertSessionHasErrors('draft_conflict');

        $campaign->refresh()->load('latestRevision');
        $this->assertSame(2, $campaign->latestRevision->revision_number);
        $this->assertSame('<p>Latest warmup body</p>', $campaign->latestRevision->payload['html_body']);
        $this->assertSame('delayp', $campaign->latestRevision->payload['send_type']);
        $this->assertSame(250, $campaign->latestRevision->payload['sleep_email']);
        $this->assertSame(900, $campaign->latestRevision->payload['sleep_ip']);
        $this->assertSame(2, $campaign->revisions()->count());
        $this->assertSame('<p>Latest warmup body</p>', $campaign->latestRevision->payload['html_body']);
        $this->assertSame(0, $campaign->sendRuns()->count());
    }

    public function test_warmup_send_can_pause_and_resume_without_creating_another_run(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Pauseable Warmup', 'kind' => 'warmup', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['draft_type' => 'warmup', 'send_type' => 'delay', 'send_setup' => []],
            'content_sha256' => hash('sha256', 'pauseable warmup content'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'pauseable warmup request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $run = WarmupSendRun::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(), 'revision_id' => $revision->getKey(),
            'requested_by' => $owner->getKey(), 'send_type' => 'delay', 'state' => 'planned',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'pause warmup run'),
            'recipient_count' => 1, 'next_position' => 1, 'execution_options' => [],
        ]);

        $index = route('console.warmups.index', $workspace->getKey());
        $pause = route('console.warmups.send-pause', [$workspace->getKey(), $campaign->getKey(), $run->getKey()]);
        $resume = route('console.warmups.send-resume', [$workspace->getKey(), $campaign->getKey(), $run->getKey()]);
        $this->actingAs($owner)->get($index)->assertOk()->assertSee('Pause Warmup send');
        $this->actingAs($owner)->post($pause)->assertRedirect(route('clean.console.warmups.index'));
        $this->assertSame('paused', $run->fresh()->state);
        $this->actingAs($owner)->get($index)->assertOk()->assertSee('Resume Warmup send');
        $this->actingAs($owner)->post($resume)->assertRedirect(route('clean.console.warmups.index'));
        $this->assertSame('running', $run->fresh()->state);
        $this->assertSame(1, WarmupSendRun::query()->where('campaign_id', $campaign->getKey())->count());
        Queue::assertPushedOn('campaign-sends', ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $run->getKey());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'warmup.send_paused')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'warmup.send_resumed')->count());
    }

    public function test_create_and_send_warmup_saves_latest_form_values_before_queueing(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $seedList = WorkspaceSeedList::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Send form seeds', 'is_active' => true,
        ]);
        $seedList->emails()->createMany([
            ['email' => 'first-send@example.test'],
            ['email' => 'second-send@example.test'],
            ['email' => 'third-send@example.test'],
        ]);
        $serverId = (string) Str::uuid();
        $routeId = '10';
        $catalog = \Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('options')->once()->andReturn([
            'sponsors' => [], 'offers' => [], 'isps' => [], 'segments' => [], 'negatives' => [],
            'servers' => [['id' => $serverId]], 'ips' => [['id' => $routeId]], 'campaign_domains' => [],
        ]);
        $catalog->shouldReceive('validateRevisionRouting')->once()->andReturn([
            'server_ids' => [$serverId], 'ip_ids' => [$routeId],
        ]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);

        $this->actingAs($owner)->post(route('console.warmups.store', $workspace->getKey()), [
            'name' => 'Create and Send Warmup', 'return_path' => 'reply@example.test',
            'email_test_addresses_text' => 'qa@example.test',
            'header' => 'Content-Type: text/html\nFrom: sender@example.test\nSubject: Current version',
            'html_body' => '<p>Current unsaved form version</p>',
            'seed_list_ids' => [$seedList->getKey()], 'send_type' => 'delayps',
            'total_send' => 2, 'fraction_ip' => 2, 'sleep_email' => 1200, 'sleep_ip' => 2400,
            'seed_every' => 8, 'bounce_limit' => 9, 'route_option' => 'ip', 'ip_type' => 'ipv4',
            'server_ids' => [$serverId], 'ip_ids' => [$routeId],
            'idempotency_key' => (string) Str::uuid(), 'send_idempotency_key' => (string) Str::uuid(),
            'submission_intent' => 'create_send',
        ])->assertRedirect(route('clean.console.warmups.index'))->assertSessionHasNoErrors();

        $campaign = Campaign::query()->where('workspace_id', $workspace->getKey())->where('kind', 'warmup')->firstOrFail();
        $campaign->load('latestRevision');
        $this->assertSame('<p>Current unsaved form version</p>', $campaign->latestRevision->payload['html_body']);
        $this->assertSame('delayps', $campaign->latestRevision->payload['send_type']);
        $this->assertSame(2, $campaign->latestRevision->payload['total_send']);
        $this->assertSame(2, $campaign->latestRevision->payload['fraction_ip']);
        $this->assertSame(1200, $campaign->latestRevision->payload['sleep_email']);
        $this->assertSame(2400, $campaign->latestRevision->payload['sleep_ip']);
        $this->assertSame(8, $campaign->latestRevision->payload['seed_every']);
        $this->assertSame(9, $campaign->latestRevision->payload['bounce_limit']);
        $run = WarmupSendRun::query()->where('campaign_id', $campaign->getKey())->firstOrFail();
        $this->assertSame($campaign->latestRevision->getKey(), $run->revision_id);
        $this->assertSame('delayps', $run->send_type);
        $this->assertSame(2, $run->execution_options['total_send']);
        $this->assertSame(2, $run->execution_options['fraction_ip']);
        $this->assertSame(1200, $run->execution_options['sleep_email_ms']);
        $this->assertSame(2400, $run->execution_options['sleep_ip_ms']);
        $this->assertSame(8, $run->execution_options['seed_every']);
        $this->assertSame(8, $run->execution_options['test_after']);
        $this->assertSame(9, $run->execution_options['bounce_limit']);
        Queue::assertPushedOn('campaign-sends', ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $run->getKey());
    }

    public function test_warmup_schedule_queues_the_saved_revision_for_the_selected_time(): void
    {
        Queue::fake();
        $fixture = $this->warmupActionFixture();
        $catalog = \Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('validateRevisionRouting')->once()->andReturn([]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);
        $scheduledInput = now()->addMinutes(10)->startOfMinute()->format('Y-m-d\\TH:i');
        $expectedSchedule = Carbon::createFromFormat('Y-m-d\\TH:i', $scheduledInput, config('app.timezone'));

        $this->actingAs($fixture['owner'])->post(route('console.warmups.schedule', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]), [
            'idempotency_key' => (string) Str::uuid(),
            'scheduled_at' => $scheduledInput,
        ])->assertRedirect(route('clean.console.warmups.index'))->assertSessionHasNoErrors();

        $run = WarmupSendRun::query()->where('campaign_id', $fixture['campaign']->getKey())->firstOrFail();
        $this->assertSame('planned', $run->state);
        $this->assertSame((string) $fixture['revision']->getKey(), (string) $run->revision_id);
        $this->assertSame($expectedSchedule->toIso8601String(), $run->execution_options['scheduled_at']);
        $this->assertSame('delay', $run->send_type);
        $this->assertSame(300, $run->execution_options['sleep_email_ms']);
        $this->assertSame(900, $run->execution_options['sleep_ip_ms']);
        $this->assertSame(2, $run->recipient_count);
        Queue::assertPushed(ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $run->getKey()
            && $job->delay instanceof \DateTimeInterface
            && $job->delay->getTimestamp() === $expectedSchedule->getTimestamp());
    }

    public function test_completed_warmup_can_be_resent_with_a_new_idempotent_run(): void
    {
        Queue::fake();
        $fixture = $this->warmupActionFixture();
        WarmupSendRun::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'campaign_id' => $fixture['campaign']->getKey(), 'revision_id' => $fixture['revision']->getKey(),
            'requested_by' => $fixture['owner']->getKey(), 'send_type' => 'delay', 'state' => 'completed',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'completed warmup fixture'),
            'recipient_count' => 2, 'next_position' => 3, 'accepted_count' => 2, 'completed_at' => now(), 'execution_options' => [],
        ]);
        $catalog = \Mockery::mock(AlphaLinkCampaignSendCatalog::class);
        $catalog->shouldReceive('validateRevisionRouting')->once()->andReturn([]);
        $this->app->instance(AlphaLinkCampaignSendCatalog::class, $catalog);

        $this->actingAs($fixture['owner'])->post(route('console.warmups.resend', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('clean.console.warmups.index'))->assertSessionHasNoErrors();

        $runs = WarmupSendRun::query()->where('campaign_id', $fixture['campaign']->getKey())->orderBy('created_at')->get();
        $this->assertCount(2, $runs);
        $this->assertSame((string) $fixture['revision']->getKey(), (string) $runs->last()->revision_id);
        $this->assertSame('planned', $runs->last()->state);
        $this->assertSame(2, $runs->last()->recipient_count);
        Queue::assertPushed(ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $runs->last()->getKey());
    }

    public function test_warmup_actions_follow_the_latest_revision_send_history(): void
    {
        $fixture = $this->warmupActionFixture();
        $newRevision = CampaignRevision::query()->create([
            'campaign_id' => $fixture['campaign']->getKey(), 'revision_number' => 2,
            'payload' => $fixture['revision']->payload,
            'content_sha256' => hash('sha256', 'updated warmup content'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'updated warmup request'),
            'created_by' => $fixture['owner']->getKey(), 'created_at' => now()->addSecond(),
        ]);
        WarmupSendRun::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'campaign_id' => $fixture['campaign']->getKey(), 'revision_id' => $fixture['revision']->getKey(),
            'requested_by' => $fixture['owner']->getKey(), 'send_type' => 'delay', 'state' => 'completed',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'previous revision completed fixture'),
            'recipient_count' => 2, 'next_position' => 3, 'accepted_count' => 2, 'completed_at' => now(), 'execution_options' => [],
        ]);

        $response = $this->actingAs($fixture['owner'])->get(route('console.warmups.index', $fixture['workspace']->getKey()))->assertOk();
        $response->assertSee('title="Queue Warmup send"', false)
            ->assertSee('disabled title="Resend is available after a completed send"', false);
        $this->assertSame(2, $newRevision->revision_number);
    }

    public function test_warmup_removal_retains_history_and_can_be_restored(): void
    {
        $fixture = $this->warmupActionFixture();
        $indexUrl = route('console.warmups.index', $fixture['workspace']->getKey());
        $index = $this->actingAs($fixture['owner'])->get($indexUrl)->assertOk()->assertSee('PMTA Admin for');
        $this->assertStringContainsString((string) $fixture['serverId'], $index->getContent());
        $this->get(route('console.pmta-admin.index', [$fixture['workspace']->getKey(), 'server_ids' => [$fixture['serverId']]]))
            ->assertOk()->assertSee('Synthetic Warmup PMTA');

        $this->post(route('console.warmups.archive', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]))
            ->assertRedirect(route('clean.console.warmups.index'))
            ->assertSessionHas('status', 'Warmup removed from the active list. Its saved revisions and send history were retained.');
        $this->assertDatabaseHas('campaigns', ['id' => $fixture['campaign']->getKey(), 'is_active' => false]);
        $this->assertDatabaseHas('campaign_revisions', ['id' => $fixture['revision']->getKey()]);
        $this->get($indexUrl)->assertOk()->assertDontSee('Reversible Warmup fixture');
        $this->get($indexUrl.'?archived=1')->assertOk()->assertSee('Reversible Warmup fixture')->assertSee('Restore Warmup');

        $this->post(route('console.warmups.restore', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]))
            ->assertRedirect(route('clean.console.warmups.index'))
            ->assertSessionHas('status', 'Warmup restored to the active list.');
        $this->assertDatabaseHas('campaigns', ['id' => $fixture['campaign']->getKey(), 'is_active' => true]);
        $this->assertSame(2, AuditEvent::query()->where('subject_id', $fixture['campaign']->getKey())->count());
    }

    public function test_warmup_archive_rejects_active_sends_and_read_only_members(): void
    {
        $fixture = $this->warmupActionFixture();
        $activeRun = WarmupSendRun::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(), 'campaign_id' => $fixture['campaign']->getKey(), 'revision_id' => $fixture['revision']->getKey(),
            'requested_by' => $fixture['owner']->getKey(), 'send_type' => 'delay', 'state' => 'planned',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'active warmup fixture'),
            'recipient_count' => 2, 'next_position' => 1, 'execution_options' => [],
        ]);
        $archiveUrl = route('console.warmups.archive', [$fixture['workspace']->getKey(), $fixture['campaign']->getKey()]);
        $this->actingAs($fixture['owner'])->post($archiveUrl)->assertConflict();
        $this->assertDatabaseHas('campaigns', ['id' => $fixture['campaign']->getKey(), 'is_active' => true]);

        $viewer = User::factory()->create();
        $fixture['workspace']->users()->attach($viewer->getKey(), ['role' => 'viewer']);
        $this->actingAs($viewer)->post($archiveUrl)->assertNotFound();
        $this->assertSame('planned', $activeRun->fresh()->state);
        $this->assertDatabaseHas('campaigns', ['id' => $fixture['campaign']->getKey(), 'is_active' => true]);
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
            ['email' => 'third@example.test'],
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
                'send_type' => 'delayp', 'seed_list_ids' => [(int) $selectedList->getKey()], 'start_from' => 1, 'total_send' => 2,
                'fraction_ip' => 1, 'sleep_email' => 11, 'sleep_ip' => 23, 'seed_every' => 7, 'bounce_limit' => 5, 'test_after' => 7,
                'header' => "Content-Type: text/html\nFrom: sender@[domain]\nSubject: Warmup fixture",
                'html_body' => '<p>Warmup fixture at https://[domain]/offer</p>',
                'return_path' => 'bounce@[domain]',
                'send_setup' => ['route_option' => 'domain', 'ip_type' => 'ipv4', 'server_ids' => [$serverId], 'ip_ids' => [$ipId]],
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
        $this->assertSame(2, $run->recipient_count);
        $this->assertSame('delayp', $run->execution_options['send_type']);
        $this->assertSame(2, $run->execution_options['total_send']);
        $this->assertSame(1, $run->execution_options['fraction_ip']);
        $this->assertSame(11, $run->execution_options['sleep_email_ms']);
        $this->assertSame(23, $run->execution_options['sleep_ip_ms']);
        $this->assertSame(7, $run->execution_options['seed_every']);
        $this->assertSame(7, $run->execution_options['test_after']);
        $this->assertSame(5, $run->execution_options['bounce_limit']);
        $this->assertSame('domain', $campaign->latestRevision->payload['send_setup']['route_option']);
        $this->assertNotSame('second@example.test', $recipient->recipient_ciphertext);
        $this->assertSame('second@example.test', Crypt::decryptString($recipient->recipient_ciphertext));
        Queue::assertPushedOn('campaign-sends', ProcessWarmupSendRun::class, fn (ProcessWarmupSendRun $job): bool => $job->sendRunId === (string) $run->getKey());

        $server = $workspace->managedServers()->create([
            'name' => 'Synthetic Warmup PMTA', 'provider' => 'Fixture provider', 'ip_address' => '203.0.113.180',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified', 'is_active' => true,
            'is_installed' => true, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'warmup worker server'), 'created_by' => $owner->getKey(),
        ]);
        $domainChoice = [
            'id' => $ipId,
            'server_id' => (string) $server->getKey(),
            'managed_server_ip_id' => $ipId,
            'sending_domain_id' => (string) Str::uuid(),
            'ip' => '203.0.113.180',
            'type' => 'ipv4',
            'domain' => 'warmup-route.example.test',
            'dns' => 'warmup-route.example.test',
            'is_ready' => true,
        ];
        $messageBuilder = app(WarmupSendMessageBuilder::class);
        $messageContext = [
            'campaign' => $campaign,
            'setup' => ['route_option' => 'domain'],
            'route' => 'domain',
            'port' => 2525,
            'servers' => collect([$server]),
            'choices_by_server' => collect([(string) $server->getKey() => collect([$domainChoice])]),
            'header_template' => "Content-Type: text/html\nFrom: sender@[domain]\nSubject: Warmup fixture",
            'body_template' => '<p>Warmup fixture at https://[domain]/offer</p>',
            'return_path' => 'bounce@[domain]',
            'negative_path' => '',
            'placeholder_rows' => [],
        ];
        $builtMessage = $messageBuilder->build($messageContext, 1, 'second@example.test', $run);
        $this->assertSame('sender@warmup-route.example.test', $this->headerAddress($builtMessage['header'], 'From'));
        $this->assertSame('bounce@warmup-route.example.test', $builtMessage['envelope_from']);
        $this->assertStringContainsString('https://warmup-route.example.test/offer', $builtMessage['body']);
        $messageContext['header_template'] = "From: sender@wrong.example.test\nSubject: Warmup fixture";
        try {
            $messageBuilder->build($messageContext, 1, 'second@example.test', $run);
            $this->fail('A domain-routed Warmup must reject a From address outside the selected domain.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('header', $exception->errors());
        }

        $workerMessageBuilder = \Mockery::mock(WarmupSendMessageBuilder::class);
        $workerMessageBuilder->shouldReceive('context')->once()->andReturn(['port' => 2525]);
        $workerMessageBuilder->shouldReceive('build')->once()->withArgs(fn (array $context, int $position, string $email, WarmupSendRun $activeRun): bool => $context['port'] === 2525
            && $position === 1 && $email === 'second@example.test' && $activeRun->is($run))
            ->andReturn($builtMessage);
        $transport = \Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once()->withArgs(fn ($message, $envelope): bool => str_contains($message->toString(), 'From: sender@warmup-route.example.test')
            && str_contains($message->toString(), 'https://warmup-route.example.test/offer')
            && $envelope->getSender()->getAddress() === 'bounce@warmup-route.example.test'
            && $envelope->getRecipients()[0]->getAddress() === 'second@example.test');
        $transport->shouldReceive('stop')->once();
        $transportFactory = \Mockery::mock(PmtaCampaignTestTransport::class);
        $transportFactory->shouldReceive('forServer')->once()
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        $workerStartedAt = now()->getTimestampMs();
        (new ProcessWarmupSendRun((string) $run->getKey()))->handle($workerMessageBuilder, $transportFactory, app(SendRunPacing::class));

        $run->refresh();
        $recipient->refresh();
        $this->assertSame('running', $run->state);
        $this->assertSame(1, $run->accepted_count);
        $this->assertGreaterThanOrEqual($workerStartedAt + 34, $run->execution_options['next_batch_at_ms']);
        $this->assertSame('third@example.test', Crypt::decryptString($run->recipients()->where('position', 2)->firstOrFail()->recipient_ciphertext));
        $this->assertSame('accepted', $recipient->state);
        Queue::assertPushed(ProcessWarmupSendRun::class, 2);
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

    /** @return array{owner: User, workspace: Workspace, campaign: Campaign, revision: CampaignRevision, serverId: string} */
    private function warmupActionFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $seedList = WorkspaceSeedList::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'Action fixture seeds', 'is_active' => true,
        ]);
        $seedList->emails()->createMany([
            ['email' => 'warmup-action-one@example.test'],
            ['email' => 'warmup-action-two@example.test'],
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Reversible Warmup fixture', 'kind' => 'warmup', 'status' => 'draft', 'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic Warmup PMTA',
            'provider' => 'Fixture provider', 'ip_address' => '203.0.113.181',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'warmup action server'),
            'created_by' => $owner->getKey(),
        ]);
        $serverId = (string) $server->getKey();
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => [
                'draft_type' => 'warmup', 'send_type' => 'delay', 'total_send' => 2,
                'seed_list_ids' => [$seedList->getKey()], 'sleep_email' => 300, 'sleep_ip' => 900,
                'send_setup' => ['route_option' => 'ip', 'ip_type' => 'ipv4', 'server_ids' => [$serverId], 'ip_ids' => ['10']],
            ],
            'content_sha256' => hash('sha256', 'reversible warmup content'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'reversible warmup request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);

        return compact('owner', 'workspace', 'campaign', 'revision', 'serverId');
    }

    private function headerAddress(string $header, string $name): string
    {
        preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $header, $matches);

        return trim((string) ($matches[1] ?? ''));
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
