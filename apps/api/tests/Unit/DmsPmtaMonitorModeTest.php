<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsPmtaMonitorMode;
use PHPUnit\Framework\TestCase;

class DmsPmtaMonitorModeTest extends TestCase
{
    public function test_pmta_45_and_50_use_nginx_for_public_ip_fallback(): void
    {
        self::assertFalse(DmsPmtaMonitorMode::usesDirectListener(45, false));
        self::assertFalse(DmsPmtaMonitorMode::usesDirectListener(50, false));
    }

    public function test_pmta_40_keeps_its_existing_direct_listener_fallback(): void
    {
        self::assertTrue(DmsPmtaMonitorMode::usesDirectListener(40, false));
    }

    public function test_provider_domains_and_unselected_pmta_never_use_direct_listener(): void
    {
        self::assertFalse(DmsPmtaMonitorMode::usesDirectListener(40, true));
        self::assertFalse(DmsPmtaMonitorMode::usesDirectListener(45, false, false));
    }
}
