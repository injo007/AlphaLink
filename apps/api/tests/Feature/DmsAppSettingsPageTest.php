<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use App\Models\WorkspaceInfrastructureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsAppSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->workspaceFor($this->owner, 'general-settings');
        $this->actingAs($this->owner);
    }

    public function test_general_settings_contains_dms_fields_and_pmta_domains_tab(): void
    {
        $response = $this->get($this->settingsPage())->assertOk();

        foreach ([
            'app_name', 'optizmo_token', 'mini_app_key', 'ip_api_key', 'offer_suppression_update',
            'hash_token', 'dkim_key', 'extension_id', 'campaign_expire', 'cloud_servers_password',
            'apply_firewall', 'dev_ips', 'pmta_relay_ips', 'production_ips', 'rdp_ips', 'app_ips', 'app_redirect',
            'block_proxies', 'filter_geo', 'allowed_proxies', 'blocked_open_traffic', 'pmta_port',
            'pmta_admin_port', 'allow_all_monitor', 'proxy_port', 'proxy_username', 'proxy_password',
            'bot_open', 'bot_click', 'bot_unsubscribe', 'move_emails_to_dormant',
            'move_emails_to_dormant_interval', 'webmail_username', 'webmail_password', 'allow_backup',
            'backup_path', 'backup_server', 'backup_username', 'backup_port', 'backup_password',
            'telegram_bot_name', 'telegram_bot_token', 'telegram_channel', 'telegram_channel_it',
            'bitly_client_id', 'bitly_secret_key', 'imgur_client_id', 'imgur_secret_key', 'ns_hn_api',
        ] as $field) {
            $response->assertSee('id="'.$field.'"', false);
        }

        $settingsUrl = e($this->settingsPage());

        $response->assertSee('SETTINGS')
            ->assertDontSee('APP SETTINGS')
            ->assertSee('id="nav-submenu-miscellaneous"', false)
            ->assertSee('href="'.$settingsUrl.'"', false)
            ->assertSee('GENERAL SETTINGS')
            ->assertSee('PMTA WEB MONITOR PUBLIC PORT')
            ->assertSee('PMTA DOMAINS SETTINGS')
            ->assertSee('LOAD SETTINGS')
            ->assertSee('SAVE SETTINGS')
            ->assertSee('AlphaLink callback not implemented.')
            ->assertDontSee('Saved values are workspace-scoped.')
            ->assertSee('id="pmta_domain_data"', false);
    }

    public function test_dms_general_settings_normalize_values_and_encrypt_all_secret_fields(): void
    {
        $this->post($this->settingsPage(), [
            'settings' => [
                'app_name' => 'AlphaLink Local',
                'optizmo_token' => 'synthetic-optizmo-token',
                'ip_api_key' => 'synthetic-ip-api-key',
                'offer_suppression_update' => '4',
                'hash_token' => 'synthetic-hash-token',
                'extension_id' => 'synthetic-extension',
                'campaign_expire' => '9',
                'app_ips' => ['198.51.100.30', 'bounce.example.test'],
                'app_redirect' => 'https://example.test/blocked',
                'block_proxies' => '1',
                'filter_geo' => '0',
                'allowed_proxies' => ['Comcast'],
                'blocked_open_traffic' => ['Example ISP'],
                'bot_open' => '24',
                'bot_click' => '7',
                'bot_unsubscribe' => '6',
                'move_emails_to_dormant' => '1',
                'move_emails_to_dormant_interval' => '5',
                'allow_backup' => '1',
                'backup_path' => '/synthetic-backup',
                'backup_server' => '203.0.113.40',
                'backup_username' => 'synthetic-root',
                'backup_port' => '22',
                'backup_password' => 'synthetic-backup-password',
                'telegram_bot_name' => 'synthetic-bot',
                'telegram_bot_token' => 'synthetic-telegram-token',
                'telegram_channel' => 'channel-1',
                'telegram_channel_it' => 'channel-2',
                'bitly_client_id' => 'synthetic-bitly-client',
                'bitly_secret_key' => 'synthetic-bitly-secret',
                'imgur_client_id' => 'synthetic-imgur-client',
                'imgur_secret_key' => 'synthetic-imgur-secret',
                'ns_hn_api' => 'synthetic-nshn-key',
                'pmta_port' => '2526',
                'pmta_admin_port' => '1213',
                'proxy_port' => '1214',
                'apply_firewall' => '0',
                'allow_all_monitor' => '1',
                'dev_ips' => ['192.0.2.10', '2001:db8::1', 'invalid'],
                'pmta_relay_ips' => ['198.51.100.40', '2001:db8::40', 'invalid'],
                'production_ips' => ['198.51.100.20'],
                'rdp_ips' => [],
                'dkim_key' => 'synthetic-dkim-selector',
                'webmail_username' => 'synthetic-webmail-user',
                'webmail_password' => 'synthetic-webmail-password',
                'proxy_username' => 'synthetic-proxy-user',
                'proxy_password' => 'synthetic-proxy-password',
                'mini_app_key' => 'synthetic-mini-app-key',
                'cloud_servers_password' => 'synthetic-cloud-password',
            ],
            'settings_present' => [
                'app_ips' => '1',
                'allowed_proxies' => '1',
                'blocked_open_traffic' => '1',
                'dev_ips' => '1',
                'pmta_relay_ips' => '1',
                'production_ips' => '1',
                'rdp_ips' => '1',
            ],
        ])->assertRedirect($this->settingsPage().'#settings')
            ->assertSessionHas('status', 'Settings saved.');

        $appSettings = $this->workspace->appSettings()->sole();
        $values = $appSettings->settings;
        $this->assertSame('AlphaLink Local', $values['app_name']);
        $this->assertSame(4, $values['offer_suppression_update']);
        $this->assertSame(9, $values['campaign_expire']);
        $this->assertTrue($values['block_proxies']);
        $this->assertFalse($values['filter_geo']);
        $this->assertTrue($values['move_emails_to_dormant']);
        $this->assertTrue($values['allow_backup']);
        $this->assertSame(['198.51.100.30', 'bounce.example.test'], $values['app_ips']);
        $this->assertSame(['Comcast'], $values['allowed_proxies']);
        $this->assertSame(['Example ISP'], $values['blocked_open_traffic']);
        $this->assertSame(22, $values['backup_port']);
        $this->assertArrayNotHasKey('cloud_servers_password', $values);
        $this->assertArrayNotHasKey('pmta_port', $values);
        $this->assertArrayNotHasKey('dkim_key', $values);
        $this->assertNotSame('synthetic-optizmo-token', $appSettings->getRawOriginal('settings'));
        $this->assertArrayNotHasKey('settings', $appSettings->toArray());

        $infrastructure = $this->workspace->infrastructureSettings()->sole();
        $this->assertSame(2526, $infrastructure->pmta_port);
        $this->assertSame(1213, $infrastructure->pmta_admin_port);
        $this->assertSame(1214, $infrastructure->proxy_port);
        $this->assertFalse($infrastructure->apply_firewall);
        $this->assertTrue($infrastructure->allow_all_monitor);
        $this->assertSame(['192.0.2.10'], $infrastructure->dev_ips);
        $this->assertSame(['198.51.100.40'], $infrastructure->pmta_relay_ips);
        $this->assertNotSame('198.51.100.40', $infrastructure->getRawOriginal('pmta_relay_ips'));
        $this->assertSame(['198.51.100.20'], $infrastructure->production_ips);
        $this->assertSame([], $infrastructure->rdp_ips);
        $this->assertSame('synthetic-webmail-password', $infrastructure->webmail_password);
        $this->assertNotSame('synthetic-webmail-password', $infrastructure->getRawOriginal('webmail_password'));
        $this->assertArrayNotHasKey('cloud_servers_password', $infrastructure->toArray());
        $this->assertArrayNotHasKey('pmta_relay_ips', $infrastructure->toArray());

        $audit = AuditEvent::query()->where('action', 'workspace.app_settings_updated')->sole();
        $this->assertContains('cloud_servers_password', $audit->properties['secrets_updated']);
        $this->assertContains('optizmo_token', $audit->properties['secrets_updated']);
        $this->assertContains('webmail_password', $audit->properties['secrets_updated']);
        $auditJson = json_encode($audit->properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-', $auditJson);
        $this->assertStringNotContainsString('192.0.2.10', $auditJson);
        $this->assertStringNotContainsString('198.51.100.40', $auditJson);
    }

    public function test_blank_secrets_are_preserved_and_explicit_clear_removes_only_selected_secret(): void
    {
        $generic = $this->workspace->appSettings()->create([
            'settings' => [
                'app_name' => 'Before edit',
                'optizmo_token' => 'saved-optizmo-token',
                'telegram_bot_token' => 'saved-telegram-token',
            ],
        ]);
        $infrastructure = $this->workspace->infrastructureSettings()->create([
            'cloud_servers_password' => 'saved-cloud-password',
        ]);

        $this->get($this->settingsPage())
            ->assertOk()
            ->assertDontSee('saved-optizmo-token')
            ->assertDontSee('saved-telegram-token')
            ->assertDontSee('saved-cloud-password')
            ->assertSee('id="clear_optizmo_token"', false);

        $this->post($this->settingsPage(), [
            'settings' => [
                'app_name' => 'After edit',
                'optizmo_token' => '',
                'telegram_bot_token' => '',
                'cloud_servers_password' => '',
            ],
            'settings_clear' => ['optizmo_token' => '1'],
        ])->assertRedirect($this->settingsPage().'#settings');

        $values = $generic->fresh()->settings;
        $this->assertSame('After edit', $values['app_name']);
        $this->assertSame('', $values['optizmo_token']);
        $this->assertSame('saved-telegram-token', $values['telegram_bot_token']);
        $this->assertSame('saved-cloud-password', $infrastructure->fresh()->cloud_servers_password);

        $audit = AuditEvent::query()->where('action', 'workspace.app_settings_updated')->latest('id')->firstOrFail();
        $this->assertSame(['optizmo_token'], $audit->properties['secrets_cleared']);
    }

    public function test_saved_setting_secrets_can_be_revealed_only_on_demand_and_are_audited(): void
    {
        $secret = 'synthetic-tracking-key-'.Str::random(16);
        $optizmoToken = 'synthetic-optizmo-key-'.Str::random(16);
        $this->workspace->infrastructureSettings()->create(['mini_app_key' => $secret]);
        $this->workspace->appSettings()->create(['settings' => ['optizmo_token' => $optizmoToken]]);

        $page = $this->get($this->settingsPage())
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('REVEAL')
            ->assertSee('Generated automatically the first time TRACKING is selected during server installation.');
        $page->assertSee('data-secret-url="'.e(route('clean.console.app-settings.secret', [
            'field' => 'mini_app_key',
        ])).'"', false);

        $this->get(route('clean.console.app-settings.secret', [
            'field' => 'mini_app_key',
        ]))->assertOk()
            ->assertJsonPath('field', 'mini_app_key')
            ->assertJsonPath('value', $secret)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $this->get(route('clean.console.app-settings.secret', [
            'field' => 'optizmo_token',
        ]))->assertOk()->assertJsonPath('value', $optizmoToken);

        $audits = AuditEvent::query()->where('action', 'workspace.app_setting_secret_revealed')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(['field' => 'mini_app_key'], $audits->firstWhere('properties.field', 'mini_app_key')->properties);
        $auditJson = json_encode($audits->pluck('properties'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $auditJson);
        $this->assertStringNotContainsString($optizmoToken, $auditJson);
    }

    public function test_non_admin_workspace_members_cannot_reveal_saved_setting_secrets(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->getKey(), ['role' => 'operator']);
        $this->workspace->infrastructureSettings()->create(['mini_app_key' => 'synthetic-secret']);
        $this->actingAs($member);

        $this->get(route('clean.console.app-settings.secret', [
            'field' => 'mini_app_key',
        ]))->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->where('action', 'workspace.app_setting_secret_revealed')->count());
    }

    public function test_environment_optizmo_token_is_revealable_without_embedding_it_in_the_page(): void
    {
        $token = 'synthetic-environment-optizmo-token';
        config(['services.optizmo.token' => $token]);

        $this->get($this->settingsPage())
            ->assertOk()
            ->assertDontSee($token)
            ->assertSee('data-secret-reveal="optizmo_token"', false);

        $this->get(route('clean.console.app-settings.secret', [
            'field' => 'optizmo_token',
        ]))->assertOk()->assertJsonPath('value', $token);
    }

    public function test_validation_failure_does_not_flash_nested_secret_values(): void
    {
        $this->from($this->settingsPage())->post($this->settingsPage(), [
            'settings' => [
                'pmta_port' => '0',
                'optizmo_token' => 'synthetic-validation-secret',
            ],
        ])->assertRedirect($this->settingsPage())
            ->assertSessionHasErrors('settings.pmta_port')
            ->assertSessionMissing('_old_input.settings.optizmo_token');

        $this->assertSame(0, WorkspaceInfrastructureSettings::query()->count());
        $this->assertSame(0, WorkspaceAppSettings::query()->count());
    }

    private function settingsPage(): string
    {
        return route('clean.console.app-settings.index');
    }

    private function workspaceFor(User $user, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'General settings workspace',
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
