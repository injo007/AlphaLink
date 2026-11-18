<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Campaigns\PmtaProxySettings;
use Tests\TestCase;

class PmtaProxySettingsTest extends TestCase
{
    public function test_explicit_proxy_endpoint_takes_precedence_over_the_pmta_relay_source_allowlist(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => '198.51.100.77',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
            'infrastructure.bulk_install_settings.proxy_username' => 'environment-user',
            'infrastructure.bulk_install_settings.proxy_password' => 'environment-password',
            'infrastructure.bulk_install_settings.pmta_relay_ips' => [],
        ]);
        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $workspace->setRelation('infrastructureSettings', (new WorkspaceInfrastructureSettings)->forceFill([
            'pmta_relay_ips' => ['203.0.113.14'],
            'proxy_port' => 8977,
        ]));

        $settings = (new PmtaProxySettings)->forWorkspace($workspace);

        $this->assertSame('198.51.100.77', $settings['host']);
        $this->assertSame(8977, $settings['port']);
        $this->assertSame('environment-user', $settings['username']);
        $this->assertSame('environment-password', $settings['password']);
        $this->assertSame('http://environment-user:environment-password@198.51.100.77:8977', $settings['url']);
    }

    public function test_an_invalid_explicit_proxy_endpoint_does_not_silently_fall_back_to_the_relay_allowlist(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => 'proxy.example.test',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
            'infrastructure.bulk_install_settings.pmta_relay_ips' => [],
        ]);
        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $workspace->setRelation('infrastructureSettings', (new WorkspaceInfrastructureSettings)->forceFill([
            'pmta_relay_ips' => ['203.0.113.14'],
            'proxy_port' => 8977,
        ]));

        $this->assertNull((new PmtaProxySettings)->forWorkspace($workspace));
    }

    public function test_incomplete_proxy_authentication_is_not_treated_as_a_usable_endpoint(): void
    {
        config([
            'infrastructure.bulk_install_settings.proxy_host' => '198.51.100.77',
            'infrastructure.bulk_install_settings.proxy_port' => 8977,
            'infrastructure.bulk_install_settings.proxy_username' => 'relay-user',
            'infrastructure.bulk_install_settings.proxy_password' => '',
            'infrastructure.bulk_install_settings.pmta_relay_ips' => [],
        ]);
        $workspace = (new Workspace)->forceFill(['id' => 'workspace-one']);
        $workspace->setRelation('infrastructureSettings', (new WorkspaceInfrastructureSettings)->forceFill([
            'pmta_relay_ips' => [],
            'proxy_port' => 8977,
        ]));

        $this->assertNull((new PmtaProxySettings)->forWorkspace($workspace));
    }
}
