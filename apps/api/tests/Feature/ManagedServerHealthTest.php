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
        $countryResolver->shouldReceive('countryCode')->once()->with('203.0.113.10')->andReturn('BF');
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
