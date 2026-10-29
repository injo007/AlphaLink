<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Campaigns\PmtaPanelUrl;
use PHPUnit\Framework\TestCase;

class PmtaPanelUrlTest extends TestCase
{
    public function test_https_server_uses_its_installed_primary_domain_and_saved_port(): void
    {
        $server = (new ManagedServer)->forceFill([
            'ip_address' => '203.0.113.40',
            'install_parameters' => [
                'https' => true,
                'pmta_monitor_https' => true,
                'data' => [
                    ['main' => false, 'domain' => 'alias.example.test'],
                    ['main' => true, 'domain' => 'sender.example.test'],
                ],
            ],
        ]);

        $this->assertSame('https://sender.example.test:2447/', PmtaPanelUrl::forServer($server, 2447));
    }

    public function test_http_server_uses_the_public_ip_and_ipv6_brackets(): void
    {
        $server = (new ManagedServer)->forceFill([
            'ip_address' => '2001:db8::40',
            'install_parameters' => ['https' => false],
        ]);

        $this->assertSame('http://[2001:db8::40]:2447/', PmtaPanelUrl::forServer($server, 2447));
    }

    public function test_legacy_general_https_flag_does_not_claim_pmta_tls_without_verification(): void
    {
        $server = (new ManagedServer)->forceFill([
            'ip_address' => '203.0.113.40',
            'install_parameters' => [
                'https' => true,
                'data' => [['main' => true, 'domain' => 'sender.example.test']],
            ],
        ]);

        $this->assertSame('http://203.0.113.40:2447/', PmtaPanelUrl::forServer($server, 2447));
    }

    public function test_external_domain_without_verified_pmta_tls_uses_the_public_ip_over_http(): void
    {
        $server = (new ManagedServer)->forceFill([
            'ip_address' => '203.0.113.40',
            'install_parameters' => [
                'https' => true,
                'pmta_monitor_https' => false,
                'data' => [['main' => true, 'domain' => 'external.example.test']],
            ],
        ]);

        $this->assertSame('http://203.0.113.40:2447/', PmtaPanelUrl::forServer($server, 2447));
    }

    public function test_unowned_main_domain_cannot_enable_a_stale_pmta_https_setting(): void
    {
        $server = (new ManagedServer)->forceFill([
            'ip_address' => '203.0.113.40',
            'install_parameters' => [
                'https' => true,
                'pmta_monitor_https' => true,
                'data' => [[
                    'main' => true,
                    'domain' => 'external.example.test',
                    'is_our' => false,
                ]],
            ],
        ]);

        $this->assertSame('http://203.0.113.40:2447/', PmtaPanelUrl::forServer($server, 2447));
    }

    public function test_https_is_not_claimed_without_a_valid_certificate_hostname(): void
    {
        $this->assertSame(
            'http://203.0.113.40:2447/',
            PmtaPanelUrl::forInstallation('203.0.113.40', 'bad host', 2447, true),
        );
    }
}
