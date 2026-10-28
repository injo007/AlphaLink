<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsDomainRecordsTest extends TestCase
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

    public function test_domain_records_row_action_opens_the_dms_records_route_in_a_new_tab(): void
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

        self::assertSame('PUT', $requests[1][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/records.example/records', $requests[1][1]);
        self::assertSame([
            ['name' => '@', 'data' => '192.0.2.99', 'type' => 'A', 'ttl' => 600],
            ['name' => '@', 'data' => 'ns01.domaincontrol.com', 'type' => 'NS'],
        ], $requests[1][2]);
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

    public function test_records_reject_nonowned_or_foreign_domains_without_provider_calls(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy', false);
        Http::fake();

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))->assertStatus(400);
        $this->actingAs($owner)->postJson($this->recordsUrl($workspace, $domain), ['type' => 'reset', 'data' => [1]])
            ->assertStatus(400)->assertJsonPath('message', 'NOT OUR DOMAIN');

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'other-records-workspace');
        $this->actingAs($otherOwner)->get($this->recordsUrl($otherWorkspace, $domain))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_record_updates_keep_dms_request_validation_and_provider_failures_visible(): void
    {
        [$owner, $workspace, $domain] = $this->domainWithProvider('godaddy');
        Http::fake(['api.godaddy.com/*' => Http::response([], 403)]);
        $url = $this->recordsUrl($workspace, $domain);

        $this->actingAs($owner)->postJson($url, ['type' => 'invalid', 'data' => [1]])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'reset', 'data' => []])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'reset', 'data' => [1]])
            ->assertStatus(400)->assertExactJson(['data' => 'Failed']);
    }

    public function test_inactive_provider_state_does_not_block_records_actions_like_dms(): void
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
