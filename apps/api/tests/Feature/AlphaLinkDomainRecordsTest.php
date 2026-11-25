<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpDomain;
use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\AlphaLinkPendingManualDnsPlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlphaLinkDomainRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_domain_records_page_loads_godaddy_hosts_and_nameservers(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        Http::fake([
            'api.godaddy.com/v1/domains/records.example/records' => Http::response([
                ['name' => '@', 'type' => 'A', 'data' => '192.0.2.10', 'ttl' => 600],
            ]),
            'api.godaddy.com/v1/domains/records.example' => Http::response([
                'nameServers' => ['ns01.domaincontrol.com', 'ns02.domaincontrol.com'],
            ]),
        ]);

        $this->actingAs($owner)
            ->get($this->recordsUrl($workspace, $domain))
            ->assertOk()
            ->assertSee('Editing')
            ->assertSee('records.example')
            ->assertSee('nameservers-editor')
            ->assertSee('hosts-editor')
            ->assertSee('EDIT NAMESERVERS')
            ->assertSee('RESET NAMESERVERS')
            ->assertSee('Add nameserver')
            ->assertSee('Add host')
            ->assertSee('CLEAR HOSTS')
            ->assertSee('EXPORT HOSTS')
            ->assertSee('IMPORT HOSTS')
            ->assertSee('EDIT HOSTS')
            ->assertSee('192.0.2.10');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'sso-key synthetic-key:synthetic-secret'));
    }

    public function test_domain_records_row_action_opens_the_reference_records_route_in_a_new_tab(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');

        $response = $this->actingAs($owner)->get(route('console.infrastructure.domains.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('title="RECORDS"', false)
            ->assertSee('window.open(url, \'_blank\', \'noopener\')', false);

        $html = str_replace('\\/', '/', (string) $response->getContent());
        self::assertStringContainsString('/infrastructure/domains/__DOMAIN__/records', $html);
    }

    public function test_godaddy_nameservers_can_be_changed_and_reset_with_scoped_audit_events(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        Http::fake(['api.godaddy.com/*' => Http::response([], 200)]);
        $url = $this->recordsUrl($workspace, $domain);

        $this->actingAs($owner)->postJson($url, [
            'type' => 'nameservers',
            'data' => [['nameserver' => 'ns1.synthetic.example'], ['nameserver' => 'ns2.synthetic.example']],
        ])->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api.godaddy.com/v1/domains/records.example'
            && $request->data() === ['nameServers' => ['ns1.synthetic.example', 'ns2.synthetic.example']]);

        $this->actingAs($owner)->postJson($url, ['type' => 'reset', 'data' => [1]])
            ->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api.godaddy.com/v1/domains/records.example'
            && $request->data() === ['nameServers' => ['ns01.domaincontrol.com', 'ns02.domaincontrol.com']]);

        $this->assertSame(2, AuditEvent::query()->where('subject_id', (string) $domain->getKey())->count());
    }

    public function test_host_replacement_uses_the_records_writer_and_ignores_empty_value_rows(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        $domain->domainProvider()->update(['is_active' => false]);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = [$request->method(), $request->url(), $request->data()];

            return $request->method() === 'GET'
                ? Http::response(['nameServers' => ['ns01.domaincontrol.com']], 200)
                : Http::response([], 200);
        });

        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), [
            'type' => 'hosts',
            'data' => [
                ['host' => 'www', 'type' => 'A', 'value' => '', 'ttl' => 60],
                ['host' => '@', 'type' => 'A', 'value' => '192.0.2.99', 'ttl' => 120],
            ],
        ])->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        self::assertCount(1, $requests);
        self::assertSame('PUT', $requests[0][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/records.example/records/A/%40', $requests[0][1]);
        self::assertSame([
            ['data' => '192.0.2.99', 'ttl' => 600],
        ], $requests[0][2]);
    }

    public function test_namecheap_reads_host_and_nameserver_records_and_updates_nameservers(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('namecheap');
        Http::fake(function ($request) {
            return match ($request['Command']) {
                'namecheap.domains.dns.getHosts' => Http::response('<ApiResponse Status="OK"><CommandResponse><DomainDNSGetHostsResult><host Name="@" Type="A" Address="192.0.2.12" TTL="1800" /></DomainDNSGetHostsResult></CommandResponse></ApiResponse>', 200),
                'namecheap.domains.dns.getList' => Http::response('<ApiResponse Status="OK"><CommandResponse><DomainDNSGetListResult><Nameserver>dns1.synthetic.example</Nameserver><Nameserver>dns2.synthetic.example</Nameserver></DomainDNSGetListResult></CommandResponse></ApiResponse>', 200),
                'namecheap.domains.dns.setDefault' => Http::response('<ApiResponse Status="OK"><CommandResponse><DomainDNSSetDefaultResult Updated="true" /></CommandResponse></ApiResponse>', 200),
                default => Http::response('<ApiResponse Status="OK"><CommandResponse><DomainDNSSetCustomResult Updated="true" /></CommandResponse></ApiResponse>', 200),
            };
        });

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))
            ->assertOk()->assertSee('192.0.2.12')->assertSee('dns1.synthetic.example');

        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), [
            'type' => 'nameservers',
            'data' => [['nameserver' => 'dns1.synthetic.example'], ['nameserver' => 'dns2.synthetic.example']],
        ])->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), [
            'type' => 'reset',
            'data' => [1],
        ])->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.dns.setCustom'
            && $request['SLD'] === 'records'
            && $request['TLD'] === 'example'
            && $request['Nameservers'] === 'dns1.synthetic.example,dns2.synthetic.example');
        Http::assertSent(fn ($request) => $request['Command'] === 'namecheap.domains.dns.setDefault'
            && $request['SLD'] === 'records'
            && $request['TLD'] === 'example');
    }

    public function test_external_domain_records_are_read_only_and_do_not_call_a_provider(): void
    {
        $this->withoutVite();
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy', false);
        $domain->forceFill(['domains_provider_id' => null])->save();
        Http::fake();

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))
            ->assertOk()
            ->assertSee('MANUAL DNS')
            ->assertSee('No active installed server assignments use this domain yet.');
        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), ['type' => 'reset', 'data' => [1]])
            ->assertStatus(400)->assertJsonPath('message', 'External domain DNS is managed manually.');

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'other-records-workspace');
        $this->actingAs($otherOwner)->get($this->recordsUrl($otherWorkspace, $domain))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_external_domain_manual_page_lists_dns_needed_by_its_installed_assignments(): void
    {
        $this->withoutVite();
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy', false);
        $domain->forceFill(['domains_provider_id' => null])->save();
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'DNS records server',
            'provider' => 'Synthetic provider',
            'ip_address' => '8.8.8.8',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => 'dns-records-'.bin2hex(random_bytes(5)),
            'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $owner->getKey(),
            'install_parameters' => ['tracking' => true, 'imap' => true, 'spf' => true, 'dkim' => true, 'dmarc' => true],
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '8.8.8.8',
            'type' => 'ipv4',
            'dns' => 'mail.records.example',
            'ehlo_hostname' => 'mail.records.example',
            'is_active' => true,
        ]);
        $identity = SendingDomainDkimIdentity::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'hostname' => 'mail.records.example',
            'selector' => 'alrecords01',
            'private_key' => 'synthetic-private-key',
            'public_record' => 'v=DKIM1; k=rsa; p=U3ludGhldGljS2V5',
            'is_active' => true,
        ]);
        ManagedServerIpDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'hostname' => 'mail.records.example',
            'tracking_host' => 'track.records.example',
            'dkim_selector' => $identity->selector,
            'sending_domain_dkim_identity_id' => $identity->getKey(),
            'is_primary' => true,
            'is_active' => true,
        ]);
        Http::fake();

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))
            ->assertOk()
            ->assertSee('mail;A;8.8.8.8;60')
            ->assertSee('track;A;8.8.8.8;60')
            ->assertSee('mail;MX;mail.records.example;60')
            ->assertSee('mail;TXT;v=spf1 ip4:8.8.8.8 -all;60')
            ->assertSee('alrecords01._domainkey.mail')
            ->assertSee('v=DMARC1; p=none; rua=mailto:dmarc@mail.records.example')
            ->assertSee('COPY ALL');

        Http::assertNothingSent();
    }

    public function test_external_domain_page_lists_pending_install_dns_before_assignments_are_installed(): void
    {
        $this->withoutVite();
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy', false);
        $domain->forceFill(['domains_provider_id' => null])->save();
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Pending external server',
            'provider' => 'Synthetic provider',
            'ip_address' => '20.66.51.224',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => 'pending-dns-'.bin2hex(random_bytes(5)),
            'request_fingerprint' => str_repeat('e', 64),
            'created_by' => $owner->getKey(),
            'install_parameters' => ['data' => [['ip' => '20.66.51.224']]],
        ]);
        $records = [
            ['host' => 'rico', 'address' => '20.66.51.224', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'track-92b714121c.rico', 'address' => '20.66.51.224', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'rico', 'address' => 'v=spf1 ip4:20.66.51.224 -all', 'type' => 'TXT', 'ttl' => '60'],
        ];

        app(AlphaLinkPendingManualDnsPlanStore::class)->replace($workspace, $server, [[
            'domain_id' => (string) $domain->getKey(),
            'domain' => (string) $domain->name,
            'records' => $records,
        ]]);

        $server->refresh();
        self::assertSame([['ip' => '20.66.51.224']], $server->install_parameters['data']);

        Http::fake();
        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))
            ->assertOk()
            ->assertSee('Pending install, reinstall, or domain-change DNS records are shown below.')
            ->assertSee('Pending records for Pending external server')
            ->assertSee('track-92b714121c.rico;A;20.66.51.224;60')
            ->assertSee('COPY ALL')
            ->assertDontSee('No active installed server assignments use this domain yet.');

        Http::assertNothingSent();
    }

    public function test_record_updates_keep_reference_request_validation_and_provider_failures_visible(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        Http::fake(['api.godaddy.com/*' => Http::response([], 403)]);
        $url = $this->recordsUrl($workspace, $domain);

        $this->actingAs($owner)->postJson($url, ['type' => 'invalid', 'data' => [1]])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'reset', 'data' => []])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'reset', 'data' => [1]])
            ->assertStatus(400)->assertExactJson(['data' => 'Failed']);
    }

    public function test_inactive_provider_state_does_not_block_records_actions_like_reference(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        $domain->domainProvider()->update(['is_active' => false]);
        Http::fake(['api.godaddy.com/*' => Http::response([], 200)]);

        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), [
            'type' => 'reset',
            'data' => [1],
        ])->assertOk()->assertExactJson(['data' => 'Updated Successfully']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->data() === ['nameServers' => ['ns01.domaincontrol.com', 'ns02.domaincontrol.com']]);
    }

    /** @return array{User, Workspace, SendingDomain} */
    private function domainWithProvider(string $type, bool $isOur = true): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'records-workspace');
        $provider = DomainProvider::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic '.$type,
            'type' => $type,
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-key',
            'api_secret' => 'synthetic-secret',
            'is_active' => true,
        ]);
        $domain = $workspace->sendingDomains()->create([
            'name' => 'records.example',
            'state' => 'verified',
            'verification_token' => 'synthetic-token',
            'created_by' => $owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'provider_domain_id' => 'synthetic-domain-id',
            'status' => 'new',
            'is_our' => $isOur,
            'is_active' => true,
            'have_who_is' => true,
            'have_renew' => true,
            'expire_at' => '2027-12-31',
        ]);

        return [$owner, $workspace, $domain];
    }

    private function workspaceFor(User $owner, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug.'-'.bin2hex(random_bytes(4)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function recordsUrl(Workspace $workspace, SendingDomain $domain): string
    {
        return route('console.infrastructure.domains.records', [$workspace->getKey(), $domain->getKey()]);
    }
}
