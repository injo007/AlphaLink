<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use App\Services\Infrastructure\AlphaLinkPmtaMonitorMode;
use App\Services\Infrastructure\AlphaLinkPmtaMonitorModeResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkPmtaMonitorModeTest extends TestCase
{
    public function test_pmta_45_and_50_use_nginx_for_public_ip_fallback(): void
    {
        self::assertFalse(AlphaLinkPmtaMonitorMode::usesDirectListener(45, false));
        self::assertFalse(AlphaLinkPmtaMonitorMode::usesDirectListener(50, false));
    }

    public function test_pmta_40_keeps_its_existing_direct_listener_fallback(): void
    {
        self::assertTrue(AlphaLinkPmtaMonitorMode::usesDirectListener(40, false));
    }

    public function test_provider_domains_and_unselected_pmta_never_use_direct_listener(): void
    {
        self::assertFalse(AlphaLinkPmtaMonitorMode::usesDirectListener(40, true));
        self::assertFalse(AlphaLinkPmtaMonitorMode::usesDirectListener(45, false, false));
    }

    public function test_change_domain_preserves_the_installed_pmta_40_monitor_topology(): void
    {
        $directCommands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public string $command = '';

            public function exec(string $command): bool|string
            {
                $this->command = $command;

                return 'ALPHALINK_PMTA_DIRECT_MODE';
            }
        };

        self::assertTrue((new AlphaLinkPmtaMonitorModeResolver)->resolve($directCommands, 40, 1213));
        self::assertStringContainsString('test ! -e /etc/nginx', $directCommands->command);
        self::assertStringContainsString('127.0.0.1:1213', $directCommands->command);

        $proxyCommands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public int $calls = 0;

            public function exec(string $command): bool|string
            {
                $this->calls++;

                return 'ALPHALINK_PMTA_PROXY_MODE';
            }
        };

        self::assertFalse((new AlphaLinkPmtaMonitorModeResolver)->resolve($proxyCommands, 40, 1213));
        self::assertSame(1, $proxyCommands->calls);
    }

    public function test_change_domain_stops_before_mutation_when_pmta_40_monitor_mode_is_unknown(): void
    {
        $commands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            public function exec(string $command): bool|string
            {
                return 'ALPHALINK_PMTA_MODE_UNKNOWN';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Change Domain stopped before changing the server');

        (new AlphaLinkPmtaMonitorModeResolver)->resolve($commands, 40, 1213);
    }
}
