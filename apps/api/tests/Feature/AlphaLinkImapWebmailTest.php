<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Infrastructure\AlphaLinkImapWebmailClient;
use App\Services\Infrastructure\AlphaLinkWebmailDiagnostics;
use App\Services\Infrastructure\AlphaLinkWebmailOutboundSender;
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
        $this->assertSame('report.pdf', $parsed['attachment_items'][0]['filename']);
        $this->assertSame('application/pdf', $parsed['attachment_items'][0]['content_type']);
        $this->assertSame(7, $parsed['attachment_items'][0]['size']);
    }

    public function test_mime_parser_extracts_spf_dkim_dmarc_auth_and_inline_cid_images(): void
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $raw = implode("\r\n", [
            'From: "Bounce Probe" <probe@sender.example>',
            'To: alimap_saved@inbox.example.test',
            'Subject: Auth Header Check',
            'Return-Path: <bounces@sender.example>',
            'Received: from mx1.sender.example (mx1.sender.example [203.0.113.10]) by zonevortex.fun with ESMTP; Thu, 12 Nov 2026 00:10:00 +0000',
            'Authentication-Results: zonevortex.fun; spf=pass (sender IP is 203.0.113.10) smtp.mailfrom=sender.example; dkim=pass (2048-bit key) header.d=sender.example; dmarc=pass (p=reject) header.from=sender.example',
            'DKIM-Signature: v=1; a=rsa-sha256; d=sender.example; s=dkim2026; c=relaxed/relaxed',
            'X-virtual-MTA: mta-198.51.100.90',
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="REL1"',
            '',
            '--REL1',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>Logo: <img src="cid:logo123"></p>',
            '--REL1',
            'Content-Type: image/png; name="logo.png"',
            'Content-ID: <logo123>',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode((string) $pngBytes),
            '--REL1--',
        ]);

        $parsed = (new AlphaLinkImapWebmailClient)->parseMimeMessage($raw);

        $this->assertSame('pass', $parsed['auth']['spf']['status']);
        $this->assertSame('pass', $parsed['auth']['dkim']['status']);
        $this->assertSame('sender.example', $parsed['auth']['dkim']['domain']);
        $this->assertSame('dkim2026', $parsed['auth']['dkim']['selector']);
        $this->assertSame('pass', $parsed['auth']['dmarc']['status']);
        $this->assertSame('reject', $parsed['auth']['dmarc']['policy']);
        $this->assertSame('mta-198.51.100.90', $parsed['auth']['virtual_mta']);
        $this->assertSame('203.0.113.10', $parsed['auth']['sender_ip']);
        $this->assertCount(1, $parsed['received_hops']);
        $this->assertStringContainsString('data:image/png;base64,', $parsed['html']);
    }

    public function test_webmail_displays_and_uses_only_the_server_main_ip_even_when_opened_via_secondary_ip(): void
    {
        $secondDomain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'second.example.test',
            'state' => 'verified',
            'verification_token' => 'tok-second',
            'created_by' => $this->owner->getKey(),
            'status' => 'used',
            'is_our' => true,
            'is_active' => true,
        ]);
        $secondaryIp = ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $secondDomain->getKey(),
            'ip' => '10.18.0.6',
            'type' => 'ipv4',
            'dns' => 'second.example.test',
            'is_active' => true,
        ]);

        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'alimap_saved',
            'webmail_password' => 'super-secret-imap-pass',
        ]);

        $url = route('console.infrastructure.my-ips.webmail', [$this->workspace->getKey(), $secondaryIp->getKey()]);

        $this->get($url)
            ->assertOk()
            ->assertSee('COMMAND OS WEBMAIL')
            ->assertSee('MAIN IP &bull; 198.51.100.90', false)
            ->assertSee('inbox.example.test')
            ->assertSee('mta-198.51.100.90')
            ->assertDontSee('second.example.test')
            ->assertDontSee('10.18.0.6')
            ->assertSee('OUTBOUND PMTA COMPOSER');
    }

    public function test_webmail_flags_bulk_delete_attachment_download_and_pmta_send_endpoints(): void
    {
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'alimap_saved',
            'webmail_password' => 'saved-imap-secret',
        ]);

        $client = Mockery::mock(AlphaLinkImapWebmailClient::class);
        $client->shouldReceive('connect')
            ->times(5)
            ->with('198.51.100.90', 143, 'alimap_saved@inbox.example.test', 'saved-imap-secret', 'auto');
        $client->shouldReceive('folderCounts')->once()->with(['INBOX', 'Sent'])->andReturn([
            'INBOX' => ['messages' => 2, 'unseen' => 1],
            'Sent' => ['messages' => 0, 'unseen' => 0],
        ]);
        $client->shouldReceive('listFolders')->once()->andReturn(['INBOX', 'Sent']);
        $client->shouldReceive('listMessages')->once()->with('INBOX', 50)->andReturn([]);
        $client->shouldReceive('setMessageFlags')->once()->with('INBOX', [10, 11], ['\\Flagged'], []);
        $client->shouldReceive('bulkDeleteMessages')->once()->with('INBOX', [10, 11])->andReturn(2);
        $client->shouldReceive('fetchAttachment')->once()->with('INBOX', 12, 0)->andReturn([
            'filename' => 'trace.txt',
            'content_type' => 'text/plain',
            'content' => 'attachment body bytes',
        ]);
        $client->shouldReceive('appendMessage')->once()->with('Sent', Mockery::type('string'));
        $client->shouldReceive('disconnect')->times(5);
        $this->app->instance(AlphaLinkImapWebmailClient::class, $client);

        $sender = Mockery::mock(AlphaLinkWebmailOutboundSender::class);
        $sender->shouldReceive('resolveVmtaName')->andReturn('mta-198.51.100.90');
        $sender->shouldReceive('resolvePmtaPort')->andReturn(1212);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn ($ws) => $ws->is($this->workspace)),
                Mockery::on(fn ($srv) => $srv->is($this->server)),
                Mockery::on(fn ($ip) => $ip->is($this->ip)),
                Mockery::on(fn ($p) => $p['from'] === 'alimap_saved@inbox.example.test' && $p['to'] === ['dest@example.test'] && $p['subject'] === 'Test Outbound'),
            )
            ->andReturn([
                'vmta' => 'mta-198.51.100.90',
                'port' => 1212,
                'message_id' => '<abc@inbox.example.test>',
                'raw_message' => "Subject: Test Outbound\r\n\r\nHello",
            ]);
        $this->app->instance(AlphaLinkWebmailOutboundSender::class, $sender);

        $this->postJson(route('console.infrastructure.my-ips.webmail.connect', [$this->workspace->getKey(), $this->ip->getKey()]), [
            'include_counts' => true,
        ])
            ->assertOk()
            ->assertJsonPath('folder_counts.INBOX.unseen', 1);

        $this->postJson(route('console.infrastructure.my-ips.webmail.flags', [$this->workspace->getKey(), $this->ip->getKey()]), [
            'folder' => 'INBOX',
            'uids' => [10, 11],
            'action' => 'star',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->postJson(route('console.infrastructure.my-ips.webmail.bulk-delete', [$this->workspace->getKey(), $this->ip->getKey()]), [
            'folder' => 'INBOX',
            'uids' => [10, 11],
        ])->assertOk()->assertJsonPath('deleted', 2);

        $this->get(route('console.infrastructure.my-ips.webmail.attachment', [$this->workspace->getKey(), $this->ip->getKey(), 12, 0]).'?folder=INBOX')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="trace.txt"')
            ->assertSee('attachment body bytes', false);

        $this->postJson(route('console.infrastructure.my-ips.webmail.send', [$this->workspace->getKey(), $this->ip->getKey()]), [
            'from' => 'alimap_saved@inbox.example.test',
            'to' => 'dest@example.test',
            'subject' => 'Test Outbound',
            'text' => 'Hello',
            'save_to_sent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('vmta', 'mta-198.51.100.90')
            ->assertJsonPath('saved_to_sent', true);
    }

    public function test_webmail_diagnostics_and_sync_user_endpoints_work_without_exposing_secrets(): void
    {
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'webmail_username' => 'contact',
            'webmail_password' => 'new-sync-secret',
        ]);

        $diag = Mockery::mock(AlphaLinkWebmailDiagnostics::class);
        $diag->shouldReceive('fetchServerTelemetry')
            ->once()
            ->with(Mockery::on(fn ($srv) => $srv->is($this->server)), '198.51.100.90', 'inbox.example.test')
            ->andReturn([
                'imap_users' => ['alimap_old', 'contact'],
                'dkim_selector' => 'al123',
                'events' => [
                    [
                        'type' => 'bounced',
                        'time' => '2026-11-12 01:11:30+0000',
                        'vmta' => 'mta-198.51.100.90',
                        'status' => '5.7.1 spam-related',
                        'diag' => '550 5.7.1 Gmail rejected',
                        'rcpt' => 'test@gmail.com',
                        'dest_ip' => '142.250.102.26',
                    ],
                ],
            ]);
        $diag->shouldReceive('dnsReadiness')
            ->once()
            ->with('198.51.100.90', 'inbox.example.test', 'al123')
            ->andReturn([
                'ptr' => ['status' => 'missing', 'value' => '', 'detail' => 'No PTR'],
                'spf' => ['status' => 'pass', 'value' => 'v=spf1 ip4:198.51.100.90 -all', 'detail' => 'OK'],
                'dkim' => ['status' => 'pass', 'selector' => 'al123', 'value' => 'v=DKIM1;p=...', 'detail' => 'OK'],
                'dmarc' => ['status' => 'pass', 'value' => 'v=DMARC1; p=none', 'detail' => 'OK'],
            ]);
        $diag->shouldReceive('syncDovecotUser')
            ->once()
            ->with(Mockery::on(fn ($srv) => $srv->is($this->server)), 'contact@inbox.example.test', 'new-sync-secret')
            ->andReturn('contact');
        $this->app->instance(AlphaLinkWebmailDiagnostics::class, $diag);

        $this->getJson(route('console.infrastructure.my-ips.webmail.diagnostics', [$this->workspace->getKey(), $this->ip->getKey()]))
            ->assertOk()
            ->assertJsonPath('dns.ptr.status', 'missing')
            ->assertJsonPath('dns.spf.status', 'pass')
            ->assertJsonPath('events.0.rcpt', 'test@gmail.com')
            ->assertJsonPath('imap_users.1', 'contact');

        $this->postJson(route('console.infrastructure.my-ips.webmail.sync-user', [$this->workspace->getKey(), $this->ip->getKey()]), [
            'username' => 'contact@inbox.example.test',
            'use_saved_password' => true,
        ])
            ->assertOk()
            ->assertJsonPath('synced', true)
            ->assertJsonPath('username', 'contact');
    }

    public function test_crlf_header_normalization_does_not_insert_blank_lines_between_headers(): void
    {
        $raw = "To: dest@example.test\r\nSubject: Clean Header Test\r\nMIME-Version: 1.0\r\n\r\nHello Body\nLine 2";
        $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $raw) ?? $raw;
        $parts = explode("\r\n\r\n", $normalized, 2);

        $this->assertCount(2, $parts);
        $this->assertStringNotContainsString("\r\n\r\n", $parts[0]);
        $this->assertSame("To: dest@example.test\r\nSubject: Clean Header Test\r\nMIME-Version: 1.0", $parts[0]);
    }
}
