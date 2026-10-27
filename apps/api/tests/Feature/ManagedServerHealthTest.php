<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerHistory;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\ServerCountryResolver;
use App\Services\Infrastructure\TcpPortProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ManagedServerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_records_dms_service_down_flags_for_active_installed_servers_only(): void
    {
        $server = $this->server('203.0.113.10', sshPort: 2222);
        $this->server('203.0.113.11', active: false);
        $this->server('203.0.113.12', installed: false);

        config([
            'infrastructure.pmta_port' => 1212,
            'infrastructure.probe_timeout_seconds' => 7,
        ]);
        $probe = Mockery::mock(TcpPortProbe::class);
        $probe->shouldReceive('checkMany')->once()
            ->with('203.0.113.10', [2222, 80, 1212], 7)
            ->andReturn([2222 => true, 80 => false, 1212 => true]);
        $countryResolver = Mockery::mock(ServerCountryResolver::class);
        $countryResolver->shouldReceive('countryCode')->once()->with('203.0.113.10', null)->andReturn('BF');
        $this->app->instance(TcpPortProbe::class, $probe);
        $this->app->instance(ServerCountryResolver::class, $countryResolver);

        $this->artisan('servers:collect-health')->assertSuccessful();

        $history = ManagedServerHistory::query()->sole();
        $this->assertSame($server->getKey(), $history->managed_server_id);
        $this->assertSame($server->workspace_id, $history->workspace_id);
        $this->assertFalse($history->is_ssh_down);
        $this->assertTrue($history->is_apache_down);
        $this->assertFalse($history->is_pmta_down);
        $this->assertNotNull($history->created_at);
        $this->assertSame('BF', $server->fresh()->country);
        $this->actingAs(User::query()->findOrFail($server->created_by))
            ->getJson('/app/workspaces/'.$server->workspace_id.'/infrastructure/servers')
            ->assertOk()
            ->assertJsonPath('data.0.country', 'BF');
    }

    public function test_health_collector_uses_the_workspace_pmta_port_saved_in_install_settings(): void
    {
        $server = $this->server('203.0.113.35');
        $server->workspace->infrastructureSettings()->create([
            'pmta_port' => 2526,
            'pmta_admin_port' => 2527,
        ]);
        config([
            'infrastructure.pmta_port' => 1212,
            'infrastructure.probe_timeout_seconds' => 5,
            'infrastructure.maxmind_city_database' => storage_path('app/maxmind/missing-fixture.mmdb'),
        ]);

        $probe = Mockery::mock(TcpPortProbe::class);
        $probe->shouldReceive('checkMany')->once()
            ->with('203.0.113.35', [22, 80, 2526], 5)
            ->andReturn([22 => true, 80 => true, 2526 => false]);
        $this->app->instance(TcpPortProbe::class, $probe);

        $this->artisan('servers:collect-health')->assertSuccessful();

        $this->assertTrue(ManagedServerHistory::query()->sole()->is_pmta_down);
    }

    public function test_country_lookup_caches_successful_ip_api_results_until_the_end_of_the_day(): void
    {
        Cache::flush();
        config(['infrastructure.ip_api_key' => 'fixture-ip-api-key']);
        Http::fake([
            'https://pro.ip-api.com/json/203.0.113.30*' => Http::response([
                'status' => 'success',
                'countryCode' => 'BF',
            ]),
        ]);

        $resolver = new ServerCountryResolver;

        $this->assertSame('BF', $resolver->countryCode('203.0.113.30'));
        $this->assertSame('BF', $resolver->countryCode('203.0.113.30'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pro.ip-api.com/json/203.0.113.30?key=fixture-ip-api-key&fields=197914623');
    }

    public function test_health_collector_prefers_the_workspace_ip_api_setting_over_the_environment_fallback(): void
    {
        Cache::flush();
        $server = $this->server('203.0.113.33');
        $server->workspace->appSettings()->create([
            'settings' => ['ip_api_key' => 'workspace-ip-api-key'],
        ]);
        config([
            'infrastructure.ip_api_key' => 'environment-ip-api-key',
            'infrastructure.pmta_port' => 1212,
            'infrastructure.maxmind_city_database' => storage_path('app/maxmind/missing-fixture.mmdb'),
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/203.0.113.33*' => Http::response([
                'status' => 'success',
                'countryCode' => 'BF',
            ]),
        ]);
        $probe = Mockery::mock(TcpPortProbe::class);
        $probe->shouldReceive('checkMany')->once()->andReturn([22 => true, 80 => true, 1212 => true]);
        $this->app->instance(TcpPortProbe::class, $probe);

        $this->artisan('servers:collect-health')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pro.ip-api.com/json/203.0.113.33?key=workspace-ip-api-key&fields=197914623');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'environment-ip-api-key'));
        $this->assertSame('BF', $server->fresh()->country);
    }

    public function test_explicitly_cleared_workspace_ip_api_key_does_not_fall_back_to_environment_secret(): void
    {
        $server = $this->server('203.0.113.34');
        $server->workspace->appSettings()->create([
            'settings' => ['ip_api_key' => ''],
        ]);
        config([
            'infrastructure.ip_api_key' => 'environment-ip-api-key',
            'infrastructure.pmta_port' => 1212,
            'infrastructure.maxmind_city_database' => storage_path('app/maxmind/missing-fixture.mmdb'),
        ]);
        Http::fake();
        $probe = Mockery::mock(TcpPortProbe::class);
        $probe->shouldReceive('checkMany')->once()->andReturn([22 => true, 80 => true, 1212 => true]);
        $this->app->instance(TcpPortProbe::class, $probe);

        $this->artisan('servers:collect-health')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($server->fresh()->country);
    }

    public function test_country_lookup_without_an_api_key_does_not_contact_the_provider(): void
    {
        config([
            'infrastructure.ip_api_key' => null,
            'infrastructure.maxmind_city_database' => storage_path('app/maxmind/missing-fixture.mmdb'),
        ]);
        Http::fake();

        $this->assertNull((new ServerCountryResolver)->countryCode('203.0.113.31'));
        Http::assertNothingSent();
    }

    public function test_country_lookup_falls_back_after_an_api_error(): void
    {
        config([
            'infrastructure.ip_api_key' => 'fixture-ip-api-key',
            'infrastructure.maxmind_city_database' => storage_path('app/maxmind/missing-fixture.mmdb'),
        ]);
        Http::fake([
            'https://pro.ip-api.com/json/203.0.113.32*' => Http::response([], 503),
        ]);

        $this->assertNull((new ServerCountryResolver)->countryCode('203.0.113.32'));
        Http::assertSentCount(1);
    }

    public function test_tcp_probe_checks_loopback_reachability_without_an_ssh_handshake(): void
    {
        $errorCode = 0;
        $errorMessage = '';
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);

        $address = stream_socket_get_name($listener, false);
        $port = (int) substr((string) strrchr((string) $address, ':'), 1);
        $probe = new TcpPortProbe;

        $this->assertTrue($probe->checkMany('127.0.0.1', [$port], 1)[$port]);
        fclose($listener);
        $this->assertFalse($probe->checkMany('127.0.0.1', [$port], 1)[$port]);
        $this->assertFalse($probe->checkMany('not-an-ip', [$port], 1)[$port]);
    }

    public function test_history_pruning_uses_the_dms_three_month_cutoff(): void
    {
        $server = $this->server('203.0.113.20');
        $old = $server->histories()->create([
            'workspace_id' => $server->workspace_id,
        ]);
        $old->created_at = now()->subMonths(3)->subDay();
        $old->saveQuietly();
        $recent = $server->histories()->create([
            'workspace_id' => $server->workspace_id,
        ]);
        $recent->created_at = now()->subMonths(3)->addDay();
        $recent->saveQuietly();

        $this->artisan('model:prune', ['--model' => ManagedServerHistory::class])->assertSuccessful();

        $this->assertDatabaseMissing('managed_server_histories', ['id' => $old->id]);
        $this->assertDatabaseHas('managed_server_histories', ['id' => $recent->id]);
    }

    private function server(
        string $ip,
        bool $active = true,
        bool $installed = true,
        int $sshPort = 22,
    ): ManagedServer {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Health fixture',
            'slug' => 'health-'.str_replace('.', '-', $ip),
            'created_by' => $user->id,
        ]);
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        return $workspace->managedServers()->create([
            'name' => 'HEALTH FIXTURE',
            'provider' => 'Fixture provider',
            'ip_address' => $ip,
            'ssh_port' => $sshPort,
            'ssh_username' => 'fixture',
            'is_active' => $active,
            'is_installed' => $installed,
            'idempotency_key' => 'fixture-'.$ip,
            'request_fingerprint' => hash('sha256', $ip),
            'created_by' => $user->id,
        ]);
    }
}
