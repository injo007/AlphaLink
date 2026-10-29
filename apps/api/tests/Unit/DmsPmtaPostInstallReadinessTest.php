<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsPmtaPostInstallReadiness;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsPmtaPostInstallReadinessTest extends TestCase
{
    public function test_proxy_http_fallback_keeps_the_public_port_and_isolates_the_doubled_backend(): void
    {
        $command = (new DmsPmtaPostInstallReadiness)->command(45, [
            'pmta_admin_port' => 1213,
            'pmta_relay_ips' => ['198.51.100.44'],
            'dev_ips' => [],
            'production_ips' => [],
            'allow_all_monitor' => true,
        ]);

        self::assertStringContainsString('proxy_pass http://127.0.0.1:2426;', $command);
        self::assertStringContainsString("'http://127.0.0.1:2426/'", $command);
        self::assertStringContainsString("'http://127.0.0.1:1213/'", $command);
        self::assertStringContainsString("'listen 1213;'", $command);
        self::assertStringContainsString('ALPHALINK_PMTA_NOT_READY:%s', $command);
        self::assertStringContainsString('pmta-backend-http-', $command);
        self::assertStringContainsString('public-monitor-http-', $command);
        self::assertStringContainsString('for attempt in $(seq 1 20)', $command);
        self::assertStringContainsString('sleep 1', $command);
    }

    public function test_https_uses_the_same_public_port_and_validates_the_certificate_hostname(): void
    {
        $command = (new DmsPmtaPostInstallReadiness)->command(45, [
            'pmta_admin_port' => 1213,
            'pmta_relay_ips' => ['198.51.100.44'],
            'dev_ips' => [],
            'production_ips' => [],
            'allow_all_monitor' => true,
        ], true, 'pmta.example.test');

        self::assertStringContainsString("'listen 1213 ssl;'", $command);
        self::assertStringContainsString("'pmta.example.test:1213:127.0.0.1'", $command);
        self::assertStringContainsString("'https://pmta.example.test:1213/'", $command);
        self::assertStringNotContainsString("'http://127.0.0.1:1213/'", $command);
        self::assertStringContainsString('for attempt in $(seq 1 20)', $command);
    }

    public function test_ip_fallback_probes_pmta_directly_on_the_shared_public_port(): void
    {
        $command = (new DmsPmtaPostInstallReadiness)->command(45, [
            'pmta_admin_port' => 1213,
            'pmta_relay_ips' => ['198.51.100.44'],
            'dev_ips' => ['192.0.2.10'],
            'production_ips' => ['198.51.100.20'],
            'allow_all_monitor' => true,
        ], directMonitor: true);

        self::assertStringContainsString("'http://127.0.0.1:1213/'", $command);
        self::assertStringNotContainsString('2426', $command);
        self::assertStringNotContainsString('nginx-service', $command);
        self::assertStringNotContainsString('monitor-upstream-configuration', $command);
        self::assertStringContainsString('pmta-public-monitor-listener', $command);
        self::assertStringContainsString('pmta-direct-http-', $command);
        self::assertStringNotContainsString('pmta-backend-http-', $command);
        self::assertStringContainsString('http-access 198.51.100.44 admin', $command);
        self::assertStringContainsString('http-access 198.51.100.20 monitor', $command);
        self::assertStringContainsString('http-access 0/0 monitor', $command);
    }

    public function test_ip_fallback_cannot_be_mistaken_for_https(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The direct public-IP monitor cannot use HTTPS without a domain proxy.');

        (new DmsPmtaPostInstallReadiness)->command(45, [
            'pmta_admin_port' => 1213,
            'pmta_relay_ips' => ['198.51.100.44'],
            'dev_ips' => [],
            'production_ips' => [],
            'allow_all_monitor' => true,
        ], true, 'pmta.example.test', true);
    }
}
