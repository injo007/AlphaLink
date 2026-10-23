<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Infrastructure\DmsBulkInstallIpPlanner;
use App\Services\Infrastructure\DmsBulkInstallRemoteIpDiscovery;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\SshHostProbe;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallRemoteIpDiscoveryTest extends TestCase
{
    public function test_it_orders_and_deduplicates_the_global_ip_output_from_dms_command(): void
    {
        $probe = new class("2001:db8::2\n198.51.100.8\n198.51.100.3\n198.51.100.8\n") extends SshHostProbe
        {
            public function __construct(private readonly string $output) {}

            public function discoverGlobalServerIps(ManagedServer $server, string $credential, string $credentialType = 'password'): string
            {
                return $this->output;
            }
        };
        $server = new ManagedServer(['state' => 'verified', 'expected_host_fingerprint' => 'SHA256:internal-pin']);

        $ips = (new DmsBulkInstallRemoteIpDiscovery($probe, new DmsBulkInstallIpPlanner))
            ->discover($server, 'synthetic-secret', 'password');

        $this->assertSame(['198.51.100.3', '198.51.100.8', '2001:db8::2'], $ips);
    }

    public function test_it_aborts_before_host_changes_when_dms_discovers_no_ips(): void
    {
        $probe = new class('') extends SshHostProbe
        {
            public function __construct(private readonly string $output) {}

            public function discoverGlobalServerIps(ManagedServer $server, string $credential, string $credentialType = 'password'): string
            {
                return $this->output;
            }
        };

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('No Ips Found');
        (new DmsBulkInstallRemoteIpDiscovery($probe, new DmsBulkInstallIpPlanner))
            ->discover(new ManagedServer, 'synthetic-secret');
    }

    public function test_it_rejects_invalid_remote_output_instead_of_passing_it_to_host_phases(): void
    {
        $probe = new class("198.51.100.8\nnot-an-ip\n") extends SshHostProbe
        {
            public function __construct(private readonly string $output) {}

            public function discoverGlobalServerIps(ManagedServer $server, string $credential, string $credentialType = 'password'): string
            {
                return $this->output;
            }
        };

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('Server IP discovery returned an invalid address.');
        (new DmsBulkInstallRemoteIpDiscovery($probe, new DmsBulkInstallIpPlanner))
            ->discover(new ManagedServer, 'synthetic-secret');
    }

    public function test_the_production_probe_requires_a_verified_server_without_prompting_for_a_fingerprint(): void
    {
        $server = new ManagedServer(['state' => 'pending']);

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('Verify the server host before installation.');
        (new SshHostProbe)->discoverGlobalServerIps($server, 'synthetic-secret');
    }
}
