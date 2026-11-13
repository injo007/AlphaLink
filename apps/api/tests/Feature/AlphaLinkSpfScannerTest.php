<?php

namespace Tests\Feature;

use App\Jobs\RunWorkspaceSpfComparison;
use App\Jobs\RunWorkspaceSpfScan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use App\Models\WorkspaceSpfSearchRun;
use App\Services\Spf\AlphaLinkSpfDnsCheckMatcher;
use App\Services\Spf\AlphaLinkSpfScanLauncher;
use App\Services\Spf\AlphaLinkSpfScanner;
use App\Services\Spf\AlphaLinkSpfScanProcessor;
use App\Services\Spf\AlphaLinkSpfSearchRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlphaLinkSpfScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_spf_routes_are_in_the_tools_navigation_and_pages_render(): void
    {
        [$owner, $workspace] = $this->workspace();

        $this->actingAs($owner)->get(route('clean.console.tools.spf.index'))
            ->assertOk()->assertSee('SPF SCANNER')->assertSee('SCAN DOMAINS')->assertSee('LIVE DNS LOOKUP')
            ->assertSee('id="spf-search-results"', false)
            ->assertSee('serverSide: true', false)
            ->assertSee('pageLength: 200', false)
            ->assertSee('console-assets/plugins/datatables/datatables.min.js', false)
            ->assertSee('<th>CIDR</th>', false)
            ->assertSee(route('clean.console.tools.spf.index'), false)
            ->assertSee('aria-current="page"', false);
        $this->actingAs($owner)->get(route('clean.console.tools.spf.domains'))
            ->assertOk()->assertSee('SPF DOMAINS')->assertSee('EXPORT CSV')->assertSee('<th>SPF</th>', false);
    }

    public function test_manual_dns_scan_follows_includes_and_does_not_persist_manual_results(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->app->instance(AlphaLinkSpfScanner::class, new AlphaLinkSpfScanner(static fn (string $domain): array => [
            'records' => match ($domain) {
                'root.example.test' => ['v=spf1 ip4:192.0.2.129/24 include:_spf.example.test -all'],
                '_spf.example.test' => ['v=spf1 ip6:2001:db8::1/64 -all'],
                default => [],
            },
            'error' => false,
        ]));

        $this->actingAs($owner)->postJson(route('console.tools.spf.manual', $workspace->id), [
            'domains' => ['ROOT.example.test', 'root.example.test'],
        ])->assertOk()->assertJsonCount(2)->assertJsonPath('0.root', 'root.example.test')
            ->assertJsonPath('0.domain', 'root.example.test')
            ->assertJsonPath('1.domain', '_spf.example.test');

        $this->assertDatabaseCount('workspace_spf_roots', 0);
    }

    public function test_uploaded_scan_uses_live_status_stop_and_persists_recursive_records(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->app->instance(AlphaLinkSpfScanLauncher::class, new AlphaLinkSpfScanLauncher(static fn (): int => 43210));
        $this->actingAs($owner)->post(route('console.tools.spf.upload', $workspace->id), [
            'file' => UploadedFile::fake()->createWithContent('domains.txt', "root.example.test\ninvalid domain\n"),
            'batch_size' => 20,
            'workers' => 2,
            'skip_existing' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $scan = WorkspaceSpfScan::query()->sole();
        $this->assertSame(WorkspaceSpfScan::STATUS_SCANNING, $scan->status);
        $this->assertSame(43210, $scan->pid);
        $this->assertSame(2, $scan->workers);
        $this->actingAs($owner)->getJson(route('console.tools.spf.scans', $workspace->id))
            ->assertOk()->assertJsonPath('data.0.id', $scan->id)->assertJsonPath('data.0.status', 'scanning');
        $this->actingAs($owner)->postJson(route('console.tools.spf.kill', [$workspace->id, $scan->id]))
            ->assertOk()->assertJsonPath('message', 'Scan cancelled. Active DNS lookups will finish before their workers exit.');
        $this->assertSame(WorkspaceSpfScan::STATUS_KILLED, $scan->fresh()->status);
    }

    public function test_uploaded_scan_is_queued_on_the_dedicated_spf_worker_queue(): void
    {
        [$owner, $workspace] = $this->workspace();
        Queue::fake();

        $this->actingAs($owner)->post(route('console.tools.spf.upload', $workspace->id), [
            'file' => UploadedFile::fake()->createWithContent('domains.txt', "one.example.test\ntwo.example.test\n"),
            'batch_size' => 25,
            'workers' => 2,
            'skip_existing' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $scan = WorkspaceSpfScan::query()->sole();
        $this->assertSame(WorkspaceSpfScan::STATUS_PENDING, $scan->status);
        $this->assertSame('queued', $scan->stage);
        Queue::assertPushed(RunWorkspaceSpfScan::class, fn (RunWorkspaceSpfScan $job): bool => $job->scanId === $scan->id && $job->workers === 2);
    }

    public function test_scan_status_exposes_completed_count_and_worker_health(): void
    {
        [$owner, $workspace] = $this->workspace();
        $scan = WorkspaceSpfScan::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'file_path' => 'spf-scans/domains.txt',
            'status' => WorkspaceSpfScan::STATUS_SCANNING,
            'stage' => 'resolving',
            'current_domain' => 'example.test',
            'total_lines' => 100_000,
            'valid_domains' => 100_000,
            'progress' => 25,
            'batch_size' => 25,
            'workers' => 2,
            'heartbeat_at' => now()->subSeconds(5),
            'progress_at' => now()->subSeconds(5),
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)->getJson(route('console.tools.spf.scans', $workspace->id))
            ->assertOk()
            ->assertJsonPath('data.0.id', $scan->id)
            ->assertJsonPath('data.0.health', 'active')
            ->assertJsonPath('data.0.progress_display', '25 / 100000 (0.03%)')
            ->assertJsonPath('data.0.current_domain', 'example.test');
    }

    public function test_ip_comparison_runs_in_background_and_table_and_export_read_saved_results(): void
    {
        [$owner, $workspace] = $this->workspace();
        foreach (['one.example.test', 'two.example.test'] as $domain) {
            WorkspaceSpfRoot::query()->create([
                'workspace_id' => $workspace->id,
                'domain' => $domain,
                'status' => 'ok',
                'record' => 'v=spf1 ip4:192.0.2.0/24 -all',
                'cidrs' => ['192.0.2.0/24'],
                'hosts' => [['domain' => $domain, 'record' => 'v=spf1 ip4:192.0.2.0/24 -all', 'cidrs' => ['192.0.2.0/24']]],
            ]);
        }
        Queue::fake();

        $this->actingAs($owner)->postJson(route('console.tools.spf.search-runs.create', $workspace->id), [
            'ips' => ['192.0.2.4', '198.51.100.7'],
        ])->assertAccepted()->assertJsonPath('status', 'pending');

        $run = WorkspaceSpfSearchRun::query()->sole();
        Queue::assertPushed(RunWorkspaceSpfComparison::class, fn (RunWorkspaceSpfComparison $job): bool => $job->searchRunId === $run->id);
        app(AlphaLinkSpfSearchRunner::class)->run((int) $run->id);

        $this->assertSame(WorkspaceSpfSearchRun::STATUS_COMPLETE, $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->progress);
        $this->assertSame(2, $run->fresh()->total_matches);
        $this->actingAs($owner)->getJson(route('console.tools.spf.search-runs.results', [$workspace->id, $run->id]).'?draw=1&start=0&length=1')
            ->assertOk()->assertJsonPath('recordsTotal', 2)->assertJsonPath('recordsFiltered', 2)->assertJsonCount(1, 'data');
        $export = $this->actingAs($owner)->get(route('console.tools.spf.search-runs.export', [$workspace->id, $run->id]))
            ->assertOk()->streamedContent();
        $this->assertCount(3, preg_split('/\r?\n/', trim($export)));
        $this->assertStringContainsString('one.example.test', $export);
        $this->assertStringContainsString('two.example.test', $export);
    }

    public function test_scan_worker_stores_workspace_scoped_roots_and_ip_search_matches_cidr(): void
    {
        [$owner, $workspace] = $this->workspace();
        $path = 'spf-scans/domains.txt';
        Storage::disk('local')->put($path, "root.example.test\n");
        $scan = WorkspaceSpfScan::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'file_path' => $path,
            'status' => WorkspaceSpfScan::STATUS_PENDING,
            'batch_size' => 10,
            'workers' => 1,
            'skip_existing' => false,
        ]);
        $this->app->instance(AlphaLinkSpfScanner::class, new AlphaLinkSpfScanner(static fn (string $domain): array => [
            'records' => $domain === 'root.example.test' ? ['v=spf1 ip4:192.0.2.129/24 include:_spf.example.test -all'] : ['v=spf1 ip6:2001:db8::1/64 -all'],
            'error' => false,
        ]));
        $processor = app(AlphaLinkSpfScanProcessor::class);
        $this->assertTrue($processor->prepare((int) $scan->id, 1));
        $this->assertSame(0, $processor->runShard((int) $scan->id, 1, 0));
        $processor->finish((int) $scan->id, true);

        $root = WorkspaceSpfRoot::query()->sole();
        $this->assertSame('192.0.2.0/24', $root->cidrs[0]);
        $this->assertSame(2, $scan->fresh()->total_lines);
        $this->assertSame(1, $scan->fresh()->progress);
        $this->assertSame(WorkspaceSpfScan::STATUS_FINISHED, $scan->fresh()->status);
        $this->actingAs($owner)->postJson(route('console.tools.spf.search', $workspace->id), ['ips' => ['192.0.2.200']])
            ->assertOk()->assertJsonPath('data.0.root', 'root.example.test')
            ->assertJsonPath('data.0.network', '192.0.2.0/24');
    }

    public function test_ip_search_resolves_dynamic_a_mx_exists_and_ptr_mechanisms_and_exports_them(): void
    {
        [$owner, $workspace] = $this->workspace();
        $root = WorkspaceSpfRoot::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'root.example.test',
            'status' => 'ok',
            'record' => 'v=spf1 -all',
            'cidrs' => [],
            'hosts' => [
                ['domain' => 'a.example.test', 'record' => 'v=spf1 a:%{i}.a.dynamic.test -all', 'cidrs' => []],
                ['domain' => 'mx.example.test', 'record' => 'v=spf1 mx:%{i}.mx.dynamic.test -all', 'cidrs' => []],
                ['domain' => 'exists.example.test', 'record' => 'v=spf1 exists:%{i}.exists.dynamic.test -all', 'cidrs' => []],
                ['domain' => 'ptr.example.test', 'record' => 'v=spf1 ptr:example.test -all', 'cidrs' => []],
            ],
        ]);
        $ip = '192.0.2.7';
        $answers = [
            $ip.'.a.dynamic.test' => [DNS_A => [['ip' => $ip]]],
            $ip.'.mx.dynamic.test' => [DNS_MX => [['target' => 'mx-target.example.test.']]],
            'mx-target.example.test' => [DNS_A => [['ip' => $ip]]],
            $ip.'.exists.dynamic.test' => [DNS_A => [['ip' => '198.51.100.9']]],
            $ip => [DNS_PTR => [['target' => 'mail.example.test.']]],
            'mail.example.test' => [DNS_A => [['ip' => $ip]]],
        ];
        $this->app->instance(AlphaLinkSpfDnsCheckMatcher::class, new AlphaLinkSpfDnsCheckMatcher(
            static fn (string $name, int $type): array => $answers[$name][$type] ?? []
        ));

        $this->actingAs($owner)->postJson(route('console.tools.spf.search', $workspace->id), ['ips' => [$ip]])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['matched_ip' => $ip, 'root' => $root->domain, 'include' => 'a.example.test', 'network' => 'a:%{i}.a.dynamic.test'])
            ->assertJsonFragment(['matched_ip' => $ip, 'root' => $root->domain, 'include' => 'mx.example.test', 'network' => 'mx:%{i}.mx.dynamic.test'])
            ->assertJsonFragment(['matched_ip' => $ip, 'root' => $root->domain, 'include' => 'exists.example.test', 'network' => 'exists:%{i}.exists.dynamic.test'])
            ->assertJsonFragment(['matched_ip' => $ip, 'root' => $root->domain, 'include' => 'ptr.example.test', 'network' => 'ptr:example.test']);

        $export = $this->actingAs($owner)->post(route('console.tools.spf.search-export', $workspace->id), ['ips' => [$ip]])
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('a:%{i}.a.dynamic.test', $export);
        $this->assertStringContainsString('mx:%{i}.mx.dynamic.test', $export);
    }

    public function test_ip_search_treats_dns_failures_as_non_matches(): void
    {
        [$owner, $workspace] = $this->workspace();
        WorkspaceSpfRoot::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'root.example.test',
            'status' => 'ok',
            'record' => 'v=spf1 -all',
            'cidrs' => [],
            'hosts' => [['domain' => 'a.example.test', 'record' => 'v=spf1 a:%{i}.dynamic.test -all', 'cidrs' => []]],
        ]);
        $this->app->instance(AlphaLinkSpfDnsCheckMatcher::class, new AlphaLinkSpfDnsCheckMatcher(
            static function (): array {
                throw new \RuntimeException('synthetic DNS lookup failure');
            }
        ));

        $this->actingAs($owner)->postJson(route('console.tools.spf.search', $workspace->id), ['ips' => ['192.0.2.7']])
            ->assertOk()
            ->assertExactJson(['data' => [], 'truncated' => false, 'dns_checks_limited' => false]);
    }

    public function test_ip_search_supports_reference_datatables_prefix_filter_sort_and_pagination(): void
    {
        [$owner, $workspace] = $this->workspace();
        foreach (['alpha-one.example.test', 'alpha-two.example.test', 'beta.example.test'] as $domain) {
            WorkspaceSpfRoot::query()->create([
                'workspace_id' => $workspace->id,
                'domain' => $domain,
                'status' => 'ok',
                'record' => 'v=spf1 ip4:203.0.113.0/24 -all',
                'cidrs' => ['203.0.113.0/24'],
                'hosts' => [['domain' => 'shared.example.test', 'record' => 'v=spf1 ip4:203.0.113.0/24 -all', 'cidrs' => ['203.0.113.0/24']]],
            ]);
        }

        $this->actingAs($owner)->postJson(route('console.tools.spf.search', $workspace->id), [
            'ips' => ['203.0.113.17'],
            'draw' => 8,
            'start' => 1,
            'length' => 1,
            'search' => ['value' => 'alpha'],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ])->assertOk()
            ->assertJsonPath('draw', 8)
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.root', 'alpha-two.example.test')
            ->assertJsonPath('data.0.include', 'shared.example.test')
            ->assertJsonPath('data.0.root_id', WorkspaceSpfRoot::query()->where('domain', 'alpha-two.example.test')->value('id'));
    }

    public function test_populated_domain_browser_filters_sorts_and_paginates_workspace_roots(): void
    {
        [$owner, $workspace] = $this->workspace();
        foreach (['alpha-zeta.example.test', 'alpha-alpha.example.test', 'beta.example.test'] as $domain) {
            WorkspaceSpfRoot::query()->create([
                'workspace_id' => $workspace->id,
                'domain' => $domain,
                'status' => 'ok',
                'record' => 'v=spf1 ip4:203.0.113.0/24 -all',
                'cidrs' => ['203.0.113.0/24'],
                'hosts' => [['domain' => $domain, 'record' => 'v=spf1 ip4:203.0.113.0/24 -all', 'cidrs' => ['203.0.113.0/24']]],
            ]);
        }
        [, $otherWorkspace] = $this->workspace();
        WorkspaceSpfRoot::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'domain' => 'alpha-private.example.test',
            'status' => 'ok',
            'record' => 'v=spf1 -all',
            'cidrs' => [],
            'hosts' => [],
        ]);

        $this->actingAs($owner)->get(route('clean.console.tools.spf.domains', [
            'search' => 'alpha',
            'sort' => 'domain',
            'dir' => 'asc',
            'per_page' => 25,
        ]))->assertOk()
            ->assertSeeInOrder(['alpha-alpha.example.test', 'alpha-zeta.example.test'])
            ->assertDontSee('beta.example.test')
            ->assertDontSee('alpha-private.example.test')
            ->assertSee('name="search" value="alpha"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('VIEW RECORD');
    }

    public function test_populated_root_record_view_and_csv_export_are_workspace_scoped(): void
    {
        [$owner, $workspace] = $this->workspace();
        $root = WorkspaceSpfRoot::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'alpha.example.test',
            'status' => 'ok',
            'record' => 'v=spf1 include:_spf.alpha.example.test -all',
            'cidrs' => ['192.0.2.0/24'],
            'hosts' => [[
                'domain' => '_spf.alpha.example.test',
                'record' => 'v=spf1 ip4:192.0.2.0/24 -all',
                'status' => 'ok',
                'cidrs' => ['192.0.2.0/24'],
            ]],
        ]);
        WorkspaceSpfRoot::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'beta.example.test',
            'status' => 'no_spf',
            'record' => null,
            'cidrs' => [],
            'hosts' => [],
        ]);
        [, $otherWorkspace] = $this->workspace();
        $privateRoot = WorkspaceSpfRoot::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'domain' => 'alpha-private.example.test',
            'status' => 'ok',
            'record' => 'v=spf1 -all',
            'cidrs' => [],
            'hosts' => [],
        ]);

        $this->actingAs($owner)->getJson(route('console.tools.spf.records', [
            'workspace' => $workspace->id,
            'id' => $root->id,
        ]))->assertOk()
            ->assertJsonPath('record', 'v=spf1 include:_spf.alpha.example.test -all')
            ->assertJsonPath('hosts.0.domain', '_spf.alpha.example.test')
            ->assertJsonPath('hosts.0.record', 'v=spf1 ip4:192.0.2.0/24 -all');

        $this->actingAs($owner)->getJson(route('console.tools.spf.records', [
            'workspace' => $workspace->id,
            'id' => $privateRoot->id,
        ]))->assertNotFound();

        $csv = $this->actingAs($owner)->get(route('console.tools.spf.export', [
            'workspace' => $workspace->id,
            'search' => 'alpha',
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('alpha.example.test', $csv);
        $this->assertStringNotContainsString('beta.example.test', $csv);
        $this->assertStringNotContainsString('alpha-private.example.test', $csv);
    }

    public function test_scanner_routes_are_workspace_scoped_and_validate_reference_limits(): void
    {
        [$owner, $workspace] = $this->workspace();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('console.tools.spf.index', $workspace->id))->assertNotFound();
        $this->actingAs($owner)->postJson(route('console.tools.spf.search', $workspace->id), ['ips' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('ips');
        $this->actingAs($owner)->postJson(route('console.tools.spf.manual', $workspace->id), ['domains' => array_fill(0, 51, 'example.test')])
            ->assertUnprocessable()->assertJsonValidationErrors('domains');
    }

    /** @return array{User, Workspace} */
    private function workspace(): array
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = Workspace::query()->create([
            'name' => 'SPF scanner',
            'slug' => 'spf-'.Str::lower(Str::random(10)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return [$owner, $workspace];
    }
}
