<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallPrivateAssetReader;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use RuntimeException;
use Tests\TestCase;

class DmsBulkInstallRuntimeInputsTest extends TestCase
{
    public function test_it_rejects_missing_runtime_settings_before_loading_assets(): void
    {
        config()->set('infrastructure.install_assets_directory', '/tmp/no-alphalink-assets');
        config()->set('infrastructure.bulk_install_settings', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required AlphaLink installer port and firewall settings are not configured.');

        (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))->resolve($this->installOptions());
    }

    public function test_it_loads_only_selected_private_assets_and_source_settings(): void
    {
        $root = sys_get_temp_dir().'/dms-install-fixture-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.'/optional/tracking', 0700, true);
        mkdir($root.'/optional/dkim', 0700, true);
        file_put_contents($root.'/optional/tracking/mini-app.zip', 'synthetic archive');
        file_put_contents($root.'/optional/dkim/keys.json', '{"key":"synthetic public","value":"synthetic private"}');

        try {
            config()->set('infrastructure.install_assets_directory', $root);
            config()->set('infrastructure.tracking_callback_url', 'https://alpha.example.test');
            config()->set('infrastructure.bulk_install_settings', [
                'pmta_admin_port' => '8080', 'proxy_port' => '3128',
                'apply_firewall' => true, 'allow_all_monitor' => false,
                'dev_ips' => '192.0.2.1, 192.0.2.2', 'pmta_relay_ips' => '198.51.100.44', 'production_ips' => '', 'rdp_ips' => '',
                'dkim_key' => 'selector', 'mini_app_key' => 'synthetic key',
            ]);
            $resolved = (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))
                ->resolve($this->installOptions(['tracking' => true, 'dkim' => true]));

            $this->assertSame('synthetic archive', $resolved['assets']['mini_app']);
            $this->assertNull($resolved['assets']['cloudflare_ips']);
            $this->assertSame('synthetic private', $resolved['settings']['dkim_key']);
            $this->assertSame('synthetic public', $resolved['settings']['dkim_public_key']);
            $this->assertSame(['192.0.2.1', '192.0.2.2'], $resolved['settings']['dev_ips']);
            $this->assertSame(['198.51.100.44'], $resolved['settings']['pmta_relay_ips']);
            $this->assertSame(8080, $resolved['settings']['pmta_admin_port']);
            $this->assertTrue($resolved['settings']['apply_firewall']);
        } finally {
            unlink($root.'/optional/tracking/mini-app.zip');
            unlink($root.'/optional/dkim/keys.json');
            rmdir($root.'/optional/tracking');
            rmdir($root.'/optional/dkim');
            rmdir($root.'/optional');
            rmdir($root);
        }
    }

    public function test_tracking_rejects_a_local_callback_address(): void
    {
        $root = sys_get_temp_dir().'/alphalink-tracking-callback-'.bin2hex(random_bytes(6));
        mkdir($root.'/optional/tracking', 0700, true);
        file_put_contents($root.'/optional/tracking/mini-app.zip', 'synthetic archive');
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.tracking_callback_url', 'http://127.0.0.1:8766');
        config()->set('infrastructure.bulk_install_settings.mini_app_key', 'synthetic key');
        config()->set('infrastructure.bulk_install_settings.dev_ips', '192.0.2.1');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Tracking requires a public HTTPS AlphaLink callback URL reachable from the installed server.');
            (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))
                ->resolve($this->installOptions(['tracking' => true]));
        } finally {
            unlink($root.'/optional/tracking/mini-app.zip');
            rmdir($root.'/optional/tracking');
            rmdir($root.'/optional');
            rmdir($root);
        }
    }

    public function test_it_uses_the_configured_pmta_listener_port_when_workspace_settings_are_absent(): void
    {
        $root = sys_get_temp_dir().'/dms-install-port-fixture-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        chmod($root, 0700);
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.pmta_port', 2527);
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 8080,
            'proxy_port' => 3128,
            'apply_firewall' => false,
            'allow_all_monitor' => false,
            'dev_ips' => ['192.0.2.1'],
        ]);

        try {
            $resolved = (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))
                ->resolve($this->installOptions());
        } finally {
            rmdir($root);
        }

        $this->assertSame(2527, $resolved['settings']['pmta_port']);
    }

    public function test_it_uses_dms_normalized_defaults_when_install_settings_are_unset(): void
    {
        $root = sys_get_temp_dir().'/dms-install-defaults-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        chmod($root, 0700);
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.bulk_install_settings.allow_all_monitor', true);

        try {
            $resolved = (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))
                ->resolve($this->installOptions());
        } finally {
            rmdir($root);
        }

        $this->assertSame(1212, $resolved['settings']['pmta_port']);
        $this->assertSame(1213, $resolved['settings']['pmta_admin_port']);
        $this->assertSame(1214, $resolved['settings']['proxy_port']);
        $this->assertFalse($resolved['settings']['apply_firewall']);
        $this->assertTrue($resolved['settings']['allow_all_monitor']);
        $this->assertSame([], $resolved['settings']['dev_ips']);
    }

    public function test_pmta_install_requires_a_persisted_relay_source_before_any_host_work(): void
    {
        $root = sys_get_temp_dir().'/dms-install-no-relay-source-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        chmod($root, 0700);
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => true,
            'dev_ips' => [],
            'pmta_relay_ips' => [],
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Configure PMTA RELAY SOURCE IPS with AlphaLink\'s stable public IPv4 egress address before installing or reinstalling PMTA.');
            app(DmsBulkInstallRuntimeInputs::class)->resolve($this->installOptions(), null, 45);
        } finally {
            rmdir($root);
        }
    }

    public function test_it_requires_an_operator_ip_when_public_monitor_access_is_disabled(): void
    {
        $root = sys_get_temp_dir().'/dms-install-no-monitor-access-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        chmod($root, 0700);
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => false,
            'dev_ips' => '',
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Add an operator IPv4 address to DEV IPS for PMTA administrator access, or enable public monitor access before installing.');
            (new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader))->resolve($this->installOptions());
        } finally {
            rmdir($root);
        }
    }

    /** @param array<string, bool> $overrides */
    private function installOptions(array $overrides = []): array
    {
        return array_replace([
            'tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false,
            'dkim' => false, 'dmarc' => false, 'https' => false,
        ], $overrides);
    }
}
