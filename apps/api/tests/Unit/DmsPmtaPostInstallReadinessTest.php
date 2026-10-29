<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsPmtaPostInstallReadiness;
use PHPUnit\Framework\TestCase;

class DmsPmtaPostInstallReadinessTest extends TestCase
{
    public function test_ip_fallback_checks_the_http_backend_and_public_monitor_on_the_same_configured_port(): void
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
}
