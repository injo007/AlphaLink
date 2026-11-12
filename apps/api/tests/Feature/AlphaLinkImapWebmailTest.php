<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Infrastructure\AlphaLinkImapWebmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AlphaLinkImapWebmailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ManagedServer $server;

    private SendingDomain $domain;

    private ManagedServerIp $ip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $this->workspace = Workspace::query()->create([
            'name' => 'Webmail Workspace',
            'slug' => 'webmail-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);

        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'MAIL_WEBMAIL_',
            'provider' => 'DigitalOcean',
            'ip_address' => '198.51.100.90',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);

        $this->domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'inbox.example.test',
            'state' => 'verified',
            'verification_token' => 'tok-webmail',
            'created_by' => $this->owner->getKey(),
            'status' => 'used',
            'is_our' => true,
            'is_active' => true,
        ]);

        $this->ip = ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $this->domain->getKey(),
            'ip' => '198.51.100.90',
            'type' => 'ipv4',
            'dns' => 'inbox.example.test',
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_my_ips_page_exposes_webmail_link_template_for_each_row(): void
    {
        $myIpsUrl = route('console.infrastructure.my-ips', $this->workspace->getKey());

        $this->get($myIpsUrl)
            ->assertOk()
            ->assertSee('data-webmail-url-template="', false)
            ->assertSee('/infrastructure/my-ips/__IP__/webmail', false)
            ->assertSee('Open Webmail for')
            ->assertDontSee('Webmail unavailable');
    }

    public function test_webmail_page_prepopulates_saved_settings_username_without_exposing_password(): void
    {
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'alimap_saved',
            'webmail_password' => 'super-secret-imap-pass',
        ]);

        $url = route('console.infrastructure.my-ips.webmail', [$this->workspace->getKey(), $this->ip->getKey()]);

        $this->get($url)
            ->assertOk()
            ->assertSee('inbox.example.test')
            ->assertSee('alimap_saved@inbox.example.test')
            ->assertSee('Use saved password from Settings')
            ->assertDontSee('super-secret-imap-pass');
    }

    public function test_webmail_connect_uses_saved_settings_credentials_and_loads_mailbox_then_reads_and_deletes(): void
    {
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'alimap_saved',
            'webmail_password' => 'saved-imap-secret',
        ]);

        $client = Mockery::mock(AlphaLinkImapWebmailClient::class);
        $client->shouldReceive('connect')
            ->times(3)
            ->with('198.51.100.90', 143, 'alimap_saved@inbox.example.test', 'saved-imap-secret', 'auto');
        $client->shouldReceive('listFolders')->once()->andReturn(['INBOX', 'Trash']);
        $client->shouldReceive('listMessages')->once()->with('INBOX', 50)->andReturn([
            [
                'uid' => 42,
                'subject' => 'Welcome Probe',
                'from' => 'sender@example.test',
                'to' => 'alimap_saved@inbox.example.test',
                'date' => 'Thu, 12 Nov 2026 00:05:00 +0000',
                'size' => 1024,
                'seen' => false,
            ],
        ]);
        $client->shouldReceive('fetchMessage')->once()->with('INBOX', 42)->andReturn([
            'uid' => 42,
            'subject' => 'Welcome Probe',
            'from' => 'sender@example.test',
            'to' => 'alimap_saved@inbox.example.test',
            'date' => 'Thu, 12 Nov 2026 00:05:00 +0000',
            'size' => 1024,
            'seen' => true,
            'headers' => ['subject' => 'Welcome Probe'],
            'text' => 'Hello mailbox',
            'html' => '<p>Hello <b>mailbox</b></p>',
            'attachments' => [],
            'raw_headers' => 'Subject: Welcome Probe',
        ]);
        $client->shouldReceive('deleteMessage')->once()->with('INBOX', 42);
        $client->shouldReceive('disconnect')->times(3);
        $this->app->instance(AlphaLinkImapWebmailClient::class, $client);

        $connectUrl = route('console.infrastructure.my-ips.webmail.connect', [$this->workspace->getKey(), $this->ip->getKey()]);
        $this->postJson($connectUrl, [
            'username' => '',
            'password' => '',
            'use_saved_password' => true,
            'port' => 143,
            'security' => 'auto',
            'folder' => 'INBOX',
        ])
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('username', 'alimap_saved@inbox.example.test')
            ->assertJsonPath('folders.0', 'INBOX')
            ->assertJsonPath('messages.0.uid', 42);

        $msgUrl = route('console.infrastructure.my-ips.webmail.message', [$this->workspace->getKey(), $this->ip->getKey(), 42]);
        $this->getJson($msgUrl.'?folder=INBOX')
            ->assertOk()
            ->assertJsonPath('message.subject', 'Welcome Probe')
            ->assertJsonPath('message.html', '<p>Hello <b>mailbox</b></p>');

        $delUrl = route('console.infrastructure.my-ips.webmail.delete', [$this->workspace->getKey(), $this->ip->getKey(), 42]);
        $this->deleteJson($delUrl.'?folder=INBOX')
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('uid', 42);
    }

    public function test_webmail_connect_can_save_entered_credentials_to_workspace_settings(): void
    {
        $client = Mockery::mock(AlphaLinkImapWebmailClient::class);
        $client->shouldReceive('connect')
            ->once()
            ->with('198.51.100.90', 993, 'customuser@inbox.example.test', 'new-secret-pw', 'ssl');
        $client->shouldReceive('listFolders')->once()->andReturn(['INBOX']);
        $client->shouldReceive('listMessages')->once()->with('INBOX', 50)->andReturn([]);
        $client->shouldReceive('disconnect')->once();
        $this->app->instance(AlphaLinkImapWebmailClient::class, $client);

        $connectUrl = route('console.infrastructure.my-ips.webmail.connect', [$this->workspace->getKey(), $this->ip->getKey()]);
        $this->postJson($connectUrl, [
            'username' => 'customuser@inbox.example.test',
            'password' => 'new-secret-pw',
            'save_to_settings' => true,
            'port' => 993,
            'security' => 'ssl',
        ])->assertOk();

        $saved = WorkspaceInfrastructureSettings::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
        $this->assertSame('customuser', $saved->webmail_username);
        $this->assertSame('new-secret-pw', $saved->webmail_password);
    }

    public function test_mime_parser_decodes_multipart_html_text_and_attachments(): void
    {
        $raw = implode("\r\n", [
            'From: =?UTF-8?B?VGVzdCBTZW5kZXI=?= <test@example.test>',
            'To: inbox@inbox.example.test',
            'Subject: Multipart Demo',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="BOUND1"',
            '',
            '--BOUND1',
            'Content-Type: multipart/alternative; boundary="BOUND2"',
            '',
            '--BOUND2',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Hello=20World',
            '--BOUND2',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('<h1>Hello HTML</h1>'),
            '--BOUND2--',
            '--BOUND1',
            'Content-Type: application/pdf; name="report.pdf"',
            'Content-Disposition: attachment; filename="report.pdf"',
            '',
            'PDFDATA',
            '--BOUND1--',
        ]);

        $parsed = (new AlphaLinkImapWebmailClient)->parseMimeMessage($raw);

        $this->assertSame('Test Sender <test@example.test>', $parsed['headers']['from']);
        $this->assertSame('Multipart Demo', $parsed['headers']['subject']);
        $this->assertSame('Hello World', $parsed['text']);
        $this->assertSame('<h1>Hello HTML</h1>', $parsed['html']);
        $this->assertSame(['report.pdf'], $parsed['attachments']);
    }
}
