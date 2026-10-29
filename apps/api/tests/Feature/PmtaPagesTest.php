<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\PmtaActionHistory;
use App\Models\PmtaConfigHistory;
use App\Models\PmtaScheduledAction;
use App\Models\PmtaSmtpConfig;
use App\Models\PmtaVirtualMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\PmtaBounceLogReader;
use App\Services\PmtaConfigFileCatalog;
use App\Services\PmtaRemoteConfiguration;
use App\Services\PmtaScheduleFrequency;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PmtaPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_workspace_member_can_reach_the_dms_pmta_navigation_pages(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        $this->server($workspace, $owner);

        foreach ([
            'console.pmta-admin.index', 'console.pmta-admin.history', 'console.pmta-scheduler.index', 'console.pmta-settings.index',
            'console.pmta-vmta.index', 'console.pmta-smtp.index', 'console.pmta-errors.index', 'console.pmta-config-history.index',
        ] as $route) {
            $this->actingAs($member)->get(route($route, $workspace->getKey()))
                ->assertOk()
                ->assertSee('PMTA SCHEDULER')
                ->assertSee('PMTA SETTINGS')
                ->assertSee('PMTA VMTA')
                ->assertSee('PMTA SMTP')
                ->assertSee('PMTA ERRORS')
                ->assertSee('PMTA CONFIG HISTORY');
        }
    }

    public function test_pmta_navigation_matches_dms_section_order_and_starts_with_the_sidebar_minimized(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $response = $this->actingAs($owner)->get(route('console.pmta-scheduler.index', $workspace->getKey()));
        $response->assertOk();
        $html = (string) $response->getContent();

        self::assertStringContainsString('page-header-fixed sidebar-mini', $html);
        self::assertStringContainsString('aria-label="Toggle navigation width" aria-pressed="true"', $html);
        self::assertStringContainsString('aria-controls="nav-submenu-servers-4"', $html);
        self::assertStringContainsString('aria-controls="nav-submenu-servers-12"', $html);
        self::assertLessThan(strpos($html, 'GLOBAL TESTS'), strpos($html, 'id="nav-submenu-servers-4"'));
        self::assertGreaterThan(strpos($html, 'GLOBAL TESTS'), strpos($html, 'id="nav-submenu-servers-12"'));

        $pmtaNavigation = substr($html, strpos($html, 'id="nav-submenu-servers-12"'));
        $labels = ['MY SERVERS', 'MY IPS', 'PMTA', 'PMTA SCHEDULER', 'PMTA SETTINGS', 'PMTA VMTA', 'PMTA SMTP', 'PMTA ERRORS', 'PMTA ACTION HISTORY', 'PMTA CONFIG HISTORY'];
        $positions = array_map(static fn (string $label): int|false => strpos($pmtaNavigation, $label), $labels);

        self::assertNotContains(false, $positions);
        $sortedPositions = $positions;
        sort($sortedPositions);
        self::assertSame($sortedPositions, $positions);
    }

    public function test_pmta_admin_uses_the_dms_responsive_control_layout(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $html = (string) $this->actingAs($owner)
            ->get(route('console.pmta-admin.index', $workspace->getKey()))
            ->assertOk()
            ->getContent();

        $controlOrder = [
            'id="pmta-admin-fields-row"',
            'class="col-sm-12 col-md-7" id="pmta-admin-controls"',
            'id="pmta-admin-servers"',
            '<fieldset class="form-group border-0 p-0">',
            'id="pmta-domain-queue"',
            'id="pmta-admin-tag-column"',
            'id="pmta-admin-tag-box"',
            'id="pmta-admin-action-row"',
            'id="pmta-admin-schedule-row"',
            'id="pmta-admin-seconds"',
            'id="pmta-admin-restart-seconds"',
            'id="pmta-admin-results-column"',
            'id="pmta-admin-schedule-status"',
            'id="pmta-admin-panels"',
        ];
        $positions = array_map(static fn (string $needle): int|false => strpos($html, $needle), $controlOrder);

        self::assertNotContains(false, $positions);
        $sortedPositions = $positions;
        sort($sortedPositions);
        self::assertSame($sortedPositions, $positions);
        self::assertStringContainsString('class="row justify-content-center" id="pmta-admin-fields-row"', $html);
        self::assertStringContainsString('@media (min-width: 1200px) and (max-width: 1399.98px)', $html);
        self::assertStringContainsString('id="pmta-admin-server-column"', $html);
        self::assertStringContainsString('grid-template-columns: repeat(12, minmax(0, 1fr));', $html);
        self::assertStringContainsString('max-width: 69.5rem;', $html);
        self::assertStringContainsString('min-width: 69.5rem;', $html);
        self::assertStringContainsString('margin-left: 3.9%;', $html);
        self::assertStringContainsString('width: 69.1%;', $html);
        self::assertStringContainsString('margin-left: 74.3%;', $html);
        self::assertStringContainsString('width: 24%;', $html);
        self::assertStringContainsString('margin-left: 1.6%;', $html);
        self::assertStringContainsString('margin-top: -.9rem;', $html);
        self::assertStringContainsString('margin-bottom: -.2rem;', $html);
        self::assertStringContainsString('margin-top: .7rem;', $html);
        self::assertStringContainsString('display: none;', $html);
        self::assertStringContainsString('grid-column: 1 / span 4;', $html);
        self::assertStringContainsString('margin-left: .14rem;', $html);
        self::assertStringContainsString('grid-column: 5 / span 4;', $html);
        self::assertStringContainsString('left: -.83rem;', $html);
        self::assertStringContainsString('grid-column: 9 / span 4;', $html);
        self::assertStringContainsString('left: .1rem;', $html);
        self::assertStringContainsString('grid-row: 2;', $html);
        self::assertStringContainsString('grid-row: 3;', $html);
        self::assertStringContainsString('id="pmta-admin-tag-column"', $html);
        self::assertStringContainsString('style="min-height: 32px; height: auto; gap: .25rem; padding: .25rem .5rem;"', $html);
        self::assertStringContainsString('class="custom-control custom-radio custom-control-inline"', $html);
        self::assertStringContainsString('class="form-group text-center" id="pmta-admin-action-row" role="group" aria-label="PMTA queue actions"', $html);
        self::assertStringContainsString('class="row" id="pmta-admin-schedule-row"', $html);
        self::assertStringContainsString('class="col-sm-12 col-md-6 pmta-admin-schedule-column"', $html);
        self::assertStringContainsString('class="col-sm-12 col-md-6 pmta-admin-restart-column"', $html);
        self::assertStringNotContainsString('flex: 0 0 242px', $html);
        self::assertStringNotContainsString('flex: 0 0 333px', $html);
        self::assertStringNotContainsString('flex: 0 0 732px', $html);
        self::assertStringContainsString('<fieldset class="form-group border-0 p-0">', $html);
        self::assertStringContainsString('<legend class="sr-only">Queue:</legend>', $html);
        self::assertStringContainsString('<span class="font-weight-bold mr-2" aria-hidden="true">Queue:</span>', $html);
        self::assertStringContainsString('style="padding: 1rem 1rem 1px !important"', $html);
        self::assertStringNotContainsString('id="pmta-admin-target-row"', $html);

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $controls = $xpath->query('//*[@id="pmta-admin-controls"]')->item(0);
        self::assertNotFalse($controls);
        foreach (['pmta-admin-tag-column', 'pmta-admin-action-row', 'pmta-admin-schedule-row', 'pmta-admin-results-column'] as $columnId) {
            $parent = $xpath->query('//*[@id="'.$columnId.'"]/parent::*')->item(0);
            self::assertNotFalse($parent, "Expected {$columnId} to be rendered inside the centered DMS PMTA control column.");
            self::assertSame('pmta-admin-controls', $parent->attributes?->getNamedItem('id')?->nodeValue);
        }
        self::assertSame('pmta-admin-fields-row', $controls->parentNode?->attributes?->getNamedItem('id')?->nodeValue);
    }

    public function test_unowned_domain_with_stale_https_state_uses_the_ip_http_monitor_proxy(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $server->forceFill(['install_parameters' => [
            'https' => true,
            'pmta_monitor_https' => true,
            'data' => [[
                'main' => true,
                'domain' => 'external.example.test',
                'is_our' => false,
            ]],
        ]])->save();
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);
        $monitorProxyUrl = route('clean.console.pmta-admin.monitor', $server->getKey(), false);

        $this->actingAs($owner)
            ->get(route('clean.console.pmta-admin.index', ['server_ids' => [$server->getKey()]]))
            ->assertOk()
            ->assertSee('data-panel-url="http://203.0.113.40:2447/"', false)
            ->assertSee('data-monitor-url="'.$monitorProxyUrl.'"', false)
            ->assertSee('data-monitor-proxied="true"', false)
            ->assertSee('The embedded view uses AlphaLink’s authenticated HTTPS proxy', false)
            ->assertDontSee('https://external.example.test:2447/', false);

        Http::fake([
            'http://203.0.113.40:2447/' => Http::response('<!doctype html><html><body>External-domain monitor</body></html>'),
        ]);

        $this->actingAs($owner)
            ->get(route('clean.console.pmta-admin.monitor', $server->getKey()))
            ->assertOk()
            ->assertSee('External-domain monitor');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://203.0.113.40:2447/');
    }

    public function test_pmta_page_card_headers_and_scheduler_spacing_match_dms(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);

        $this->actingAs($owner)->get(route('console.pmta-scheduler.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('class="block" id="pmta-scheduler"', false)
            ->assertSee('<h3 class="block-title">pmta scheduler</h3>', false)
            ->assertSee('aria-labelledby="pmta-schedule-queue-label"', false)
            ->assertSee('name="pmta-schedule-queue" value="domain" checked', false)
            ->assertSee('name="pmta-schedule-queue" value="campaign"', false)
            ->assertDontSee('id="pmta-schedule-queue"', false)
            ->assertSee('class="block-header"', false)
            ->assertDontSee('class="block-header block-header-default"', false)
            ->assertSee('class="block-content"', false)
            ->assertDontSee('block-content-full', false)
            ->assertSee('<hr>', false)
            ->assertDontSee('Select the */* tag for commands that target all queues.')
            ->assertDontSee('<"dms-table-tools"B>frtip', false)
            ->assertSee('class="sr-only" id="pmta-schedule-status"', false);

        $this->actingAs($owner)->get(route('console.pmta-settings.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('<h3 class="block-title">Pmta Settings</h3>', false)
            ->assertSee('class="block-header"', false)
            ->assertDontSee('class="block-header block-header-default"', false)
            ->assertSee('id="pmta-settings-reload-servers"', false)
            ->assertDontSee('pmta-settings-search-servers');

        $this->actingAs($owner)->get(route('console.pmta-vmta.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('class="block-header block-header-default"><h3 class="block-title">Pmta VMTA 2', false)
            ->assertSee('class="block-header"><h3 class="block-title">Pmta VMTA</h3>', false)
            ->assertSee('class="block-header block-header-default"><h3 class="block-title">MY VMTA', false)
            ->assertSee('>Ips: <', false)
            ->assertSee('title="Select Ips"', false)
            ->assertSee('data-restore-v2-url=', false)
            ->assertSee('ip_ids:ipIds', false)
            ->assertSee('server_id:serverId', false)
            ->assertSee('placeholder="Domains"', false)
            ->assertDontSee('One line per VMTA: IP,host-name.')
            ->assertDontSee('One line per domain. If ROUTE is enabled')
            ->assertDontSee('max="100"', false)
            ->assertSee("domainInput.placeholder=routeToggle.checked?'Domain,route':'Domain';", false);

        $this->actingAs($owner)->get(route('console.pmta-smtp.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('class="block-header"><h3 class="block-title">Pmta SMTP', false)
            ->assertSee('class="block-header block-header-default"><h3 class="block-title">MY SMTP', false)
            ->assertSee('>Ips: <button', false)
            ->assertSee('title="Search Servers" aria-label="Search IPs"', false)
            ->assertSee('title="Select Ips"', false)
            ->assertSee('<label class="custom-control-label" for="pmta-smtp-gscript">GSCRIPT</label><button', false)
            ->assertDontSee('pmta-smtp-gscript-note');

        $this->actingAs($owner)->get(route('console.pmta-errors.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Pmta Bounces');

        $this->actingAs($owner)->get(route('console.pmta-config-history.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('<h3 class="block-title">Pmta Config History</h3>', false)
            ->assertSee('class="table table-sm table-hover"', false)
            ->assertDontSee('table-striped');

        $this->actingAs($owner)->get(route('console.pmta-admin.history', $workspace->getKey()))
            ->assertOk()
            ->assertSee('<h3 class="block-title">Pmta Action History</h3>', false)
            ->assertSee('<label class="sr-only" for="pmta-history-server">Servers:</label>', false)
            ->assertSee('class="table table-sm table-hover"', false)
            ->assertDontSee('table-striped');
    }

    public function test_config_history_uses_dms_filter_table_and_workspace_scoped_config_reveal(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        $server = $this->server($workspace, $owner);
        $otherServer = $this->server($workspace, $owner, '203.0.113.80');
        $record = PmtaConfigHistory::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'actor_user_id' => $owner->getKey(),
            'file' => 'domains', 'action' => 'edit', 'contents' => "synthetic configuration fixture\n", 'result' => 'Saved.', 'succeeded' => true,
        ]);
        PmtaConfigHistory::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $otherServer->getKey(), 'actor_user_id' => $owner->getKey(),
            'file' => 'vmta_1', 'action' => 'push', 'contents' => "another synthetic configuration fixture\n", 'result' => 'Pushed.', 'succeeded' => true,
        ]);

        $this->actingAs($member)->get(route('console.pmta-config-history.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('id="pmta-config-history-filter"', false)
            ->assertSee('id="pmta-config-history-table"', false)
            ->assertSee('SEE CONFIG')
            ->assertSee('data-action="fullscreen_toggle"', false);

        $historyUrl = route('console.pmta-config-history.index', [
            'workspace' => $workspace->getKey(), 'draw' => 7, 'start' => 0, 'length' => 1,
            'server' => $server->getKey(), 'order' => [['column' => 0, 'dir' => 'desc']],
        ]);
        $response = $this->actingAs($member)->getJson($historyUrl)
            ->assertOk()
            ->assertJsonPath('draw', 7)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $record->getKey())
            ->assertJsonPath('data.0.has_config', true)
            ->assertJsonMissingPath('data.0.contents');
        self::assertStringNotContainsString('synthetic configuration fixture', (string) $response->getContent());

        $searchUrl = route('console.pmta-config-history.index', [
            'workspace' => $workspace->getKey(), 'draw' => 8, 'start' => 0, 'length' => 50, 'server' => '0',
            'search' => ['value' => 'vmta_1'],
        ]);
        $this->actingAs($member)->getJson($searchUrl)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.file', 'vmta_1');

        $this->actingAs($member)->getJson(route('console.pmta-config-history.contents', [$workspace->getKey(), $record->getKey()]))
            ->assertOk()
            ->assertJsonPath('contents', "synthetic configuration fixture\n");

        $otherWorkspace = $this->workspace($owner);
        $otherWorkspaceServer = $this->server($otherWorkspace, $owner, '203.0.113.81');
        $foreignRecord = PmtaConfigHistory::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'managed_server_id' => $otherWorkspaceServer->getKey(), 'actor_user_id' => $owner->getKey(),
            'file' => 'domains', 'action' => 'edit', 'contents' => 'foreign fixture', 'result' => 'Saved.', 'succeeded' => true,
        ]);
        $this->actingAs($member)->getJson(route('console.pmta-config-history.contents', [$workspace->getKey(), $foreignRecord->getKey()]))
            ->assertNotFound();
    }

    public function test_admin_monitor_grid_sits_flush_below_the_dms_action_block(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $response = $this->actingAs($owner)->get(route('console.pmta-admin.index', [
            'workspace' => $workspace->getKey(),
            'server_ids' => [$server->getKey()],
        ]));
        $response->assertOk()->assertSee('id="pmta-admin-panels"', false)
            ->assertSee('id="pmta-admin-schedule-status"', false)
            ->assertSee('class="col-sm-12 col-md-7" id="pmta-admin-controls"', false)
            ->assertSee('id="pmta-admin-schedule-status" class="small text-muted mb-2" hidden role="status"', false)
            ->assertSee('class="sr-only" for="pmta-admin-seconds"', false)
            ->assertDontSee('Leave blank or enter 0 for one run.');

        $document = new \DOMDocument;
        $previousErrorSetting = libxml_use_internal_errors(true);
        try {
            $document->loadHTML((string) $response->getContent());
            $xpath = new \DOMXPath($document);
            self::assertSame(1, $xpath->query('//*[@id="pmta-admin-panels"]/parent::section[contains(concat(" ", normalize-space(@class), " "), " block ")]')->length);
            self::assertSame(0, $xpath->query('//*[@id="pmta-admin-panels"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " block-content ")]')->length);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }
    }

    public function test_smtp_and_vmta_pages_use_the_dms_form_placement_and_table_controls(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $this->serverAndIp($workspace, $owner);

        $this->actingAs($owner)->get(route('console.pmta-smtp.index', $workspace->getKey()))
            ->assertOk()->assertSee('username,password,route,port,domain*')
            ->assertSee('id="pmta-smtp-search-ips"', false)->assertSee('id="pmta-smtp-copy-script"', false)
            ->assertSee('id="pmta-smtp-result"', false)->assertSee('id="pmta-smtp-table"', false)
            ->assertSee('result.success === false && !Array.isArray(result.results)', false)
            ->assertSee("api(root.dataset.restoreUrl, 'POST', { ip_ids: ipIds })", false)
            ->assertSee("{ title: 'DOMAIN', data: 'dns' }", false)
            ->assertSee('datatables.min.js');
        $this->actingAs($owner)->get(route('console.pmta-vmta.index', $workspace->getKey()))
            ->assertOk()->assertSee('block-mode-hidden', false)->assertSee('id="pmta-vmta-search-ips"', false)
            ->assertSee('id="pmta-vmta-result"', false)->assertSee('id="pmta-vmta-table"', false)
            ->assertSee('ip_ids:ipIds', false)->assertSee('server_id:serverId', false)
            ->assertSee('dms-reference/pmta-vmta-response.js', false)
            ->assertSee('window.AlphaLinkPmtaVmtaResponse', false)
            ->assertDontSee('type="module"', false);
    }

    public function test_settings_page_uses_the_dms_file_tree_editor_and_separate_edit_and_push_controls(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $this->server($workspace, $owner);

        $response = $this->actingAs($owner)->get(route('console.pmta-settings.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('PMTA SETTINGS')
            ->assertSee('<option value="" selected>Select Server</option>', false)
            ->assertSee('id="pmta-settings-holder" hidden', false)
            ->assertSee('id="pmta-settings-open-edit-push"', false)
            ->assertSee('id="pmta-settings-files"', false)
            ->assertSee('id="pmta-settings-contents"', false)
            ->assertSee('jstree.min.js')
            ->assertSee('ace.js')
            ->assertSee('id="pmta-settings-edit"', false)
            ->assertSee('id="pmta-settings-toggle-push"', false)
            ->assertSee('id="pmta-settings-edit-push"', false)
            ->assertSee('id="pmta-settings-restore-domains"', false);

        $html = (string) $response->getContent();
        self::assertLessThan(strpos($html, 'id="pmta-settings-holder"'), strpos($html, 'id="pmta-settings-open-edit-push"'));
    }

    public function test_settings_file_actions_use_the_workspace_remote_adapter_and_encrypt_history(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('listFiles')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))->andReturn(['domains']);
        $remote->shouldReceive('readFile')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)), 'domains')->andReturn("synthetic-domain fixture\n");
        $remote->shouldReceive('writeAndReload')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)), 'domains', 'synthetic-edited fixture')->andReturn('PMTA configuration reloaded.');
        $remote->shouldReceive('pushFile')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)), 'domains', 'synthetic-pushed fixture')->andReturn('PMTA configuration reloaded.');
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($owner)->getJson(route('console.pmta-settings.files', ['workspace' => $workspace->getKey(), 'server_id' => $server->getKey()]))
            ->assertOk()->assertJsonPath('files', ['domains']);
        $this->getJson(route('console.pmta-settings.file', ['workspace' => $workspace->getKey(), 'server_id' => $server->getKey(), 'file' => 'domains']))
            ->assertOk()->assertJsonPath('contents', "synthetic-domain fixture\n");
        $editResponse = $this->postJson(route('console.pmta-settings.edit', $workspace->getKey()), [
            'server_id' => $server->getKey(), 'file' => 'domains', 'contents' => "synthetic-edited fixture\n",
        ]);
        self::assertSame(200, $editResponse->getStatusCode(), (string) $editResponse->getContent());
        $editResponse->assertJsonPath('success', true);
        $this->postJson(route('console.pmta-settings.push', $workspace->getKey()), [
            'server_ids' => [$server->getKey()], 'file' => 'domains', 'contents' => "synthetic-pushed fixture\n",
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('results.0.success', true);

        $history = PmtaConfigHistory::query()->orderBy('id')->get();
        self::assertCount(2, $history);
        self::assertSame('synthetic-edited fixture', $history[0]->contents);
        self::assertSame('edit', $history[0]->action);
        self::assertNotSame('synthetic-edited fixture', $history[0]->getRawOriginal('contents'));
        self::assertSame('synthetic-pushed fixture', $history[1]->contents);
        self::assertSame('push', $history[1]->action);
    }

    public function test_settings_edit_uses_dms_reload_confirmation_and_returns_pmta_debug_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->ordered()
            ->with("[ -f '/etc/pmta/conf/domains' ] && echo yes || echo no")
            ->andReturn('yes');
        $session->shouldReceive('upload')->once()->with('synthetic-edited fixture', '/etc/pmta/conf/domains')->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration RELOADED.');
        $session->shouldReceive('exec')->once()->ordered()->with('pmtad --debug')->andReturn('synthetic PMTA settings diagnostic');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-settings.edit', $workspace->getKey()), [
            'server_id' => $server->getKey(), 'file' => 'domains', 'contents' => 'synthetic-edited fixture',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'synthetic PMTA settings diagnostic');

        $this->assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_settings_push_uses_dms_reload_confirmation_and_returns_pmta_debug_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->ordered()
            ->with("[ -f '/etc/pmta/conf/domains' ] && echo yes || echo no")
            ->andReturn('yes');
        $session->shouldReceive('upload')->once()->with('synthetic-pushed fixture', '/etc/pmta/conf/domains')->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA reload failed.');
        $session->shouldReceive('exec')->once()->ordered()->with('pmtad --debug')->andReturn('synthetic PMTA push diagnostic');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-settings.push', $workspace->getKey()), [
            'server_ids' => [$server->getKey()], 'file' => 'domains', 'contents' => 'synthetic-pushed fixture',
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.result', 'synthetic PMTA push diagnostic');

        $this->assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_settings_push_refuses_to_create_a_missing_dms_configuration_file(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->with("[ -f '/etc/pmta/conf/domains' ] && echo yes || echo no")->andReturn('no');
        $session->shouldNotReceive('upload');
        $session->shouldNotReceive('exec')->with('pmta reload');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-settings.push', $workspace->getKey()), [
            'server_ids' => [$server->getKey()], 'file' => 'domains', 'contents' => 'synthetic-config',
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.result', 'File Not Found');

        $this->assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_pmta_settings_server_refresh_returns_only_installed_workspace_servers(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $installed = $this->server($workspace, $owner);
        $uninstalled = $this->server($workspace, $owner, '203.0.113.80');
        $uninstalled->forceFill(['is_installed' => false])->save();
        $otherWorkspace = $this->workspace($owner);
        $this->server($otherWorkspace, $owner, '203.0.113.81');

        $this->actingAs($owner)->getJson(route('console.pmta-settings.servers', $workspace->getKey()))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $installed->getKey())
            ->assertJsonPath('data.0.name', $installed->name);
    }

    public function test_scheduler_is_workspace_scoped_persistent_and_requires_the_wildcard_queue_tag(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'operator']);
        $server = $this->server($workspace, $owner);
        $url = route('console.pmta-scheduler.store', $workspace->getKey());
        $base = ['server_ids' => [$server->getKey()], 'queue' => 'domain', 'tags' => ['example.test'], 'action' => 'pause', 'frequency' => 'everyFiveMinutes'];

        $this->actingAs($member)->postJson($url, $base)->assertUnprocessable()->assertJsonValidationErrors('tags');
        $this->actingAs($member)->postJson($url, array_replace($base, ['tags' => ['*/*'], 'action' => 'schedule']))
            ->assertOk()->assertJsonPath('success', true);

        $task = PmtaScheduledAction::query()->sole();
        self::assertSame([(string) $server->getKey()], $task->server_ids);
        self::assertSame(['*/*'], $task->tags);
        self::assertSame('schedule', $task->action);

        $this->actingAs($member)->getJson(route('console.pmta-scheduler.data', $workspace->getKey()))
            ->assertOk()->assertJsonPath('data.0.id', $task->getKey())->assertJsonMissingPath('data.0.is_active');

        $this->actingAs($member)->patchJson(route('console.pmta-scheduler.destroy', [$workspace->getKey(), $task->getKey()]), ['is_active' => false])
            ->assertMethodNotAllowed();
        self::assertDatabaseHas('pmta_scheduled_actions', ['id' => $task->getKey(), 'is_active' => true]);
        $this->actingAs($member)->deleteJson(route('console.pmta-scheduler.destroy', [$workspace->getKey(), $task->getKey()]))->assertOk();
        self::assertDatabaseMissing('pmta_scheduled_actions', ['id' => $task->getKey()]);
    }

    public function test_pmta_scheduler_and_settings_push_accept_dms_unbounded_server_and_tag_arrays(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $serverIds = array_merge(
            [(string) $server->getKey()],
            array_map(static fn (int $index): string => (string) Str::uuid(), range(1, 100)),
        );
        $tags = array_merge(['*/*'], array_map(static fn (int $index): string => 'queue-'.$index, range(1, 200)));

        $this->actingAs($owner)->postJson(route('console.pmta-scheduler.store', $workspace->getKey()), [
            'server_ids' => $serverIds,
            'queue' => 'domain',
            'tags' => $tags,
            'action' => 'schedule',
            'frequency' => 'everyFiveMinutes',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.server_ids.0', 'Select active installed servers from this workspace.');

        self::assertDatabaseCount('pmta_scheduled_actions', 0);

        $this->actingAs($owner)->postJson(route('console.pmta-scheduler.store', $workspace->getKey()), [
            'server_ids' => [(string) $server->getKey()],
            'queue' => 'domain',
            'tags' => $tags,
            'action' => 'schedule',
            'frequency' => 'everyFiveMinutes',
        ])->assertOk()->assertJsonPath('success', true);

        self::assertDatabaseCount('pmta_scheduled_actions', 1);
        self::assertCount(201, PmtaScheduledAction::query()->sole()->tags);

        $this->actingAs($owner)->postJson(route('console.pmta-settings.push', $workspace->getKey()), [
            'server_ids' => $serverIds,
            'file' => 'domains',
            'contents' => 'synthetic config',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.server_ids.0', 'Select active installed servers from this workspace.');
    }

    public function test_scheduler_data_returns_all_workspace_tasks_without_the_dms_row_cap(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $otherWorkspace = $this->workspace($owner);
        $tasks = PmtaScheduledAction::factory()->count(1001)->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
        ]);
        $foreignTask = PmtaScheduledAction::factory()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'created_by' => $owner->getKey(),
        ]);

        $response = $this->actingAs($owner)->getJson(route('console.pmta-scheduler.data', $workspace->getKey()))
            ->assertOk()
            ->assertJsonCount(1001, 'data')
            ->assertJsonPath('data.0.id', $tasks->last()->getKey());

        self::assertNotContains($foreignTask->getKey(), array_column($response->json('data'), 'id'));
    }

    public function test_scheduler_data_hides_workspaces_the_user_cannot_access(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $this->workspace($owner);

        $this->actingAs($outsider)
            ->getJson(route('console.pmta-scheduler.data', $workspace->getKey()))
            ->assertNotFound();
    }

    public function test_scheduled_pmta_action_history_records_success_and_skips_failed_commands(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);
        $action = PmtaScheduledAction::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'server_ids' => [(string) $server->getKey()],
            'queue' => 'domain',
            'tags' => ['*/*'],
            'action' => 'schedule',
            'frequency' => 'everyFiveMinutes',
            'is_active' => true,
        ]);

        Http::fakeSequence()
            ->push('<result><succ>scheduled</succ></result>')
            ->push('<result><fail>relay denied</fail></result>');

        $this->artisan('pmta:run-scheduled-action', ['actionId' => $action->getKey()])->assertSuccessful();
        $this->artisan('pmta:run-scheduled-action', ['actionId' => $action->getKey()])->assertSuccessful();

        $history = PmtaActionHistory::query()->sole();
        self::assertSame('schedule', $history->action);
        self::assertSame('scheduled', $history->result);
        self::assertSame($server->getKey(), $history->managed_server_id);
        self::assertSame($owner->getKey(), $history->actor_user_id);
    }

    public function test_scheduled_pmta_action_processes_each_selected_server_with_its_result(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $serverOne = $this->server($workspace, $owner);
        $serverTwo = $this->server($workspace, $owner, '203.0.113.79');
        $serverThree = $this->server($workspace, $owner, '203.0.113.80');
        $serverOne->forceFill(['install_parameters' => ['pmta_version' => 40]])->save();
        $serverTwo->forceFill(['install_parameters' => ['pmta_version' => 45]])->save();
        $serverThree->forceFill(['install_parameters' => ['pmta_version' => 50]])->save();
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);
        $action = PmtaScheduledAction::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'server_ids' => [(string) $serverOne->getKey(), (string) $serverTwo->getKey(), (string) $serverThree->getKey()],
            'queue' => 'domain',
            'tags' => ['*/*'],
            'action' => 'schedule',
            'frequency' => 'everyFiveMinutes',
            'is_active' => true,
        ]);
        Http::fakeSequence()
            ->push('<result><succ>scheduled one</succ></result>')
            ->push('<result><succ>scheduled two</succ></result>')
            ->push('<result><succ>scheduled three</succ></result>');

        $this->artisan('pmta:run-scheduled-action', ['actionId' => $action->getKey()])->assertSuccessful();

        $history = PmtaActionHistory::query()->orderBy('managed_server_id')->get();
        self::assertCount(3, $history);
        self::assertSame([$serverOne->getKey(), $serverTwo->getKey(), $serverThree->getKey()], $history->pluck('managed_server_id')->all());
        self::assertSame(['scheduled one', 'scheduled two', 'scheduled three'], $history->pluck('result')->all());
        $requests = Http::recorded();
        self::assertCount(3, $requests);
        self::assertSame([2447, 2447, 2447], $requests->map(static fn (array $pair): int|false|null => parse_url($pair[0]->url(), PHP_URL_PORT))->all());
    }

    public function test_pmta_action_history_prunable_query_uses_the_dms_one_month_cutoff(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $expired = PmtaActionHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'pause',
            'result' => 'expired fixture',
            'created_at' => now()->subMonth()->startOfDay(),
        ]);
        PmtaActionHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'pause',
            'result' => 'retained fixture',
            'created_at' => now()->subMonth()->addDay(),
        ]);

        self::assertSame([$expired->getKey()], app(PmtaActionHistory::class)->prunable()->pluck('id')->all());
    }

    public function test_pmta_schedule_options_cover_the_dms_scheduler_cadences(): void
    {
        $frequencies = app(PmtaScheduleFrequency::class);
        self::assertSame('*/5 * * * *', $frequencies->expression('everyFiveMinutes'));
        self::assertSame('0 1-23/2 * * *', $frequencies->expression('everyOddHour'));
        self::assertSame('0 0 * * 1-5', $frequencies->expression('weekdays'));
        self::assertSame('0 0 28-31 * *', $frequencies->expression('lastDayOfMonth'));
        self::assertTrue($frequencies->isLastDay('lastDayOfMonth'));
        $this->expectException(\InvalidArgumentException::class);
        $frequencies->expression('unsupported-frequency');
    }

    public function test_persistent_pmta_tasks_do_not_add_an_overlap_lock_to_the_dms_schedule(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $action = PmtaScheduledAction::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'server_ids' => [(string) $server->getKey()],
            'queue' => 'domain',
            'tags' => ['*/*'],
            'action' => 'schedule',
            'frequency' => 'everyFiveMinutes',
            'is_active' => true,
        ]);

        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'pmta-scheduled-action-'.$action->getKey());

        self::assertNotNull($event);
        self::assertFalse($event->withoutOverlapping);
    }

    public function test_pmta_file_catalog_accepts_dms_relative_paths_and_rejects_path_traversal(): void
    {
        $catalog = app(PmtaConfigFileCatalog::class);
        self::assertSame(['domains', 'vmta_15'], $catalog->visibleFiles("domains\n../outside\n/etc/passwd\nnested/domains\nvmta_15\n"));
        self::assertSame('/etc/pmta/conf/domains', $catalog->path('domains'));
        $this->expectException(\InvalidArgumentException::class);
        $catalog->path('../authorized_keys');
    }

    public function test_vmta_push_creates_workspace_rows_and_auditable_config_without_exposing_remote_credentials(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $server->forceFill(['install_parameters' => ['pmta_version' => 45]])->save();

        $temporaryPath = '/etc/pmta/conf/tmp_vmta_'.$member->getKey();
        $configurationPath = '/etc/pmta/conf/vmta_'.$member->getKey();
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('upload')->once()->withArgs(fn (string $contents, string $path): bool => $path === $temporaryPath
            && str_contains($contents, 'smtp-hosts lookup-mx:route.example.test')
            && str_contains($contents, 'vmta-203.0.113.79-101-v_101'))->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('cat '.escapeshellarg($temporaryPath).' >> '.escapeshellarg($configurationPath))->andReturn('');
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('rm -rf '.escapeshellarg($temporaryPath))->andReturn('');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($member)->postJson(route('console.pmta-vmta.push', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()],
            'domains' => "mail.example.test,route.example.test\nmail.example.test,route.example.test",
            'route' => true,
            'duplicate' => 101,
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('results.0.count', 101);

        self::assertSame(101, PmtaVirtualMta::query()->where('workspace_id', $workspace->getKey())->count());
        self::assertSame(['v_1', 'v_101'], [
            PmtaVirtualMta::query()->orderBy('id')->value('name'),
            PmtaVirtualMta::query()->orderByDesc('id')->value('name'),
        ]);
        self::assertSame(101, DB::table('managed_server_mtas')->where('workspace_id', $workspace->getKey())->where('mta_type', 'vmta')->count());
        self::assertDatabaseHas('managed_server_mtas', [
            'pmta_virtual_mta_id' => PmtaVirtualMta::query()->orderBy('id')->value('id'),
            'mta_type' => 'vmta',
            'name' => 'v_1',
            'dns' => 'mail.example.test',
            'user_id' => $member->getKey(),
        ]);
        $pushedVmta = PmtaVirtualMta::query()->where('managed_server_ip_id', $ip->getKey())->where('duplicate_index', 1)->sole();
        $this->actingAs($owner)->getJson(route('console.campaigns.send-options', [
            $workspace->getKey(),
            'server_ids' => [$server->getKey()],
            'route' => 'vmta',
        ]))->assertOk()
            ->assertJsonPath('data.ips.0.name', 'v_1')
            ->assertJsonPath('data.ips.0.pmta_name', 'vmta-'.$ip->ip.'-'.$pushedVmta->getKey().'-v_1')
            ->assertJsonPath('data.ips.0.dns', 'mail.example.test');
        $history = PmtaConfigHistory::query()->sole();
        self::assertTrue($history->succeeded);
        self::assertStringContainsString('vmta-203.0.113.79-1-v_1', $history->contents);
        self::assertStringContainsString('vmta-203.0.113.79-101-v_101', $history->contents);
        self::assertStringNotContainsString('mail.example.test', (string) $history->getRawOriginal('contents'));
    }

    public function test_vmta_push_cleans_up_temporary_config_and_rolls_back_rows_when_validation_reload_fails(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $temporaryPath = '/etc/pmta/conf/tmp_vmta_'.$owner->getKey();
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('upload')->once()->withArgs(fn (string $contents, string $path): bool => $path === $temporaryPath
            && str_contains($contents, 'host-name vmta.example.test'))->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA rejected the temporary configuration.');
        $session->shouldReceive('exec')->once()->ordered()->with('pmtad --debug')->andReturn('synthetic VMTA configuration error');
        $session->shouldReceive('exec')->once()->ordered()->with('rm -rf '.escapeshellarg($temporaryPath))->andReturn('');
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('Cleanup reload attempted.');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-vmta.push', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()], 'domains' => 'vmta.example.test', 'route' => false, 'duplicate' => 1,
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('results.0.message', 'synthetic VMTA configuration error');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
        self::assertDatabaseMissing('managed_server_mtas', [
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'mta_type' => 'vmta',
        ]);
        self::assertDatabaseMissing('pmta_config_histories', [
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(), 'action' => 'vmta_push',
        ]);
    }

    public function test_vmta_v2_push_uses_the_selected_workspace_server_and_saves_auditable_rows(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);

        $temporaryPath = "/etc/pmta/conf/tmp_vmta_{$member->getKey()}";
        $configurationPath = "/etc/pmta/conf/vmta_{$member->getKey()}";
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('upload')->once()->withArgs(fn (string $contents, string $path): bool => $path === $temporaryPath
            && str_contains($contents, 'vmta-203.0.113.79-1-v_1')
            && str_contains($contents, 'host-name vmta.example.test')
        )->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('cat '.escapeshellarg($temporaryPath).' >> '.escapeshellarg($configurationPath))->andReturn('');
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('rm -rf '.escapeshellarg($temporaryPath))->andReturn('');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($member)->postJson(route('console.pmta-vmta.push-rows', $workspace->getKey()), [
            'server_id' => $server->getKey(),
            'rows' => $ip->ip.',vmta.example.test',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', '1 VMTA entry(s) pushed.');

        self::assertDatabaseHas('pmta_virtual_mtas', [
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(),
            'domain' => 'vmta.example.test',
            'name' => 'v_1',
        ]);
        self::assertDatabaseHas('managed_server_mtas', [
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'user_id' => $member->getKey(),
            'mta_type' => 'vmta',
            'name' => 'v_1',
            'dns' => $ip->dns,
        ]);
        $pushedVmta = PmtaVirtualMta::query()->where('managed_server_ip_id', $ip->getKey())->where('duplicate_index', 1)->sole();
        $this->actingAs($owner)->getJson(route('console.campaigns.send-options', [
            $workspace->getKey(),
            'server_ids' => [$server->getKey()],
            'route' => 'vmta',
        ]))->assertOk()
            ->assertJsonPath('data.ips.0.name', 'v_1')
            ->assertJsonPath('data.ips.0.pmta_name', 'vmta-'.$ip->ip.'-'.$pushedVmta->getKey().'-v_1')
            ->assertJsonPath('data.ips.0.dns', $ip->dns);
        self::assertTrue(PmtaConfigHistory::query()->sole()->succeeded);
    }

    public function test_vmta_v2_resolves_the_ip_globally_but_pushes_to_the_selected_server(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        [$selectedServer, $selectedIp] = $this->serverAndIp($workspace, $owner);
        $ipServer = $this->server($workspace, $owner, '203.0.113.80');
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $ipServer->getKey(), 'sending_domain_id' => $selectedIp->sending_domain_id,
            'ip' => '203.0.113.80', 'type' => 'IPV4', 'dns' => 'source.example.test', 'is_active' => true,
        ]);

        $temporaryPath = "/etc/pmta/conf/tmp_vmta_{$owner->getKey()}";
        $configurationPath = "/etc/pmta/conf/vmta_{$owner->getKey()}";
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('upload')->once()->withArgs(fn (string $contents, string $path): bool => $path === $temporaryPath
            && str_contains($contents, 'smtp-source-ip 203.0.113.80')
            && str_contains($contents, 'host-name vmta.example.test')
        )->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('cat '.escapeshellarg($temporaryPath).' >> '.escapeshellarg($configurationPath))->andReturn('');
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA configuration reloaded.');
        $session->shouldReceive('exec')->once()->ordered()->with('rm -rf '.escapeshellarg($temporaryPath))->andReturn('');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($selectedServer, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-vmta.push-rows', $workspace->getKey()), [
            'server_id' => $selectedServer->getKey(),
            'rows' => $ip->ip.',vmta.example.test',
        ])->assertOk()->assertJsonPath('success', true);

        self::assertDatabaseHas('pmta_virtual_mtas', [
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $ipServer->getKey(),
            'managed_server_ip_id' => $ip->getKey(), 'created_by' => $owner->getKey(), 'ip' => $ip->ip,
        ]);
        self::assertDatabaseHas('pmta_config_histories', [
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $selectedServer->getKey(),
            'actor_user_id' => $owner->getKey(), 'action' => 'vmta_v2_push', 'succeeded' => true,
        ]);
    }

    public function test_vmta_v2_reload_failure_runs_dms_debug_cleanup_and_rolls_back_rows(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $temporaryPath = "/etc/pmta/conf/tmp_vmta_{$owner->getKey()}";
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('upload')->once()->withArgs(fn (string $contents, string $path): bool => $path === $temporaryPath
            && str_contains($contents, 'host-name vmta.example.test')
        )->andReturn(true);
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('PMTA reload failed.');
        $session->shouldReceive('exec')->once()->ordered()->with('pmtad --debug')->andReturn('synthetic PMTA config diagnostic');
        $session->shouldReceive('exec')->once()->ordered()->with('rm -rf '.escapeshellarg($temporaryPath))->andReturn('');
        $session->shouldReceive('exec')->once()->ordered()->with('pmta reload')->andReturn('cleanup reload attempted');
        $this->instance(PmtaRemoteConfiguration::class, $this->pmtaRemoteForSession($server, $session));

        $this->actingAs($owner)->postJson(route('console.pmta-vmta.push-rows', $workspace->getKey()), [
            'server_id' => $server->getKey(),
            'rows' => $ip->ip.',vmta.example.test',
        ])->assertUnprocessable()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'synthetic PMTA config diagnostic');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
        self::assertDatabaseMissing('pmta_config_histories', [
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(), 'action' => 'vmta_v2_push',
        ]);
    }

    public function test_vmta_and_smtp_inputs_accept_dms_unbounded_cardinality(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $this->serverAndIp($workspace, $owner);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldNotReceive('open');
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $ipIds = array_merge(range(10000, 10500), [10000]);
        $expectedSelectionError = 'Choose active IPs from installed servers in this workspace.';

        foreach (['console.pmta-vmta.restore', 'console.pmta-smtp.restore'] as $routeName) {
            $this->actingAs($owner)->postJson(route($routeName, $workspace->getKey()), [
                'ip_ids' => $ipIds,
            ])->assertUnprocessable()
                ->assertJsonPath('errors.ip_ids.0', $expectedSelectionError);
        }

        $this->actingAs($owner)->postJson(route('console.pmta-vmta.push', $workspace->getKey()), [
            'ip_ids' => [10000, 10000],
            'domains' => 'example.test',
            'route' => false,
            'duplicate' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.ip_ids.0', $expectedSelectionError);

        $smtpRows = array_map(static fn (int $index): array => [
            'user-'.$index,
            'secret-'.$index,
            'smtp.example.test',
            '587',
            'mail.example.test',
            'ignored-column',
        ], range(1, 201));

        $this->actingAs($owner)->postJson(route('console.pmta-smtp.push', $workspace->getKey()), [
            'ip_ids' => $ipIds,
            'smtp' => $smtpRows,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.ip_ids.0', $expectedSelectionError);
    }

    public function test_vmta_reset_deletes_local_rows_and_ignores_remote_command_results_like_dms(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $row = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'domain' => 'vmta.example.test', 'name' => 'v_1',
            'duplicate_index' => 1, 'route' => false, 'is_active' => true,
        ]);

        $session = Mockery::mock(DmsInstallerSshSession::class);
        $path = escapeshellarg('/etc/pmta/conf/vmta_'.$member->getKey());
        $session->shouldReceive('exec')->once()->with('rm -rf '.$path)->andReturn(false);
        $session->shouldReceive('exec')->once()->with('pmta reload')->andReturn(false);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))->andReturnUsing(function () use ($row, $session) {
            self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $row->getKey()]);

            return $session;
        });
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-vmta.restore', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()],
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('results.0.success', true)
            ->assertJsonPath('results.0.message', 'Restored Successfully');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $row->getKey()]);
        self::assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_vmta_v2_reset_uses_the_selected_server_and_dms_remote_cleanup(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $row = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'domain' => 'vmta.example.test', 'name' => 'v_1',
            'duplicate_index' => 1, 'route' => false, 'is_active' => true,
        ]);

        $session = Mockery::mock(DmsInstallerSshSession::class);
        $path = escapeshellarg('/etc/pmta/conf/vmta_'.$member->getKey());
        $session->shouldReceive('exec')->once()->with('rm -rf '.$path)->andReturn('permission denied');
        $session->shouldReceive('exec')->once()->with('pmta reload')->andReturn(false);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))->andReturnUsing(function () use ($row, $session) {
            self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $row->getKey()]);

            return $session;
        });
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-vmta.restore-v2', $workspace->getKey()), [
            'server_id' => $server->getKey(),
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('results.0.success', true)
            ->assertJsonPath('results.0.message', 'Restored Successfully');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $row->getKey()]);
        self::assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_vmta_reset_keeps_dms_row_deletion_when_remote_connection_fails(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $row = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'domain' => 'vmta.example.test', 'name' => 'v_1',
            'duplicate_index' => 1, 'route' => false, 'is_active' => true,
        ]);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))
            ->andThrow(new \RuntimeException('synthetic SSH connection failure'));
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-vmta.restore', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()],
        ])->assertOk()->assertJsonPath('success', false)->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.message', 'synthetic SSH connection failure');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $row->getKey()]);
        self::assertDatabaseMissing('pmta_config_histories', [
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'actor_user_id' => $member->getKey(), 'action' => 'vmta_restore',
        ]);
    }

    public function test_vmta_table_delete_is_limited_to_the_current_users_selected_rows(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $memberRow = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'domain' => 'member.example.test', 'name' => 'v_1',
            'duplicate_index' => 1, 'route' => false, 'is_active' => true,
        ]);
        $ownerRow = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $owner->getKey(), 'ip' => $ip->ip, 'domain' => 'owner.example.test', 'name' => 'v_2',
            'duplicate_index' => 2, 'route' => false, 'is_active' => true,
        ]);

        $this->actingAs($member)->deleteJson(route('console.pmta-vmta.rows.destroy', $workspace->getKey()), [
            'ids' => [$memberRow->getKey(), $ownerRow->getKey()],
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', '1 VMTA record(s) removed.');

        self::assertDatabaseMissing('pmta_virtual_mtas', ['id' => $memberRow->getKey()]);
        self::assertDatabaseHas('pmta_virtual_mtas', ['id' => $ownerRow->getKey()]);
    }

    public function test_smtp_reset_deletes_rows_before_remote_cleanup_and_ignores_reload_text(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $row = PmtaSmtpConfig::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'dns' => $ip->dns, 'username' => 'fixture-user',
            'password' => 'synthetic-password', 'host' => 'smtp.example.test', 'port' => 587, 'google_script' => false, 'is_active' => true,
        ]);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->with('rm -rf /etc/pmta/conf/smtp_'.$member->getKey())->andReturn('');
        $session->shouldReceive('exec')->once()->with('pmta reload')->andReturn('No reload confirmation returned.');
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))->andReturnUsing(function () use ($row, $session) {
            self::assertDatabaseMissing('pmta_smtp_configs', ['id' => $row->getKey()]);

            return $session;
        });
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-smtp.restore', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()],
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('results.0.success', true)
            ->assertJsonPath('results.0.message', 'Restored Successfully');

        self::assertDatabaseMissing('pmta_smtp_configs', ['id' => $row->getKey()]);
        self::assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_smtp_reset_keeps_dms_row_deletion_when_remote_connection_fails(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $row = PmtaSmtpConfig::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $member->getKey(), 'ip' => $ip->ip, 'dns' => $ip->dns, 'username' => 'fixture-user',
            'password' => 'synthetic-password', 'host' => 'smtp.example.test', 'port' => 587, 'google_script' => false, 'is_active' => true,
        ]);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($subject): bool => $subject->is($server)))
            ->andThrow(new \RuntimeException('synthetic SSH connection failure'));
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-smtp.restore', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()],
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.message', 'synthetic SSH connection failure');

        self::assertDatabaseMissing('pmta_smtp_configs', ['id' => $row->getKey()]);
        self::assertDatabaseCount('pmta_config_histories', 0);
    }

    public function test_smtp_push_keeps_secret_encrypted_and_never_returns_it_from_the_rows_endpoint(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspace($owner);
        $workspace->users()->attach($member->getKey(), ['role' => 'operator']);
        [$server, $ip] = $this->serverAndIp($workspace, $owner);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('listFiles')->once()->andReturn([]);
        $remote->shouldReceive('pushFile')->once()->withArgs(fn ($subject, string $file, string $contents): bool => $subject->is($server) && $file === 'smtp_'.$member->getKey() && str_contains($contents, 'auth-username fixture-user'))
            ->andReturn('PMTA configuration reloaded.');
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($member)->postJson(route('console.pmta-smtp.push', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()], 'smtp' => [
                ['fixture-user', 'synthetic-secret-value', 'smtp.example.test', '587', 'mail.example.test'],
                ['backup-user', 'backup-secret', 'backup.example.test', '2525'],
            ], 'google_script' => false,
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('results.0.count', 2);

        $config = PmtaSmtpConfig::query()->where('username', 'fixture-user')->sole();
        self::assertSame('synthetic-secret-value', $config->password);
        self::assertNotSame('synthetic-secret-value', $config->getRawOriginal('password'));
        self::assertDatabaseHas('pmta_smtp_configs', ['username' => 'backup-user', 'host' => 'backup.example.test', 'port' => 2525]);
        $this->actingAs($member)->getJson(route('console.pmta-smtp.rows', $workspace->getKey()))
            ->assertOk()->assertJsonMissing(['password' => 'synthetic-secret-value']);
    }

    public function test_gscript_submission_fails_closed_when_the_reference_runtime_assets_are_missing(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        [, $ip] = $this->serverAndIp($workspace, $owner);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldNotReceive('open');
        $remote->shouldNotReceive('pushFile');
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($owner)->postJson(route('console.pmta-smtp.push', $workspace->getKey()), [
            'ip_ids' => [$ip->getKey()], 'smtp' => [['fixture-user', 'fixture-token']], 'google_script' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('google_script');

        self::assertDatabaseCount('pmta_smtp_configs', 0);
    }

    public function test_bounce_reader_masks_recipient_and_addresses_in_diagnostics(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->andReturn('hard,2026-10-25 10:00:00,vmta-a,550,recipient bad@example.test,person@example.test');
        $rows = app(PmtaBounceLogReader::class)->read($session, $server, []);

        self::assertCount(1, $rows);
        self::assertSame('******@example.test', $rows[0]['recipient']);
        self::assertSame('recipient (MASKED)', $rows[0]['dns_diag']);
        $command = app(PmtaBounceLogReader::class)->command([]);
        self::assertStringContainsString('/etc/pmta/bounced/archive/'.date('Y-m-d').'/b-*', $command);
        self::assertStringContainsString('/etc/pmta/bounced/process/'.date('Y-m-d').'/b-*', $command);
        self::assertStringContainsString('FPAT = "([^,]*)|(\\"[^\\"]*\\")"', $command);
    }

    public function test_bounce_search_accepts_partial_email_filters_and_returns_every_result(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $server = $this->server($workspace, $owner);
        $line = 'hard,2026-10-25 10:00:00,vmta-a,550,recipient rejected,person@example.test';
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')
            ->once()
            ->withArgs(static fn (string $command): bool => str_contains($command, "email_pattern='person'"))
            ->andReturn(implode("\n", array_fill(0, 2001, $line)));
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->andReturn($session);
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $serverLabel = $server->name.substr((string) $server->getKey(), 0, 8);
        $this->actingAs($owner)
            ->get(route('console.pmta-errors.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee($serverLabel.' | '.$server->ip_address);

        $this->postJson(route('console.pmta-errors.search', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'emails' => ['person'],
        ])
            ->assertOk()
            ->assertJsonCount(2001, 'data')
            ->assertJsonPath('data.0.server', $serverLabel)
            ->assertJsonPath('data.2000.category', 'hard');
    }

    public function test_bounce_search_rejects_servers_outside_the_selected_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner);
        $otherWorkspace = $this->workspace($owner);
        $otherServer = $this->server($otherWorkspace, $owner);
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldNotReceive('open');
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($owner)
            ->postJson(route('console.pmta-errors.search', $workspace->getKey()), [
                'server_ids' => [$otherServer->getKey()],
                'emails' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('server_ids');
    }

    private function workspace(User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'PMTA feature test workspace', 'slug' => 'pmta-'.Str::lower(Str::random(10)), 'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function server(Workspace $workspace, User $actor, string $ipAddress = '203.0.113.78'): ManagedServer
    {
        return $workspace->managedServers()->create([
            'name' => 'PMTA fixture server', 'provider' => 'Synthetic provider', 'ip_address' => $ipAddress, 'ssh_port' => 22,
            'ssh_username' => 'root', 'state' => 'verified', 'expected_host_fingerprint' => 'SHA256:fixture', 'is_active' => true,
            'is_installed' => true, 'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', Str::random(16)),
            'created_by' => $actor->getKey(),
        ]);
    }

    /** @return array{ManagedServer, ManagedServerIp} */
    private function serverAndIp(Workspace $workspace, User $actor): array
    {
        $server = $this->server($workspace, $actor);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'fixture.example.test', 'state' => 'verified',
            'verification_token' => (string) Str::uuid(), 'created_by' => $actor->getKey(),
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(), 'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.79', 'type' => 'IPV4', 'dns' => 'mail.example.test', 'is_active' => true,
        ]);

        return [$server, $ip];
    }

    private function pmtaRemoteForSession(ManagedServer $server, DmsInstallerSshSession $session): PmtaRemoteConfiguration
    {
        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()
            ->with(Mockery::on(fn ($subject): bool => $subject->is($server)))
            ->andReturn(['secret' => 'synthetic-secret', 'type' => 'password']);
        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()
            ->with(Mockery::on(fn ($subject): bool => $subject->is($server)), 'synthetic-secret', 'password')
            ->andReturn($session);

        return new PmtaRemoteConfiguration($credentials, $sessions, new PmtaConfigFileCatalog);
    }
}
