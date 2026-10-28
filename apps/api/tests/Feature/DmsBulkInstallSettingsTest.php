<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Infrastructure\DmsBulkInstallPrivateAssetReader;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsBulkInstallSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->workspaceFor($this->owner, 'install-settings-one');
        $this->actingAs($this->owner);
    }

    public function test_selected_tracking_imap_and_dkim_values_are_provisioned_once_and_encrypted(): void
    {
        $root = sys_get_temp_dir().'/alphalink-service-values-'.bin2hex(random_bytes(6));
        mkdir($root.'/optional/tracking', 0700, true);
        mkdir($root.'/optional/imap', 0700, true);
        mkdir($root.'/optional/dkim', 0700, true);
        file_put_contents($root.'/optional/tracking/mini-app.zip', 'synthetic archive');
        file_put_contents($root.'/optional/imap/dovecot.conf', 'synthetic config');
        file_put_contents($root.'/optional/dkim/keys.json', '{"key":"synthetic public","value":"synthetic private"}');
        config()->set('infrastructure.install_assets_directory', $root);
        config()->set('infrastructure.tracking_callback_url', 'https://alpha.example.test');
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 1213, 'proxy_port' => 1214,
            'apply_firewall' => false, 'allow_all_monitor' => false,
            'dev_ips' => '192.0.2.10',
        ]);

        try {
            $inputs = new DmsBulkInstallRuntimeInputs(new DmsBulkInstallPrivateAssetReader);
            $options = [
                'tracking' => true, 'proxy' => false, 'imap' => true, 'spf' => false,
                'dkim' => true, 'dmarc' => false, 'https' => false,
            ];
            $first = $inputs->resolve($options, $this->workspace);
            $second = $inputs->resolve($options, $this->workspace);
            $settings = $this->workspace->infrastructureSettings()->sole();

            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['settings']['mini_app_key']);
            $this->assertMatchesRegularExpression('/^al[a-f0-9]{16}$/', $first['settings']['dkim_selector']);
            $this->assertMatchesRegularExpression('/^alimap[a-f0-9]{16}$/', $first['settings']['imap_username']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['settings']['imap_password']);
            $this->assertSame($first['settings']['mini_app_key'], $second['settings']['mini_app_key']);
            $this->assertSame($first['settings']['dkim_selector'], $second['settings']['dkim_selector']);
            $this->assertSame($first['settings']['imap_username'], $second['settings']['imap_username']);
            $this->assertSame($first['settings']['imap_password'], $second['settings']['imap_password']);
            $this->assertNotSame($settings->mini_app_key, $settings->getRawOriginal('mini_app_key'));
            $this->assertNotSame($settings->dkim_key, $settings->getRawOriginal('dkim_key'));
            $this->assertNotSame($settings->webmail_username, $settings->getRawOriginal('webmail_username'));
            $this->assertNotSame($settings->webmail_password, $settings->getRawOriginal('webmail_password'));
            $this->assertArrayNotHasKey('mini_app_key', $settings->toArray());
            $this->assertArrayNotHasKey('dkim_key', $settings->toArray());
            $this->assertArrayNotHasKey('webmail_password', $settings->toArray());
        } finally {
            unlink($root.'/optional/tracking/mini-app.zip');
            unlink($root.'/optional/imap/dovecot.conf');
            unlink($root.'/optional/dkim/keys.json');
            rmdir($root.'/optional/tracking');
            rmdir($root.'/optional/imap');
            rmdir($root.'/optional/dkim');
            rmdir($root.'/optional');
            rmdir($root);
        }
    }

    public function test_settings_page_matches_dms_install_fields_without_rendering_saved_secrets(): void
    {
        $this->workspace->infrastructureSettings()->create([
            'pmta_port' => 2525,
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => false,
            'mini_app_key' => 'synthetic-mini-key',
            'proxy_password' => 'synthetic-proxy-password',
        ]);

        $this->get($this->settingsPage())
            ->assertOk()
            ->assertSee('GENERAL SETTINGS')
            ->assertSee('TRACKING APP KEY')
            ->assertSee('APP DKIM KEY')
            ->assertSee('Cloud Servers Password:')
            ->assertSee('APPLY FIREWALL')
            ->assertSee('DEV IPS')
            ->assertSee('PMTA RELAY SOURCE IPS')
            ->assertSee('PRODUCTION IPS')
            ->assertSee('RDP IPS')
            ->assertSee('PMTA WEB MONITOR PUBLIC PORT')
            ->assertSee('does not rewrite an installed server')
            ->assertSee('SHOW PMTA MONITOR TO ALL')
            ->assertSee('PROXY PORT')
            ->assertSee('PMTA DOMAINS SETTINGS')
            ->assertSee('SAVE SETTINGS')
            ->assertDontSee('synthetic-mini-key')
            ->assertDontSee('synthetic-proxy-password');
    }

    public function test_public_monitor_setting_can_be_saved_without_a_development_ip(): void
    {
        $this->post($this->settingsSave(), [
            'pmta_port' => '1212',
            'pmta_admin_port' => '1213',
            'proxy_port' => '1214',
            'apply_firewall' => '0',
            'allow_all_monitor' => '1',
            'pmta_relay_ips' => ['198.51.100.44'],
        ])->assertRedirect($this->settingsPage().'#settings')
            ->assertSessionHas('status', 'Installation settings saved.');

        $settings = $this->workspace->infrastructureSettings()->sole();
        $this->assertTrue($settings->allow_all_monitor);
        $this->assertSame([], $settings->dev_ips);
        $this->assertSame(['198.51.100.44'], $settings->pmta_relay_ips);
        $this->assertFalse($settings->pmta_relay_ips_auto_managed);
    }

    public function test_saving_other_settings_does_not_disable_automatic_relay_egress_refresh(): void
    {
        $this->workspace->infrastructureSettings()->create([
            'pmta_relay_ips' => ['1.1.1.1'],
            'pmta_relay_ips_auto_managed' => true,
        ]);

        $this->post($this->settingsSave(), [
            'pmta_port' => '1212',
            'pmta_admin_port' => '1213',
            'proxy_port' => '1214',
            'allow_all_monitor' => '1',
            'pmta_relay_ips' => ['1.1.1.1'],
        ])->assertRedirect($this->settingsPage().'#settings');

        $this->assertTrue($this->workspace->infrastructureSettings()->sole()->pmta_relay_ips_auto_managed);
    }

    public function test_owner_can_save_dms_install_settings_with_encrypted_secrets_and_filtered_ipv4_values(): void
    {
        $this->post($this->settingsSave(), [
            'pmta_port' => '2525',
            'pmta_admin_port' => '1213',
            'proxy_port' => '1214',
            'apply_firewall' => '1',
            'allow_all_monitor' => '0',
            'dev_ips' => ['192.0.2.10', '2001:db8::1', 'invalid'],
            'pmta_relay_ips' => ['198.51.100.44', '2001:db8::44', 'invalid'],
            'production_ips' => ['198.51.100.20'],
            'rdp_ips' => [],
            'dkim_key' => 'synthetic-dkim-selector',
            'webmail_username' => 'synthetic-webmail-user',
            'webmail_password' => 'synthetic-webmail-password',
            'proxy_username' => 'synthetic-proxy-user',
            'proxy_password' => 'synthetic-proxy-password',
            'mini_app_key' => 'synthetic-mini-app-key',
        ])->assertRedirect($this->settingsPage().'#settings')
            ->assertSessionHas('status', 'Installation settings saved.');

        $settings = $this->workspace->infrastructureSettings()->sole();
        $this->assertSame(2525, $settings->pmta_port);
        $this->assertSame(1213, $settings->pmta_admin_port);
        $this->assertSame(1214, $settings->proxy_port);
        $this->assertTrue($settings->apply_firewall);
        $this->assertFalse($settings->allow_all_monitor);
        $this->assertSame(['192.0.2.10'], $settings->dev_ips);
        $this->assertSame(['198.51.100.44'], $settings->pmta_relay_ips);
        $this->assertFalse($settings->pmta_relay_ips_auto_managed);
        $this->assertSame(['198.51.100.20'], $settings->production_ips);
        $this->assertSame([], $settings->rdp_ips);
        $this->assertSame('synthetic-dkim-selector', $settings->dkim_key);
        $this->assertSame('synthetic-webmail-user', $settings->webmail_username);
        $this->assertSame('synthetic-webmail-password', $settings->webmail_password);
        $this->assertNotSame('synthetic-webmail-password', $settings->getRawOriginal('webmail_password'));
        $this->assertNotSame('synthetic-proxy-password', $settings->getRawOriginal('proxy_password'));
        $this->assertArrayNotHasKey('webmail_password', $settings->toArray());
        $this->assertArrayNotHasKey('dev_ips', $settings->toArray());
        $this->assertArrayNotHasKey('pmta_relay_ips', $settings->toArray());

        $audit = AuditEvent::query()->where('action', 'infrastructure.install_settings_updated')->sole();
        $this->assertSame($this->workspace->getKey(), $audit->workspace_id);
        $this->assertSame(
            ['dkim_key', 'webmail_username', 'webmail_password', 'proxy_username', 'proxy_password', 'mini_app_key'],
            $audit->properties['secrets_updated'],
        );
        $auditProperties = json_encode($audit->properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-webmail-password', $auditProperties);
        $this->assertStringNotContainsString('192.0.2.10', $auditProperties);
        $this->assertStringNotContainsString('198.51.100.44', $auditProperties);
    }

    public function test_missing_workspace_relay_source_is_detected_and_saved_automatically(): void
    {
        config()->set('infrastructure.install_assets_directory', '/tmp/no-selected-assets');
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => true,
            'dev_ips' => [],
            'pmta_relay_ips' => [],
        ]);
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('', 404),
            'https://api4.ipify.org' => Http::response('1.1.1.1'),
            'https://checkip.amazonaws.com' => Http::response("1.1.1.1\n"),
            'https://ipv4.icanhazip.com' => Http::response('1.1.1.1'),
            'https://v4.ident.me' => Http::response('9.9.9.9'),
        ]);

        $resolved = app(DmsBulkInstallRuntimeInputs::class)->resolve(
            ['tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false, 'https' => false],
            $this->workspace,
            45,
        );

        $settings = $this->workspace->infrastructureSettings()->sole();
        $this->assertSame(['1.1.1.1'], $resolved['settings']['pmta_relay_ips']);
        $this->assertSame(['1.1.1.1'], $settings->pmta_relay_ips);
        $this->assertTrue($settings->pmta_relay_ips_auto_managed);
        Http::assertSentCount(6);
    }

    public function test_auto_managed_relay_source_refreshes_when_the_app_host_egress_changes(): void
    {
        config()->set('infrastructure.install_assets_directory', '/tmp/no-selected-assets');
        config()->set('infrastructure.bulk_install_settings', [
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => true,
            'dev_ips' => [],
            'pmta_relay_ips' => [],
        ]);
        $this->workspace->infrastructureSettings()->create([
            'pmta_relay_ips' => ['9.9.9.9'],
            'pmta_relay_ips_auto_managed' => true,
        ]);
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('', 404),
            'https://api4.ipify.org' => Http::response('1.1.1.1'),
            'https://checkip.amazonaws.com' => Http::response('1.1.1.1'),
            'https://ipv4.icanhazip.com' => Http::response('1.1.1.1'),
            'https://v4.ident.me' => Http::response('1.1.1.1'),
        ]);

        $resolved = app(DmsBulkInstallRuntimeInputs::class)->resolve(
            ['tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false, 'https' => false],
            $this->workspace,
            45,
        );

        $settings = $this->workspace->infrastructureSettings()->sole();
        $this->assertSame(['1.1.1.1'], $resolved['settings']['pmta_relay_ips']);
        $this->assertSame(['1.1.1.1'], $settings->pmta_relay_ips);
        $this->assertTrue($settings->pmta_relay_ips_auto_managed);
    }

    public function test_deployment_sync_refreshes_automatic_sources_and_preserves_explicit_workspace_lists(): void
    {
        $autoWorkspace = $this->workspace;
        $autoWorkspace->infrastructureSettings()->create([
            'pmta_relay_ips' => ['9.9.9.9'],
            'pmta_relay_ips_auto_managed' => true,
        ]);
        $manualWorkspace = $this->workspaceFor($this->owner, 'install-settings-manual');
        $manualWorkspace->infrastructureSettings()->create([
            'pmta_relay_ips' => ['9.9.9.9'],
            'pmta_relay_ips_auto_managed' => false,
        ]);
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('', 404),
            'https://api4.ipify.org' => Http::response('1.1.1.1'),
            'https://checkip.amazonaws.com' => Http::response('1.1.1.1'),
            'https://ipv4.icanhazip.com' => Http::response('1.1.1.1'),
            'https://v4.ident.me' => Http::response('1.1.1.1'),
        ]);

        $this->artisan('alphalink:sync-pmta-relay-source-ips')
            ->expectsOutput('Verified AlphaLink egress and synchronized 1 automatically managed workspace(s); preserved 1 explicitly configured workspace(s).')
            ->assertExitCode(0);

        $this->assertSame(['1.1.1.1'], $autoWorkspace->infrastructureSettings()->sole()->pmta_relay_ips);
        $this->assertTrue($autoWorkspace->infrastructureSettings()->sole()->pmta_relay_ips_auto_managed);
        $this->assertSame(['9.9.9.9'], $manualWorkspace->infrastructureSettings()->sole()->pmta_relay_ips);
        $this->assertFalse($manualWorkspace->infrastructureSettings()->sole()->pmta_relay_ips_auto_managed);
    }

    public function test_blank_secret_fields_keep_existing_encrypted_values(): void
    {
        $saved = $this->workspace->infrastructureSettings()->create([
            'pmta_port' => 2525,
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => false,
            'allow_all_monitor' => false,
            'webmail_password' => 'saved-secret',
        ]);

        $this->post($this->settingsSave(), [
            'pmta_port' => 2525,
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => 0,
            'allow_all_monitor' => 0,
        ])->assertRedirect($this->settingsPage().'#settings');

        $this->assertSame('saved-secret', $saved->fresh()->webmail_password);
    }

    public function test_workspace_members_can_use_install_settings_but_ids_stay_workspace_scoped(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'install-settings-two');

        $this->actingAs($operator)->get($this->settingsPage())
            ->assertOk()
            ->assertSee('SERVER / PMTA SETTINGS')
            ->assertSee('PMTA DOMAINS SETTINGS')
            ->assertDontSee('OPTIZMO TOKEN');
        $this->actingAs($operator)
            ->post(route('console.app-settings.update', $this->workspace->getKey()), [])
            ->assertForbidden();
        $this->actingAs($operator)->post($this->settingsSave(), [])->assertRedirect();
        $this->actingAs($operator)
            ->get(route('clean.console.infrastructure.install-settings.domains.load', ['pmta_version' => 40]))
            ->assertOk();
        $this->actingAs($operator)
            ->post(route('console.infrastructure.install-settings.domains.update', $this->workspace->getKey()), [])
            ->assertRedirect();
        $this->actingAs($this->owner)
            ->get(route('console.infrastructure.install-settings.edit', $otherWorkspace->getKey()))
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.install-settings.update', $otherWorkspace->getKey()), [])
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->get(route('console.infrastructure.install-settings.domains.load', [$otherWorkspace->getKey(), 'pmta_version' => 40]))
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.install-settings.domains.update', $otherWorkspace->getKey()), [])
            ->assertNotFound();
        $this->assertSame(1, WorkspaceInfrastructureSettings::query()->count());
        $this->assertSame($this->workspace->getKey(), WorkspaceInfrastructureSettings::query()->sole()->workspace_id);
    }

    public function test_runtime_inputs_use_workspace_settings_and_map_dms_fields_to_install_inputs(): void
    {
        $assetRoot = sys_get_temp_dir().'/dms-workspace-settings-'.bin2hex(random_bytes(6));
        mkdir($assetRoot, 0700);
        chmod($assetRoot, 0700);
        config([
            'infrastructure.install_assets_directory' => $assetRoot,
            'infrastructure.bulk_install_settings' => [],
        ]);
        $this->workspace->infrastructureSettings()->create([
            'pmta_port' => 2526,
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'pmta_relay_ips' => ['198.51.100.44'],
            'apply_firewall' => true,
            'allow_all_monitor' => false,
            'dev_ips' => ['192.0.2.11'],
            'dkim_key' => 'selector-from-dms-app-dkim-key',
            'webmail_username' => 'synthetic-webmail-user',
            'webmail_password' => 'synthetic-webmail-password',
        ]);

        try {
            $inputs = app(DmsBulkInstallRuntimeInputs::class)->resolve($this->installOptions(), $this->workspace, 45);
        } finally {
            rmdir($assetRoot);
        }

        $this->assertSame(2526, $inputs['settings']['pmta_port']);
        $this->assertSame(1213, $inputs['settings']['pmta_admin_port']);
        $this->assertTrue($inputs['settings']['apply_firewall']);
        $this->assertSame(['192.0.2.11'], $inputs['settings']['dev_ips']);
        $this->assertSame(['198.51.100.44'], $inputs['settings']['pmta_relay_ips']);
        $this->assertSame('selector-from-dms-app-dkim-key', $inputs['settings']['dkim_selector']);
        $this->assertSame('synthetic-webmail-user', $inputs['settings']['imap_username']);
        $this->assertSame('synthetic-webmail-password', $inputs['settings']['imap_password']);
    }

    public function test_invalid_ports_are_rejected_without_partial_persistence(): void
    {
        $this->from($this->settingsPage())->post($this->settingsSave(), [
            'pmta_port' => 0,
            'pmta_admin_port' => 1213,
            'proxy_port' => 1214,
            'apply_firewall' => 0,
            'allow_all_monitor' => 0,
        ])->assertRedirect($this->settingsPage())->assertSessionHasErrors('settings.pmta_port');

        $this->assertSame(0, WorkspaceInfrastructureSettings::query()->count());
    }

    public function test_pmta_domain_configuration_is_loaded_saved_encrypted_and_used_for_selected_version(): void
    {
        $assetRoot = sys_get_temp_dir().'/dms-pmta-domains-'.bin2hex(random_bytes(6));
        mkdir($assetRoot, 0700);
        mkdir($assetRoot.'/pmta/4.0', 0700, true);
        chmod($assetRoot, 0700);
        file_put_contents($assetRoot.'/pmta/4.0/domains.conf', 'synthetic dms baseline config');
        config(['infrastructure.install_assets_directory' => $assetRoot]);

        try {
            $this->get(route('clean.console.infrastructure.install-settings.domains.load', ['pmta_version' => 40]))
                ->assertOk()
                ->assertExactJson(['data' => 'synthetic dms baseline config']);

            $localConfig = "domain example.test\n  dkim-signature true";
            $this->postJson(route('console.infrastructure.install-settings.domains.update', $this->workspace->getKey()), [
                'pmta_version' => 45,
                'data' => $localConfig,
            ])->assertOk()->assertExactJson(['data' => 'Edited Successfully']);
            $this->getJson(route('clean.console.infrastructure.install-settings.domains.load', ['pmta_version' => 45]))
                ->assertOk()
                ->assertExactJson(['data' => $localConfig]);

            $settings = $this->workspace->infrastructureSettings()->sole();
            $this->assertSame($localConfig, $settings->pmta_domain_configs['45']);
            $this->assertNotSame($localConfig, $settings->getRawOriginal('pmta_domain_configs'));
            $this->assertArrayNotHasKey('pmta_domain_configs', $settings->toArray());

            $audit = AuditEvent::query()->where('action', 'infrastructure.pmta_domain_settings_updated')->sole();
            $this->assertSame(['pmta_version' => 45, 'bytes' => strlen($localConfig)], $audit->properties);
            $this->assertStringNotContainsString($localConfig, json_encode($audit->properties, JSON_THROW_ON_ERROR));

            $this->workspace->infrastructureSettings()->updateOrCreate([], [
                'pmta_port' => 2525,
                'pmta_admin_port' => 1213,
                'proxy_port' => 1214,
                'apply_firewall' => false,
                'allow_all_monitor' => false,
                'dev_ips' => ['192.0.2.10'],
                'pmta_relay_ips' => ['198.51.100.44'],
            ]);

            $runtimeRoot = $assetRoot.'/runtime';
            mkdir($runtimeRoot, 0700);
            chmod($runtimeRoot, 0700);
            config(['infrastructure.install_assets_directory' => $runtimeRoot]);
            $inputs = app(DmsBulkInstallRuntimeInputs::class)->resolve($this->installOptions(), $this->workspace, 45);
            $this->assertSame($localConfig, $inputs['assets']['pmta_domains_override']);
            rmdir($runtimeRoot);
        } finally {
            @unlink($assetRoot.'/pmta/4.0/domains.conf');
            @rmdir($assetRoot.'/pmta/4.0');
            @rmdir($assetRoot.'/pmta');
            @rmdir($assetRoot);
        }
    }

    private function settingsPage(): string
    {
        return route('clean.console.app-settings.index');
    }

    private function settingsSave(): string
    {
        return route('console.infrastructure.install-settings.update', $this->workspace->getKey());
    }

    private function workspaceFor(User $user, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Install settings workspace',
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    /** @return array<string, bool> */
    private function installOptions(): array
    {
        return [
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];
    }
}
