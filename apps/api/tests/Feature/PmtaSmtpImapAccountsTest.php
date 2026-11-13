<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\PmtaSmtpConfig;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Infrastructure\AlphaLinkWebmailDiagnostics;
use App\Services\PmtaRemoteConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PmtaSmtpImapAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ManagedServer $imapServer;

    private ManagedServer $nonImapServer;

    private ManagedServerIp $imapIp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $this->workspace = Workspace::query()->create([
            'name' => 'PMTA SMTP IMAP Workspace',
            'slug' => 'pmta-smtp-imap-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);

        $this->imapServer = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'MAIL_IMAP_',
            'provider' => 'DigitalOcean',
            'ip_address' => '198.51.100.40',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'install_parameters' => ['imap' => true, 'pmta_version' => 45],
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);

        $this->nonImapServer = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'MAIL_NO_IMAP_',
            'provider' => 'DigitalOcean',
            'ip_address' => '198.51.100.41',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'install_parameters' => ['imap' => false, 'pmta_version' => 45],
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $this->owner->getKey(),
        ]);

        $domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'smtpimap.example.test',
            'state' => 'verified',
            'verification_token' => 'tok-smtp-imap',
            'created_by' => $this->owner->getKey(),
            'status' => 'used',
            'is_our' => true,
            'is_active' => true,
        ]);

        $this->imapIp = ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->imapServer->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '198.51.100.40',
            'type' => 'ipv4',
            'dns' => 'smtpimap.example.test',
            'is_active' => true,
        ]);

        ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->nonImapServer->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '198.51.100.41',
            'type' => 'ipv4',
            'dns' => 'noimap.example.test',
            'is_active' => true,
        ]);

        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'contact',
            'webmail_password' => 'top-secret-webmail-pw',
            'pmta_port' => 1212,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_pmta_smtp_page_renders_imap_installed_accounts_option(): void
    {
        $this->get(route('console.pmta-smtp.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('IMAP-Installed Accounts (Main IP)')
            ->assertSee('IMAP-Installed Server Accounts (Main IP Only)')
            ->assertSee('0 Accounts Selected')
            ->assertSee('0 Selected')
            ->assertSee('Push Selected to Server (0)');
    }

    public function test_imap_accounts_endpoint_lists_only_imap_installed_servers_main_ip_and_never_exposes_passwords(): void
    {
        ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->imapServer->getKey(),
            'sending_domain_id' => $this->imapIp->sending_domain_id,
            'ip' => '10.18.0.6',
            'type' => 'ipv4',
            'dns' => 'secondary.smtpimap.example.test',
            'is_active' => true,
        ]);

        $diag = Mockery::mock(AlphaLinkWebmailDiagnostics::class);
        $diag->shouldReceive('listDovecotUsers')
            ->once()
            ->with(Mockery::on(fn ($srv) => $srv->is($this->imapServer)))
            ->andReturn(['alimap_abc']);
        $this->app->instance(AlphaLinkWebmailDiagnostics::class, $diag);

        $response = $this->getJson(route('console.pmta-smtp.imap-accounts', $this->workspace->getKey()))
            ->assertOk()
            ->assertJsonPath('imap_server_count', 1)
            ->assertJsonPath('has_saved_password', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_name', 'MAIL_IMAP_')
            ->assertJsonPath('data.0.ip', '198.51.100.40')
            ->assertJsonPath('data.0.is_main_ip', true)
            ->assertJsonPath('data.0.login_address', 'alimap_abc@smtpimap.example.test')
            ->assertJsonPath('data.0.source', 'dovecot')
            ->assertJsonPath('data.1.login_address', 'contact@smtpimap.example.test')
            ->assertJsonPath('data.1.source', 'settings');

        $this->assertStringNotContainsString('10.18.0.6', (string) $response->getContent());
        $this->assertStringNotContainsString('top-secret-webmail-pw', (string) $response->getContent());
    }

    public function test_push_imap_accounts_saves_pmta_smtp_configs_and_pushes_pmta_file(): void
    {
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('listFiles')->once()->with(Mockery::on(fn ($srv) => $srv->is($this->imapServer)))->andReturn([]);
        $remote->shouldReceive('pushFile')->once()->with(
            Mockery::on(fn ($srv) => $srv->is($this->imapServer)),
            'smtp_'.$this->owner->getKey(),
            Mockery::on(fn (string $cfg) => str_contains($cfg, 'auth-username contact@smtpimap.example.test')
                && str_contains($cfg, 'auth-password top-secret-webmail-pw')
                && str_contains($cfg, 'smtp-hosts 198.51.100.40:1212')),
        );
        $this->app->instance(PmtaRemoteConfiguration::class, $remote);

        $this->postJson(route('console.pmta-smtp.push-imap', $this->workspace->getKey()), [
            'items' => [
                ['ip_id' => $this->imapIp->getKey(), 'username' => 'contact@smtpimap.example.test'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.count', 1);

        $config = PmtaSmtpConfig::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
        $this->assertSame('contact@smtpimap.example.test', $config->username);
        $this->assertSame('top-secret-webmail-pw', $config->password);
        $this->assertSame('198.51.100.40', $config->host);
        $this->assertSame(1212, (int) $config->port);
    }
}
