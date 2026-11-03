<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkPmtaMonitorMode;
use PHPUnit\Framework\TestCase;

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
}
