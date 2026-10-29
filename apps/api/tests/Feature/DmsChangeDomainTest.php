<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\SendingDomainHistory;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaPanelUrl;
use App\Services\Infrastructure\DmsCentos9HostPreparation;
use App\Services\Infrastructure\DmsChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsChangeDomainRunner;
use App\Services\Infrastructure\DmsInstallerServerCredentialResolver;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsChangeDomainTest extends TestCase
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
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Change Domain workspace',
            'slug' => 'change-domain-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->server = $this->createServer();
        $this->domain = $this->createDomain('current.example.test', 'used');
        $this->ip = $this->createIp($this->domain, '198.51.100.10');
        $this->server->forceFill(['install_parameters' => [
            'data' => [[
                'main' => true,
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'domain_id' => $this->domain->getKey(),
                'sub' => 'relay',
                'domain' => $this->domain->name,
            ]],
            'tracking' => true,
            'https' => true,
            'spf' => true,
            'dkim' => false,
            'dmarc' => true,
        ]])->save();
        $this->actingAs($this->owner);
    }

    public function test_change_domain_page_matches_dms_controls_and_lists_only_installable_server_and_domains(): void
    {
        $uninstalled = $this->createServer(['name' => 'NOT_INSTALLED', 'is_installed' => false, 'ip_address' => '192.0.2.24']);
        $inactive = $this->createServer(['name' => 'INACTIVE', 'is_active' => false, 'ip_address' => '192.0.2.25']);
        $cleanDomain = $this->createDomain('clean.example.test', 'free');
        $this->createDomain('spam-listed.example.test', 'new');
        $listed = SendingDomain::query()->where('name', 'spam-listed.example.test')->firstOrFail();
        SendingDomainHistory::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'sending_domain_id' => $listed->getKey(),
            'is_listed_spamhaus' => true,
            'is_listed_hostkarma' => false,
        ]);

        $this->get(route('clean.console.infrastructure.change-domain.index'))
            ->assertOk()
            ->assertSee('Change Domain')
            ->assertSee('id="change-domain-server"', false)
            ->assertSee('id="change-domain-https"', false)
            ->assertSee('id="change-domain-spf"', false)
            ->assertSee('id="change-domain-dkim"', false)
            ->assertSee('id="change-domain-dmarc"', false)
            ->assertSee('id="change-domain-ips"', false)
            ->assertSee('>CHANGE</button>', false)
            ->assertSee('id="apply-domain-change" disabled', false)
            ->assertDontSee('change-domain-blocker')
            ->assertDontSee('That equivalent runner is not complete')
            ->assertDontSee('CHANGE will be enabled when AlphaLink has a verified DNS-provider writer')
            ->assertSee('LOAD')
            ->assertSee('ALPHA_CHANGE_DOMAIN')
            ->assertSee($this->server->ip_address)
            ->assertSee($cleanDomain->name)
            ->assertSee($this->domain->name)
            ->assertDontSee($uninstalled->name)
            ->assertDontSee($inactive->name)
            ->assertDontSee('spam-listed.example.test');
    }

    public function test_load_endpoint_returns_dms_shaped_saved_ip_map_and_options_without_mutating_state(): void
    {
        $response = $this->postJson($this->loadUrl(), ['server_id' => $this->server->getKey()])
            ->assertOk()
            ->assertJsonPath('spf', true)
            ->assertJsonPath('dkim', false)
            ->assertJsonPath('dmarc', true)
            ->assertJsonPath('data.0.ip_id', $this->ip->getKey())
            ->assertJsonPath('data.0.ip', $this->ip->ip)
            ->assertJsonPath('data.0.type', 'ipv4')
            ->assertJsonPath('data.0.old_domain_id', $this->domain->getKey())
            ->assertJsonPath('data.0.domain_id', $this->domain->getKey())
            ->assertJsonPath('data.0.sub', 'relay')
            ->assertJsonPath('data.0.main', true);

        $response->assertJsonMissingPath('https');
        $this->assertSame($this->ip->getKey(), $response->json('data.0.ip_id'));
        $this->assertSame('current.example.test', $this->domain->fresh()->name);
        $this->assertSame($this->domain->getKey(), $this->ip->fresh()->sending_domain_id);
        $this->assertSame('relay.current.example.test', $this->ip->fresh()->dns);
    }

    public function test_load_endpoint_is_workspace_scoped_and_available_to_members(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other change domain workspace',
            'slug' => 'other-change-domain-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);

        $this->postJson($this->loadUrl(), ['server_id' => $this->createServer(['workspace_id' => $otherWorkspace->getKey()])->getKey()])
            ->assertNotFound();

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)
            ->postJson($this->loadUrl(), ['server_id' => $this->server->getKey()])
            ->assertOk();
    }

    public function test_change_endpoint_runs_the_selected_workspace_operation_and_exposes_scoped_logs(): void
    {
        $runner = Mockery::mock(DmsChangeDomainRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function ($workspace, $actor, $server, $rows, $options, $log, $requestId): bool {
            self::assertTrue($workspace->is($this->workspace));
            self::assertTrue($actor->is($this->owner));
            self::assertTrue($server->is($this->server));
            self::assertSame([[
                'ip_id' => $this->ip->getKey(),
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $this->domain->getKey(),
                'sub' => 'relay',
                'main' => true,
            ]], $rows);
            self::assertSame(['https' => false, 'spf' => true, 'dkim' => false, 'dmarc' => true], $options);
            self::assertIsCallable($log);
            $log('Setup Host');

            return true;
        })->andReturnNull();
        $this->app->instance(DmsChangeDomainRunner::class, $runner);

        $payload = [
            'server_id' => $this->server->getKey(),
            'data' => [[
                'ip_id' => $this->ip->getKey(),
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $this->domain->getKey(),
                'sub' => 'relay',
                'main' => true,
            ]],
            'https' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => true,
        ];

        $this->postJson($this->url().'/change', $payload)
            ->assertOk()->assertJsonPath('data', 'Changed Successfully');
        $this->getJson($this->url().'/'.$this->server->getKey().'/logs')
            ->assertOk()->assertExactJson(['Setup Host']);
        $this->assertSame($this->domain->getKey(), $this->ip->fresh()->sending_domain_id);

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Isolated change domain workspace',
            'slug' => 'isolated-change-domain-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $this->actingAs($otherOwner)->getJson($this->url().'/'.$this->server->getKey().'/logs')->assertNotFound();
    }

    public function test_change_endpoint_accepts_blank_subdomains_after_empty_strings_are_normalized_to_null(): void
    {
        $runner = Mockery::mock(DmsChangeDomainRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function ($workspace, $actor, $server, $rows): bool {
            self::assertTrue($workspace->is($this->workspace));
            self::assertTrue($actor->is($this->owner));
            self::assertTrue($server->is($this->server));
            self::assertSame('', $rows[0]['sub']);

            return true;
        })->andReturnNull();
        $this->app->instance(DmsChangeDomainRunner::class, $runner);

        $this->postJson($this->url().'/change', [
            'server_id' => $this->server->getKey(),
            'data' => [[
                'ip_id' => $this->ip->getKey(),
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $this->domain->getKey(),
                'sub' => '',
                'main' => true,
            ]],
            'https' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
        ])->assertOk()->assertJsonPath('data', 'Changed Successfully');
    }

    public function test_runner_replays_host_vhost_vmta_and_persistence_in_dms_order_for_non_owned_domains(): void
    {
        $this->server->forceFill(['host_facts' => ['os_pretty_name' => 'AlmaLinux 9.8 (Sapphire Caracal)']])->save();
        $credentialResolver = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentialResolver->shouldReceive('resolve')->once()->with(Mockery::on(fn ($server) => $server->is($this->server)))
            ->andReturn(['secret' => 'synthetic-secret', 'type' => 'password']);
        $sessionFactory = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $sessionFactory->shouldReceive('create')->once()->with($this->server, 'synthetic-secret', 'password')->andReturn($session);

        $session->shouldReceive('exec')->once()->with("hostnamectl set-hostname 'current.example.test'")->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with("sed -i 's/HOSTNAME=.*/HOSTNAME=current.example.test/g' /etc/sysconfig/network")->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with("grep -qxF '192.0.2.23 current.example.test' /etc/hosts || echo -e '192.0.2.23 current.example.test' >> /etc/hosts")->ordered()->andReturn('');
        $session->shouldReceive('upload')->once()->with(Mockery::on(fn ($content) => str_contains($content, 'ServerName current.example.test') && ! str_contains($content, "'www.current.example.test'")), '/etc/httpd/conf.d/current_example_test.conf')->ordered()->andReturn(true);
        $session->shouldReceive('exec')->once()->with('systemctl restart httpd')->ordered()->andReturn('');
        $session->shouldReceive('upload')->once()->with(Mockery::on(fn ($content) => str_contains($content, 'smtp-source-ip '.$this->ip->ip)), '/etc/pmta/conf/vmta')->ordered()->andReturn(true);
        $session->shouldReceive('exec')->once()->with('chown -R pmta:pmta /etc/pmta')->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with('systemctl restart pmta;')->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with('systemctl restart pmtahttp;')->ordered()->andReturn('');

        $dnsWriter = Mockery::mock(DmsChangeDomainDnsRecordWriter::class);
        $writtenRecords = [];
        $dnsWriter->shouldReceive('setHosts')->once()
            ->with(
                Mockery::on(fn (SendingDomain $domain): bool => $domain->is($this->domain)),
                Mockery::on(static function (array $records) use (&$writtenRecords): bool {
                    $writtenRecords = $records;

                    return true;
                }),
            )
            ->andReturn(true);
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentialResolver);
        $this->app->instance(DmsInstallerSshSessionFactory::class, $sessionFactory);
        $this->app->instance(DmsChangeDomainDnsRecordWriter::class, $dnsWriter);

        $logs = [];
        app(DmsChangeDomainRunner::class)->run(
            $this->workspace,
            $this->owner,
            $this->server,
            [[
                'ip_id' => $this->ip->getKey(),
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $this->domain->getKey(),
                'sub' => 'relay',
                'main' => true,
            ]],
            ['https' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false],
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        self::assertSame([
            'Prepare Changing Domain Server : '.$this->server->getKey(),
            'Setup Host', 'End Setting Host', 'Setup VHost', 'End Setting VHost',
            'Config Hosts A-AAAA-MX... For current.example.test', 'Done Setting Records',
            'Setup VMTAS', 'End Setting VMTAS', 'Cleaning', 'Done Changing Domain',
        ], $logs);
        self::assertSame([
            ['host' => 'relay', 'address' => '198.51.100.10', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '198.51.100.10', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '198.51.100.10', 'type' => 'A', 'ttl' => '60'],
        ], $writtenRecords);
        $this->assertSame('relay.current.example.test', $this->ip->fresh()->dns);
        $this->assertTrue($this->server->fresh()->install_parameters['spf'] === false);
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.domain_changed')->count());
    }

    public function test_runner_replays_owned_domain_dns_vmta_https_and_dms_false_dns_result_continuation(): void
    {
        $this->server->forceFill([
            'host_facts' => ['os_pretty_name' => 'AlmaLinux 9.8 (Sapphire Caracal)'],
            'install_parameters' => [
                'data' => [[
                    'main' => true,
                    'ip' => '10.0.0.25',
                    'type' => 'ipv4',
                    'domain_id' => $this->domain->getKey(),
                    'sub' => 'relay',
                    'domain' => $this->domain->name,
                ]],
                'pmta_version' => 45,
                'tracking' => true,
                'proxy' => true,
                'imap' => true,
            ],
        ])->save();
        $this->workspace->infrastructureSettings()->create([
            'pmta_admin_port' => 1213,
            'dev_ips' => [],
            'production_ips' => [],
            'pmta_relay_ips' => ['203.0.113.70'],
            'allow_all_monitor' => true,
        ]);
        $this->ip->forceFill(['ip' => '10.0.0.25'])->save();
        $newDomain = $this->createDomain('new.example.test', 'free');
        $newDomain->forceFill(['is_our' => true])->save();

        $credentialResolver = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentialResolver->shouldReceive('resolve')->once()
            ->with(Mockery::on(fn ($server) => $server->is($this->server)))
            ->andReturn(['secret' => 'synthetic-secret', 'type' => 'password']);
        $sessionFactory = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $sessionFactory->shouldReceive('create')->once()
            ->with($this->server, 'synthetic-secret', 'password')
            ->andReturn($session);

        $session->shouldReceive('exec')->once()->with("curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.25' https://ifconfig.me/ip 2>/dev/null")->ordered()->andReturn('203.0.113.25');
        $session->shouldReceive('exec')->once()->with("hostnamectl set-hostname 'new.example.test'")->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with("sed -i 's/HOSTNAME=.*/HOSTNAME=new.example.test/g' /etc/sysconfig/network")->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with("grep -qxF '192.0.2.23 new.example.test' /etc/hosts || echo -e '192.0.2.23 new.example.test' >> /etc/hosts")->ordered()->andReturn('');
        $session->shouldReceive('upload')->once()->with(
            Mockery::on(fn (string $content): bool => str_contains($content, 'ServerName new.example.test')
                && str_contains($content, 'ServerAlias www.new.example.test relay.new.example.test')),
            '/etc/httpd/conf.d/new_example_test.conf',
        )->ordered()->andReturn(true);
        $session->shouldReceive('exec')->once()->with('systemctl restart httpd')->ordered()->andReturn('');
        $session->shouldReceive('upload')->once()->with(
            Mockery::on(fn (string $content): bool => str_contains($content, 'smtp-source-ip 10.0.0.25')
                && str_contains($content, 'host-name relay.new.example.test')),
            '/etc/pmta/conf/vmta',
        )->ordered()->andReturn(true);
        $session->shouldReceive('upload')->once()->with(
            Mockery::on(fn (string $content): bool => str_contains($content, 'relay-domain relay.new.example.test')
                && str_contains($content, 'address /fbl@relay.new.example.test/')),
            '/etc/pmta/settings/inbound',
        )->ordered()->andReturn(true);
        $session->shouldReceive('exec')->once()->with('chown -R pmta:pmta /etc/pmta')->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with('systemctl restart pmta;')->ordered()->andReturn('');
        $session->shouldReceive('exec')->once()->with('systemctl restart pmtahttp;')->ordered()->andReturn('');
        $session->shouldReceive('upload')->once()->with(
            Mockery::on(fn (string $content): bool => str_contains($content, 'listen 1213;')
                && str_contains($content, 'server_name _;')
                && str_contains($content, 'proxy_pass http://127.0.0.1:2426;')
                && ! str_contains($content, 'ssl_certificate')),
            '/etc/nginx/conf.d/default.conf.alphalink-tls-stage',
        )->ordered()->andReturn(true);
        $session->shouldReceive('exec')->once()->with(Mockery::on(fn (string $command): bool => ! str_contains($command, 'letsencrypt/live')
            && str_contains($command, "mv '/etc/nginx/conf.d/default.conf.alphalink-tls-stage' '/etc/nginx/conf.d/default.conf'")
            && str_contains($command, 'nginx -t')
            && str_contains($command, 'systemctl reload nginx')))->ordered()->andReturn('ALPHALINK_PMTA_PROXY_READY');

        $dnsWriter = Mockery::mock(DmsChangeDomainDnsRecordWriter::class);
        $writtenRecords = [];
        $dnsWriter->shouldReceive('setHosts')->once()
            ->with(
                Mockery::on(fn (SendingDomain $domain): bool => $domain->is($newDomain)),
                Mockery::on(static function (array $records) use (&$writtenRecords): bool {
                    $writtenRecords = $records;

                    return true;
                }),
            )
            ->andReturn(false);

        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentialResolver);
        $this->app->instance(DmsInstallerSshSessionFactory::class, $sessionFactory);
        $this->app->instance(DmsChangeDomainDnsRecordWriter::class, $dnsWriter);

        $logs = [];
        app(DmsChangeDomainRunner::class)->run(
            $this->workspace,
            $this->owner,
            $this->server,
            [[
                'ip_id' => $this->ip->getKey(),
                'ip' => $this->ip->ip,
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $newDomain->getKey(),
                'sub' => 'relay',
                'main' => true,
            ]],
            ['https' => true, 'spf' => true, 'dkim' => false, 'dmarc' => true],
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        self::assertSame([
            'Prepare Changing Domain Server : '.$this->server->getKey(),
            'Setup Host', 'End Setting Host', 'Setup VHost', 'End Setting VHost',
            'Config Hosts A-AAAA-MX... For new.example.test', 'Failed Setting Records',
            'The selected domain new.example.test is unavailable from its DNS provider; using public IP 192.0.2.23 for the PMTA monitor and skipping domain HTTPS.',
            'Setup VMTAS', 'End Setting VMTAS', 'Skip domain HTTPS; no verified DNS-provider record is available.', 'Setting Https',
            'Cleaning', 'Done Changing Domain',
        ], $logs);
        self::assertSame([
            ['host' => 'relay', 'address' => '203.0.113.25', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '203.0.113.25', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '203.0.113.25', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'relay', 'address' => 'relay.new.example.test', 'type' => 'MX', 'ttl' => '60'],
            ['host' => '_dmarc.relay', 'address' => 'v=DMARC1; p=none; fo=1; rua=mailto:dmarc@new.example.test; ruf=mailto:dmarc@new.example.test', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:203.0.113.25 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:203.0.113.25 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $writtenRecords);
        $this->assertSame($newDomain->getKey(), $this->ip->fresh()->sending_domain_id);
        $this->assertSame('relay.new.example.test', $this->ip->fresh()->dns);
        $this->assertSame('used', $newDomain->fresh()->status);
        $this->assertSame('free', $this->domain->fresh()->status);
        $this->assertTrue($this->server->fresh()->install_parameters['proxy']);
        $this->assertSame(['spf' => true, 'dkim' => false, 'dmarc' => true, 'https' => false], array_intersect_key(
            $this->server->fresh()->install_parameters,
            array_flip(['https', 'spf', 'dkim', 'dmarc']),
        ));
        $changedServer = $this->server->fresh();
        $this->assertFalse($changedServer->install_parameters['pmta_monitor_https']);
        $this->assertSame('http://192.0.2.23:1213/', PmtaPanelUrl::forServer($changedServer, 1213));
        $this->assertSame(
            route('clean.console.pmta-admin.monitor', $changedServer->getKey(), false),
            PmtaPanelUrl::displayUrl($changedServer, 1213),
        );
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.domain_changed')->count());
    }

    public function test_unresolved_private_dns_ip_stops_change_domain_before_host_mutation(): void
    {
        $parameters = $this->server->install_parameters;
        $parameters['data'][0]['ip'] = '10.0.0.25';
        $this->server->forceFill([
            'ip_address' => '10.0.0.10',
            'host_facts' => ['os_pretty_name' => 'AlmaLinux 9.8 (Sapphire Caracal)'],
            'install_parameters' => $parameters,
        ])->save();
        $this->ip->forceFill(['ip' => '10.0.0.25'])->save();
        $newDomain = $this->createDomain('new.example.test', 'free');
        $newDomain->forceFill(['is_our' => true])->save();

        $credentials = Mockery::mock(DmsInstallerServerCredentialResolver::class);
        $credentials->shouldReceive('resolve')->once()->andReturn(['secret' => 'synthetic-secret', 'type' => 'password']);
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->times(4)->andReturn('');
        $sessions = Mockery::mock(DmsInstallerSshSessionFactory::class);
        $sessions->shouldReceive('create')->once()->andReturn($session);
        $host = Mockery::mock(DmsCentos9HostPreparation::class);
        $host->shouldNotReceive('configureHost');
        $writer = Mockery::mock(DmsChangeDomainDnsRecordWriter::class);
        $writer->shouldNotReceive('setHosts');
        $this->app->instance(DmsInstallerServerCredentialResolver::class, $credentials);
        $this->app->instance(DmsInstallerSshSessionFactory::class, $sessions);
        $this->app->instance(DmsCentos9HostPreparation::class, $host);
        $this->app->instance(DmsChangeDomainDnsRecordWriter::class, $writer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A selected interface has no verified public IP for DNS records.');

        app(DmsChangeDomainRunner::class)->run(
            $this->workspace,
            $this->owner,
            $this->server,
            [[
                'ip_id' => $this->ip->getKey(),
                'ip' => '10.0.0.25',
                'type' => 'ipv4',
                'old_domain_id' => $this->domain->getKey(),
                'domain_id' => $newDomain->getKey(),
                'sub' => 'relay',
                'main' => true,
            ]],
            ['https' => false, 'spf' => true, 'dkim' => false, 'dmarc' => false],
            static function (string $message): void {},
        );
    }

    public function test_load_endpoint_rejects_missing_installation_ip_assignment_and_invalid_server_id(): void
    {
        $this->server->forceFill(['install_parameters' => [
            'data' => [[
                'ip' => '198.51.100.99',
                'type' => 'ipv4',
                'sub' => '',
                'main' => true,
            ]],
        ]])->save();

        $this->postJson($this->loadUrl(), ['server_id' => $this->server->getKey()])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The saved IP-to-domain assignments are incomplete for this server.');

        $this->postJson($this->loadUrl(), ['server_id' => 'not-a-uuid'])->assertUnprocessable();
        $this->postJson($this->loadUrl(), ['server_id' => (string) Str::uuid()])->assertNotFound();
    }

    private function createServer(array $overrides = []): ManagedServer
    {
        return ManagedServer::query()->create(array_merge([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_CHANGE_DOMAIN',
            'provider' => 'Test provider',
            'ip_address' => '192.0.2.23',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $this->owner->getKey(),
        ], $overrides));
    }

    private function createDomain(string $name, string $status): SendingDomain
    {
        return SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => (string) Str::random(40),
            'created_by' => $this->owner->getKey(),
            'status' => $status,
            'is_our' => false,
            'is_active' => true,
        ]);
    }

    private function createIp(SendingDomain $domain, string $ipAddress): ManagedServerIp
    {
        return ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $ipAddress,
            'type' => 'ipv4',
            'dns' => 'relay.'.$domain->name,
            'is_active' => true,
        ]);
    }

    private function url(): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/change-domain';
    }

    private function loadUrl(): string
    {
        return $this->url().'/load-ips';
    }
}
