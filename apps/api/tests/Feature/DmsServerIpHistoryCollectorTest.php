<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpHistory;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsServerIpHistoryCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ManagedServer $server;

    private SendingDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'IP history test',
            'slug' => 'ip-history-test',
            'created_by' => $owner->getKey(),
        ]);
        $this->workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $provider = DomainProvider::query()->create(['name' => 'IP history provider', 'is_active' => true]);
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'IP_HISTORY_RELAY',
            'provider' => 'IP history provider',
            'ip_address' => '192.0.2.40',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $owner->getKey(),
        ]);
        $this->domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ip-history.example.test',
            'state' => 'verified',
            'verification_token' => 'ip-history-token',
            'created_by' => $owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
    }

    public function test_disabled_collector_refuses_to_send_dns_queries(): void
    {
        config(['infrastructure.ip_history_enabled' => false]);
        Process::fake();
        $ip = $this->createIp();

        $this->artisan('servers:collect-ip-history')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertDatabaseCount('managed_server_ip_histories', 0);
        $this->assertSame(0, $ip->histories()->count());
    }

    public function test_enabled_collector_records_dms_queries_and_values_for_eligible_ipv4_ips(): void
    {
        config(['infrastructure.ip_history_enabled' => true]);
        $ip = $this->createIp();
        $inactiveIp = $this->createIp(['ip' => '192.0.2.42', 'is_active' => false]);
        $commands = [];

        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            $commands[] = $command;
            $query = (string) end($command);
            $output = match (true) {
                in_array('-x', $command, true) => 'Relay.Example.test.',
                str_contains($query, '.zen.spamhaus.org') => '127.0.0.2',
                str_contains($query, '.b.barracudacentral.org') => 'timed out',
                str_contains($query, '.bl.spamcop.net') => '',
                str_contains($query, '.black.junkemailfilter.com') => '127.0.0.4',
                str_contains($query, '.dbl.spamhaus.org') => '127.0.1.2',
                str_contains($query, '.multi.surbl.org') => '',
                str_contains($query, '.score.senderscore.com') => '127.0.0.92',
                default => '',
            };

            return Process::result($output);
        });

        $this->artisan('servers:collect-ip-history')
            ->expectsOutput('Recorded IP history for 1 eligible IPv4 address(es).')
            ->assertExitCode(0);

        $history = $ip->histories()->firstOrFail();
        $this->assertSame($this->workspace->getKey(), $history->workspace_id);
        $this->assertSame('relay.example.test', $history->ptr);
        $this->assertTrue($history->ip_listed_spamhaus);
        $this->assertFalse($history->ip_listed_barracuda);
        $this->assertFalse($history->ip_listed_spamcop);
        $this->assertSame('92', $history->sender_score);
        $this->assertTrue($history->domain_listed_hostkarma);
        $this->assertTrue($history->domain_listed_spamhaus_dbl);
        $this->assertFalse($history->domain_listed_surbl);
        $this->assertSame(0, $inactiveIp->histories()->count());
        $this->assertCount(8, $commands);
        $this->assertContains(['dig', '@1.1.1.1', '+short', '+time=3', '+tries=1', '-x', '192.0.2.41'], $commands);
        $this->assertContains(['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', '41.2.0.192.zen.spamhaus.org'], $commands);
        $this->assertDatabaseCount('managed_server_ip_histories', 1);
    }

    public function test_history_pruning_matches_dms_one_month_retention(): void
    {
        $ip = $this->createIp();
        $expired = ManagedServerIpHistory::factory()->forManagedIp($ip)->create([
            'created_at' => now()->subMonth()->subDay(),
            'updated_at' => now()->subMonth()->subDay(),
        ]);
        $current = ManagedServerIpHistory::factory()->forManagedIp($ip)->create([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->assertSame([$expired->getKey()], app(ManagedServerIpHistory::class)->prunable()->pluck('id')->all());
        $this->assertDatabaseHas('managed_server_ip_histories', ['id' => $current->getKey()]);
    }

    private function createIp(array $overrides = []): ManagedServerIp
    {
        return ManagedServerIp::query()->create(array_replace([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $this->domain->getKey(),
            'ip' => '192.0.2.41',
            'type' => 'ipv4',
            'dns' => 'ip-history.example.test',
            'is_active' => true,
        ], $overrides));
    }
}
