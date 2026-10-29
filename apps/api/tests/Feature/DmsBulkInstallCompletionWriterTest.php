<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallCompletionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsBulkInstallCompletionWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private ManagedServer $server;

    private DomainProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Finalization test',
            'slug' => 'finalization-test',
            'created_by' => $this->user->getKey(),
        ]);
        $this->workspace->users()->attach($this->user->getKey(), ['role' => 'owner']);
        $this->provider = DomainProvider::query()->create(['name' => 'Synthetic provider', 'is_active' => true]);
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_TEST_RELAY',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.30',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $this->user->getKey(),
        ]);
    }

    public function test_external_domain_finalization_replaces_old_ip_and_mta_rows_and_marks_server_installed_last(): void
    {
        $domain = $this->domain('external.example.org', false, 'free');
        $oldIp = $this->ip($domain, '203.0.113.99', 'old.example.org');
        ManagedServerMta::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'managed_server_ip_id' => $oldIp->getKey(),
            'ip' => $oldIp->ip,
            'dns' => $oldIp->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'username' => null,
            'is_active' => true,
            'user_id' => $this->user->getKey(),
        ]);
        $dataIps = [
            ['main' => true, 'ip' => '198.51.100.30', 'type' => 'ipv4', 'domain_id' => $domain->getKey(), 'sub' => '', 'domain' => $domain->name],
            ['main' => false, 'ip' => '2001:db8::30', 'type' => 'ipv6', 'domain_id' => $domain->getKey(), 'sub' => 'mail', 'domain' => $domain->name],
        ];
        $options = $this->installOptions();

        $operations = app(DmsBulkInstallCompletionWriter::class)->finalize(
            $this->workspace,
            $this->server,
            $domain,
            $dataIps,
            45,
            $options,
        );

        $this->assertSame([
            'delete_server_mtas',
            'delete_server_ips',
            'insert_server_ip',
            'insert_server_ip',
            'save_installation_config',
            'mark_server_installed',
        ], array_column($operations, 'action'));
        $this->assertDatabaseMissing('managed_server_ips', ['id' => $oldIp->getKey()]);
        $this->assertDatabaseMissing('managed_server_mtas', ['managed_server_id' => $this->server->getKey()]);
        $this->assertSame([
            ['ip' => '198.51.100.30', 'type' => 'ipv4', 'dns' => $domain->name, 'is_active' => true],
            ['ip' => '2001:db8::30', 'type' => 'ipv6', 'dns' => $domain->name, 'is_active' => true],
        ], ManagedServerIp::query()->where('managed_server_id', $this->server->getKey())
            ->orderBy('id')->get(['ip', 'type', 'dns', 'is_active'])->toArray());
        $this->assertSame('free', $domain->fresh()->status);
        $this->assertSame([
            'data' => $dataIps,
            'pmta_version' => 45,
            ...$options,
            'pmta_monitor_https' => true,
        ], $this->server->fresh()->install_parameters);
        $this->assertTrue($this->server->fresh()->is_installed);
    }

    public function test_owned_domain_is_marked_used_and_ip_dns_uses_the_source_subdomain_rule(): void
    {
        $domain = $this->domain('owned.example.org', true, 'new');
        $dataIps = [[
            'main' => false,
            'ip' => '198.51.100.31',
            'type' => 'ipv4',
            'domain_id' => $domain->getKey(),
            'sub' => 'blue',
            'domain' => $domain->name,
        ]];

        $operations = app(DmsBulkInstallCompletionWriter::class)->finalize(
            $this->workspace,
            $this->server,
            $domain,
            $dataIps,
            40,
            $this->installOptions(),
        );

        $this->assertSame('used', $domain->fresh()->status);
        $this->assertDatabaseHas('managed_server_ips', [
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'dns' => 'blue.owned.example.org',
        ]);
        $this->assertSame('mark_domain_used', $operations[3]['action']);
        $lastOperation = end($operations);
        $this->assertSame('mark_server_installed', $lastOperation['action']);
    }

    public function test_single_server_assignments_finalize_each_ip_against_its_selected_domain(): void
    {
        $external = $this->domain('external.example.org', false, 'free');
        $owned = $this->domain('owned.example.org', true, 'new');
        $dataIps = [
            [
                'main' => true,
                'ip' => '198.51.100.32',
                'type' => 'ipv4',
                'domain_id' => (string) $external->getKey(),
                'sub' => '',
                'domain' => $external->name,
                'is_our' => false,
            ],
            [
                'main' => false,
                'ip' => '2001:db8::32',
                'type' => 'ipv6',
                'domain_id' => (string) $owned->getKey(),
                'sub' => 'relay',
                'domain' => $owned->name,
                'is_our' => true,
            ],
        ];

        $operations = app(DmsBulkInstallCompletionWriter::class)->finalize(
            $this->workspace,
            $this->server,
            $external,
            $dataIps,
            45,
            $this->installOptions(),
            [$external, $owned],
        );

        $this->assertDatabaseHas('managed_server_ips', [
            'managed_server_id' => $this->server->getKey(),
            'ip' => '198.51.100.32',
            'sending_domain_id' => $external->getKey(),
            'dns' => 'external.example.org',
        ]);
        $this->assertDatabaseHas('managed_server_ips', [
            'managed_server_id' => $this->server->getKey(),
            'ip' => '2001:db8::32',
            'sending_domain_id' => $owned->getKey(),
            'dns' => 'relay.owned.example.org',
        ]);
        $this->assertSame('free', $external->fresh()->status);
        $this->assertSame('used', $owned->fresh()->status);
        $this->assertSame(2, count(array_filter($operations, fn (array $operation): bool => $operation['action'] === 'insert_server_ip')));
    }

    public function test_finalization_rejects_cross_workspace_records_before_writing(): void
    {
        $domain = $this->domain('external.example.org', false, 'free');
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other finalization workspace',
            'slug' => 'other-finalization-workspace',
            'created_by' => $this->user->getKey(),
        ]);

        try {
            app(DmsBulkInstallCompletionWriter::class)->finalize(
                $otherWorkspace,
                $this->server,
                $domain,
                [],
                40,
                $this->installOptions(),
            );
            $this->fail('Cross-workspace finalization must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Bulk-install finalization records must belong to the same workspace.', $exception->getMessage());
        }

        $this->assertSame(0, ManagedServerIp::query()->count());
        $this->assertFalse($this->server->fresh()->is_installed);
    }

    public function test_finalization_failure_preserves_dms_ordered_partial_writes_and_does_not_mark_server_installed(): void
    {
        $domain = $this->domain('external.example.org', false, 'free');
        $dataIps = [[
            'main' => true,
            'ip' => '198.51.100.32',
            'type' => 'ipv4',
            'domain_id' => $domain->getKey(),
            'sub' => '',
            'domain' => $domain->name,
        ]];
        ManagedServer::saving(function (ManagedServer $server): void {
            if ($server->isDirty('install_parameters')) {
                throw new \RuntimeException('Synthetic config persistence failure.');
            }
        });

        try {
            app(DmsBulkInstallCompletionWriter::class)->finalize(
                $this->workspace,
                $this->server,
                $domain,
                $dataIps,
                45,
                $this->installOptions(),
            );
            $this->fail('The synthetic configuration persistence error should propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic config persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('managed_server_ips', [
            'managed_server_id' => $this->server->getKey(),
            'ip' => '198.51.100.32',
        ]);
        $this->assertNull($this->server->fresh()->install_parameters);
        $this->assertFalse($this->server->fresh()->is_installed);
    }

    private function domain(string $name, bool $isOwned, string $status): SendingDomain
    {
        return SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => 'synthetic-token',
            'created_by' => $this->user->getKey(),
            'domains_provider_id' => $this->provider->getKey(),
            'status' => $status,
            'is_our' => $isOwned,
            'is_active' => true,
        ]);
    }

    private function ip(SendingDomain $domain, string $ip, string $dns): ManagedServerIp
    {
        return ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $ip,
            'type' => 'ipv4',
            'dns' => $dns,
            'is_active' => true,
        ]);
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function installOptions(): array
    {
        return [
            'tracking' => false,
            'proxy' => true,
            'imap' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];
    }
}
