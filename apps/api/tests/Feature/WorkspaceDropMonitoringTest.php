<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceDropMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_page_uses_the_drops_sidebar_and_renders_dms_filter_and_table_controls(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)
            ->get(route('console.drops.monitoring', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Drops Monitoring')
            ->assertSee('Drop Type')
            ->assertSee('FILTER')
            ->assertSee('drops-monitoring-table')
            ->assertSee($this->cleanWorkspaceUrl(route('console.drops.monitoring', $workspace->getKey())), false)
            ->assertSee($this->cleanWorkspaceUrl(route('console.pmta-admin.index', $workspace->getKey())), false)
            ->assertSee('vendor/daterangepicker/daterangepicker.js')
            ->assertSee('drops-monitor.js');
    }

    public function test_monitor_json_filters_processed_rows_and_formats_related_data_like_dms(): void
    {
        $owner = User::factory()->create(['username' => 'Fixture operator']);
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = $workspace->sponsors()->create([
            'name' => 'Fixture sponsor',
            'affiliate_id' => 'fixture-sponsor',
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture',
            'password' => 'fixture-secret',
            'api_key' => 'fixture-api-key',
            'type' => 'cake',
            'allowed_users' => [],
        ]);
        $offer = $workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'OF-42',
            'name' => 'Fixture offer',
            'type' => 'CPA',
            'vertical' => 'testing',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
        ]);
        $isp = Isp::query()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Fixture ISP']);
        $server = $this->server($workspace, $owner, 'Synthetic drop server');
        $uninstalledServer = $this->server($workspace, $owner, 'Synthetic uninstalled Drop server', '203.0.113.151');
        $uninstalledServer->update(['is_installed' => false]);
        $list = $this->audienceList($workspace, $owner, 'Fixture list');
        $today = now()->toDateString();
        $todayStart = Carbon::parse($today)->startOfDay();

        $sent = $this->drop($workspace, $owner, [
            'send_type' => 'send',
            'servers' => [$server->getKey(), $uninstalledServer->getKey()],
            'lists' => [$list->getKey()],
            'filters' => ['age:18+'],
            'countries' => ['US', 'CA'],
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isp_id' => $isp->getKey(),
            'send_status' => 'progress',
            'send_progress' => 17,
            'send_count' => 100,
            'is_scheduled' => true,
            'scheduled_at' => $todayStart->copy()->addHours(18),
            'created_at' => $todayStart->copy()->addHours(12),
        ]);
        $test = $this->drop($workspace, $owner, [
            'send_type' => 'test',
            'servers' => [$server->getKey()],
            'created_at' => $todayStart->copy()->addHours(13),
        ]);
        $this->drop($workspace, $owner, ['created_at' => $todayStart->copy()->subSecond()]);
        $this->drop($workspace, $owner, ['is_processed' => false, 'created_at' => $todayStart->copy()->addHours(14)]);

        $otherUser = User::factory()->create();
        $otherWorkspace = $this->workspace($otherUser, 'owner');
        $this->drop($otherWorkspace, $otherUser, ['created_at' => $todayStart->copy()->addHours(15)]);

        $response = $this->actingAs($owner)->getJson(route('console.drops.monitoring', [
            $workspace->getKey(),
            'from' => $today,
            'to' => $today,
            'drop_type' => 'all',
        ]));

        $response->assertOk()->assertJsonPath('data.0.id', $test->getKey())->assertJsonPath('data.1.id', $sent->getKey())
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_ids.0', (string) $server->getKey())
            ->assertJsonPath('data.1.servers.0', 'Synthetic drop server'.$server->getKey())
            ->assertJsonPath('data.1.server_ids.0', (string) $server->getKey())
            ->assertJsonCount(1, 'data.1.server_ids')
            ->assertJsonPath('data.1.lists.0', 'Fixture list')
            ->assertJsonPath('data.1.offer', '(Fixture sponsor) - (OF-42) - Fixture offer')
            ->assertJsonPath('data.1.isp', 'Fixture ISP')
            ->assertJsonPath('data.1.user', $owner->username)
            ->assertJsonPath('data.1.filters.0', 'age:18+')
            ->assertJsonPath('data.1.countries.1', 'CA')
            ->assertJsonPath('data.1.send_progress', 17)
            ->assertJsonPath('data.1.send_count', 100)
            ->assertJsonPath('data.1.is_scheduled', true);

        $this->actingAs($owner)->getJson(route('console.drops.monitoring', [
            $workspace->getKey(),
            'from' => $today,
            'to' => $today,
            'drop_type' => 'send',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sent->getKey());
    }

    public function test_operator_monitoring_is_limited_to_owned_drops_and_progress_endpoint_checks_ownership(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $today = now()->toDateString();
        $ownDrop = $this->drop($workspace, $operator, ['send_status' => 'progress', 'send_count' => 4]);
        $otherDrop = $this->drop($workspace, $otherOperator, ['send_status' => 'progress', 'send_count' => 9]);

        $this->actingAs($operator)
            ->getJson(route('console.drops.monitoring', [$workspace->getKey(), 'from' => $today, 'to' => $today]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownDrop->getKey());

        $this->actingAs($operator)
            ->getJson(route('console.drops.monitor', [$workspace->getKey(), $ownDrop->getKey()]))
            ->assertOk()->assertJsonPath('id', $ownDrop->getKey())->assertJsonPath('send_status', 'progress');

        $this->actingAs($operator)
            ->getJson(route('console.drops.monitor', [$workspace->getKey(), $otherDrop->getKey()]))
            ->assertNotFound();
    }

    public function test_owner_can_monitor_workspace_drops_but_other_workspaces_remain_isolated(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $secondUser = User::factory()->create();
        $otherWorkspace = $this->workspace($secondUser, 'owner');
        $workspaceDrop = $this->drop($workspace, $secondUser, ['send_status' => 'paused', 'send_progress' => 2]);
        $foreignDrop = $this->drop($otherWorkspace, $secondUser, ['send_status' => 'progress']);

        $this->actingAs($owner)
            ->getJson(route('console.drops.monitor', [$workspace->getKey(), $workspaceDrop->getKey()]))
            ->assertOk()->assertJsonPath('id', $workspaceDrop->getKey())->assertJsonPath('send_progress', 2);

        $this->actingAs($owner)
            ->getJson(route('console.drops.monitor', [$workspace->getKey(), $foreignDrop->getKey()]))
            ->assertNotFound();
    }

    public function test_monitor_filters_reject_invalid_types_and_reversed_date_ranges(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)
            ->getJson(route('console.drops.monitoring', [$workspace->getKey(), 'drop_type' => 'unknown']))
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->getJson(route('console.drops.monitoring', [
                $workspace->getKey(),
                'from' => now()->addDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_drop_stats_match_dms_aggregates_and_return_zeroes_for_empty_drops(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $drop = $this->drop($workspace, $owner);
        $emptyDrop = $this->drop($workspace, $owner);

        foreach ([
            ['type' => 'open', 'revenue' => 0],
            ['type' => 'click', 'revenue' => 0],
            ['type' => 'lead', 'revenue' => 42.5],
            ['type' => 'lead', 'revenue' => 5],
            ['type' => 'unsubscribe', 'revenue' => 0],
            ['type' => 'bot', 'revenue' => 0],
        ] as $event) {
            WorkspaceDropVisitor::factory()->forDrop($drop)->create($event);
        }

        $this->actingAs($owner)
            ->getJson(route('console.drops.stats', [$workspace->getKey(), $drop->getKey()]))
            ->assertExactJson([
                'open' => 1,
                'click' => 1,
                'count_lead' => 2,
                'lead' => 47.5,
                'unsubscribe' => 1,
                'bot' => 1,
            ]);

        $this->actingAs($owner)
            ->getJson(route('console.drops.stats', [$workspace->getKey(), $emptyDrop->getKey()]))
            ->assertExactJson([
                'open' => 0,
                'click' => 0,
                'count_lead' => 0,
                'lead' => 0,
                'unsubscribe' => 0,
                'bot' => 0,
            ]);
    }

    public function test_drop_stats_and_visitors_require_drop_ownership_for_operators(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $ownDrop = $this->drop($workspace, $operator);
        $otherDrop = $this->drop($workspace, $otherOperator);

        WorkspaceDropVisitor::factory()->forDrop($ownDrop)->create(['type' => 'open']);

        $this->actingAs($operator)
            ->getJson(route('console.drops.stats', [$workspace->getKey(), $ownDrop->getKey()]))
            ->assertOk()->assertJsonPath('open', 1);

        $this->actingAs($operator)
            ->getJson(route('console.drops.stats', [$workspace->getKey(), $otherDrop->getKey()]))
            ->assertNotFound();

        $this->actingAs($operator)
            ->get(route('console.drops.visitors', [$workspace->getKey(), $otherDrop->getKey()]))
            ->assertNotFound();
    }

    public function test_visitors_page_renders_dms_map_table_and_local_controls(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $drop = $this->drop($workspace, $owner);

        $this->actingAs($owner)
            ->get(route('console.drops.visitors', [$workspace->getKey(), $drop->getKey()]))
            ->assertOk()
            ->assertSee('DROP')
            ->assertSee((string) $drop->getKey())
            ->assertSee('VISITORS')
            ->assertSee('MAP')
            ->assertSee('drop-visitors-table')
            ->assertSee($this->cleanWorkspaceUrl(route('console.drops.visitors', [$workspace->getKey(), $drop->getKey()])), false)
            ->assertSee('dms-reference/drops-visitors.js')
            ->assertSee('gstatic.com/charts/loader.js')
            ->assertSee("style-src 'self' 'unsafe-inline' https://www.gstatic.com", false);
    }

    public function test_visitors_endpoint_returns_dms_datatables_shape_search_order_and_paging(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $drop = $this->drop($workspace, $owner);
        $older = WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'type' => 'open', 'ip' => '192.0.2.41', 'country' => 'United States', 'email_id' => 4101,
        ]);
        $middle = WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'type' => 'click', 'ip' => '192.0.2.41', 'country' => 'United States', 'email_id' => 4102,
        ]);
        $newest = WorkspaceDropVisitor::factory()->forDrop($drop)->create([
            'type' => 'lead', 'revenue' => 3.25, 'ip' => '192.0.2.42', 'country' => 'Canada', 'email_id' => 4201,
        ]);

        $query = http_build_query([
            'draw' => 7,
            'start' => 0,
            'length' => 20,
            'search' => ['value' => '192.0.2.41'],
            'order' => [0 => ['column' => 0, 'dir' => 'desc']],
        ]);
        $this->actingAs($owner)
            ->getJson(route('console.drops.visitors', [$workspace->getKey(), $drop->getKey()]).'?'.$query)
            ->assertOk()->assertJsonPath('draw', 7)->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $middle->getKey())
            ->assertJsonPath('data.0.type', 'click')
            ->assertJsonPath('data.0.ip', '192.0.2.41')
            ->assertJsonPath('data.1.id', $older->getKey());

        $pageQuery = http_build_query([
            'draw' => 8,
            'start' => 1,
            'length' => 20,
            'order' => [0 => ['column' => 0, 'dir' => 'desc']],
        ]);
        $this->actingAs($owner)
            ->getJson(route('console.drops.visitors', [$workspace->getKey(), $drop->getKey()]).'?'.$pageQuery)
            ->assertOk()->assertJsonPath('recordsTotal', 3)->assertJsonPath('recordsFiltered', 3)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $middle->getKey())
            ->assertJsonPath('data.1.id', $older->getKey());

        self::assertNotSame($newest->getKey(), $middle->getKey());
    }

    public function test_visitors_chart_groups_only_supported_event_counts_by_country_and_drop(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $drop = $this->drop($workspace, $owner);
        $otherDrop = $this->drop($workspace, $owner);

        foreach ([
            ['type' => 'open', 'country' => 'United States'],
            ['type' => 'open', 'country' => 'United States'],
            ['type' => 'click', 'country' => 'United States'],
            ['type' => 'lead', 'country' => 'United States'],
            ['type' => 'unsubscribe', 'country' => 'United States'],
            ['type' => 'bot', 'country' => 'United States'],
            ['type' => 'click', 'country' => 'Canada'],
        ] as $event) {
            WorkspaceDropVisitor::factory()->forDrop($drop)->create($event);
        }
        WorkspaceDropVisitor::factory()->forDrop($otherDrop)->create(['type' => 'open', 'country' => 'Canada']);

        $this->actingAs($owner)
            ->getJson(route('console.drops.visitors', [$workspace->getKey(), $drop->getKey()]).'?chart=1')
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.country', 'Canada')->assertJsonPath('0.open', 0)
            ->assertJsonPath('0.click', 1)->assertJsonPath('0.lead', 0)->assertJsonPath('0.unsubscribe', 0)
            ->assertJsonPath('1.country', 'United States')->assertJsonPath('1.open', 2)
            ->assertJsonPath('1.click', 1)->assertJsonPath('1.lead', 1)->assertJsonPath('1.unsubscribe', 1);
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Drop monitor workspace',
            'slug' => 'drop-monitor-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function server(Workspace $workspace, User $owner, string $name, string $ipAddress = '203.0.113.150'): ManagedServer
    {
        return $workspace->managedServers()->create([
            'name' => $name,
            'provider' => 'Synthetic provider',
            'ip_address' => $ipAddress,
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
            'created_by' => $owner->getKey(),
        ]);
    }

    private function audienceList(Workspace $workspace, User $owner, string $name): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function drop(Workspace $workspace, User $user, array $attributes = []): WorkspaceDrop
    {
        return WorkspaceDrop::factory()->forWorkspace($workspace, $user)->create($attributes);
    }
}
