<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpHistory;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsServerIpManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ManagedServer $server;

    private SendingDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'IP detail test',
            'slug' => 'ip-detail-test',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $provider = DomainProvider::query()->create(['name' => 'IP test provider', 'is_active' => true]);
        $this->server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_IP_RELAY',
            'provider' => 'IP test provider',
            'ip_address' => '192.0.2.40',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ip-detail.example.test',
            'state' => 'verified',
            'verification_token' => 'test-domain-token',
            'created_by' => $this->owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_my_ips_page_and_data_are_scoped_to_active_ips_on_active_installed_workspace_servers(): void
    {
        $visibleIp = $this->createIp();
        ManagedServerIpHistory::factory()->forManagedIp($visibleIp)->create([
            'ptr' => 'relay.example.test',
            'ip_listed_spamhaus' => true,
            'sender_score' => '92',
        ]);
        $this->createIp(['ip' => '192.0.2.44', 'is_active' => false]);

        $uninstalledServer = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'UNINSTALLED_IP_RELAY',
            'provider' => 'IP test provider',
            'ip_address' => '192.0.2.45',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $uninstalledServer->getKey(),
            'sending_domain_id' => $this->domain->getKey(),
            'ip' => '192.0.2.45',
            'type' => 'ipv4',
            'dns' => 'uninstalled.example.test',
            'is_active' => true,
        ]);

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other IP workspace',
            'slug' => 'other-ip-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $otherServer = ManagedServer::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'name' => 'OTHER_WORKSPACE_RELAY',
            'provider' => 'IP test provider',
            'ip_address' => '192.0.2.46',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherDomain = SendingDomain::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'name' => 'other-ip.example.test',
            'state' => 'verified',
            'verification_token' => 'other-ip-token',
            'created_by' => $otherOwner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'managed_server_id' => $otherServer->getKey(),
            'sending_domain_id' => $otherDomain->getKey(),
            'ip' => '192.0.2.46',
            'type' => 'ipv4',
            'dns' => 'other-ip.example.test',
            'is_active' => true,
        ]);

        $url = $this->url('/my-ips');
        $this->get($url)
            ->assertOk()
            ->assertSee('My IPs')
            ->assertSee('data-source-url="'.$this->cleanWorkspaceUrl($url).'"', false)
            ->assertSee('Webmail access is not enabled', false);

        $response = $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleIp->getKey())
            ->assertJsonPath('data.0.server.name', $this->server->name)
            ->assertJsonPath('data.0.history.ptr', 'relay.example.test')
            ->assertJsonPath('data.0.history.sender_score', '92')
            ->assertJsonPath('data.0.history.ip_listed_spamhaus', true);

        self::assertSame([$visibleIp->getKey()], collect($response->json('data'))->pluck('id')->all());

        $filteredUrl = $this->url('/my-ips?server_id='.urlencode((string) $otherServer->getKey()));
        $this->get($filteredUrl)->assertOk();
        $this->getJson($filteredUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_my_ips_export_contains_only_the_selected_workspace_server_rows(): void
    {
        $ip = $this->createIp();
        $url = route('console.infrastructure.my-ips.export', [
            'workspace' => $this->workspace->getKey(),
            'server_id' => $this->server->getKey(),
        ]);

        $response = $this->get($url)->assertOk();
        $csv = $response->streamedContent();

        self::assertStringContainsString('server,"ssh down","apache down","pmta down",ip,"ip type","ip domain"', $csv);
        self::assertStringContainsString($ip->ip, $csv);
        self::assertStringContainsString($ip->dns, $csv);
        self::assertStringNotContainsString('192.0.2.44', $csv);
    }

    public function test_owner_views_server_ips_vmtas_and_smtp_with_latest_ip_history(): void
    {
        $ip = $this->createIp();
        ManagedServerIpHistory::factory()->forManagedIp($ip)->create([
            'ptr' => 'relay.example.test',
            'ip_listed_spamhaus' => true,
            'sender_score' => '92',
        ]);
        $vmta = $this->createMta($ip, 'vmta');
        $smtp = $this->createMta($ip, 'smtp');

        $pageUrl = $this->url('/servers/'.$this->server->id.'/ips');
        $this->get($pageUrl)->assertOk()
            ->assertSee('IP AND MTA')
            ->assertSee('id="server-ip-tabs"', false)
            ->assertSee('data-toggle="tabs"', false)
            ->assertSee('data-vmta-url=', false)
            ->assertSee('data-smtp-url=', false)
            ->assertSee('server-ip-details.js');
        $this->getJson($pageUrl.'?draw=4&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.id', $ip->getKey())
            ->assertJsonPath('data.0.history.ptr', 'relay.example.test')
            ->assertJsonPath('data.0.history.ip_listed_spamhaus', true)
            ->assertJsonPath('data.0.histories_count', 1);

        $this->getJson($this->url('/servers/'.$this->server->id.'/mtas/vmta'))
            ->assertOk()->assertJsonPath('recordsTotal', 1)->assertJsonPath('data.0.id', $vmta->getKey())
            ->assertJsonPath('data.0.user.name', $this->owner->name);
        $this->getJson($this->url('/servers/'.$this->server->id.'/mtas/smtp'))
            ->assertOk()->assertJsonPath('recordsTotal', 1)->assertJsonPath('data.0.id', $smtp->getKey());
    }

    public function test_ip_and_mta_tables_default_to_newest_record_first(): void
    {
        $olderIp = $this->createIp(['ip' => '192.0.2.44']);
        $newerIp = $this->createIp(['ip' => '192.0.2.45']);
        $olderVmta = $this->createMta($olderIp, 'vmta');
        $newerVmta = $this->createMta($newerIp, 'vmta');

        $ipResponse = $this->getJson($this->url('/servers/'.$this->server->id.'/ips?draw=1&start=0&length=10'))
            ->assertOk();
        $vmtaResponse = $this->getJson($this->url('/servers/'.$this->server->id.'/mtas/vmta?draw=1&start=0&length=10'))
            ->assertOk();

        $this->assertSame([$newerIp->getKey(), $olderIp->getKey()], $ipResponse->json('data.*.id'));
        $this->assertSame([$newerVmta->getKey(), $olderVmta->getKey()], $vmtaResponse->json('data.*.id'));
    }

    public function test_ip_and_mta_status_changes_are_scoped_and_audited(): void
    {
        $ip = $this->createIp();
        $mta = $this->createMta($ip, 'vmta');

        $this->patchJson($this->url('/servers/'.$this->server->id.'/ips/'.$ip->getKey()), ['status' => false])
            ->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->patchJson($this->url('/servers/'.$this->server->id.'/mtas/'.$mta->getKey()), ['status' => false])
            ->assertOk()->assertJsonPath('data', 'Changed successfully');

        $this->assertFalse($ip->fresh()->is_active);
        $this->assertFalse($mta->fresh()->is_active);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'server.ip_status_changed',
            'subject_id' => $this->server->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'server.mta_status_changed',
            'subject_id' => $this->server->getKey(),
        ]);

        $this->patchJson($this->url('/servers/'.$this->server->id.'/ips/'.$ip->getKey()), ['status' => 'invalid'])
            ->assertUnprocessable();
        $this->assertFalse($ip->fresh()->is_active);
    }

    public function test_rejected_status_saves_return_the_dms_failure_response_without_audit(): void
    {
        $ip = $this->createIp();
        $mta = $this->createMta($ip, 'vmta');

        $ipSaveEvent = 'eloquent.saving: '.ManagedServerIp::class;
        Event::listen($ipSaveEvent, static fn (): bool => false);

        $this->patchJson($this->url('/servers/'.$this->server->id.'/ips/'.$ip->getKey()), ['status' => false])
            ->assertStatus(400)
            ->assertJsonPath('data', 'Failed');
        Event::forget($ipSaveEvent);

        $mtaSaveEvent = 'eloquent.saving: '.ManagedServerMta::class;
        Event::listen($mtaSaveEvent, static fn (): bool => false);

        $this->patchJson($this->url('/servers/'.$this->server->id.'/mtas/'.$mta->getKey()), ['status' => false])
            ->assertStatus(400)
            ->assertJsonPath('data', 'Failed');
        Event::forget($mtaSaveEvent);

        $this->assertTrue($ip->fresh()->is_active);
        $this->assertTrue($mta->fresh()->is_active);
        $this->assertDatabaseMissing('audit_events', ['action' => 'server.ip_status_changed']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'server.mta_status_changed']);
    }

    public function test_ip_history_route_returns_the_dms_history_table_contract(): void
    {
        $ip = $this->createIp();
        ManagedServerIpHistory::factory()->forManagedIp($ip)->create([
            'ptr' => 'relay.example.test',
            'sender_score' => '92',
        ]);
        $historyUrl = $this->url('/servers/'.$this->server->id.'/ips/'.$ip->getKey().'/history');

        $this->get($historyUrl)->assertOk()
            ->assertSee('HISTORIES')
            ->assertSee('data-source-url="'.$this->cleanWorkspaceUrl(route('console.infrastructure.servers.ips.history', [$this->workspace->id, $this->server->id, $ip->getKey()])).'"', false);
        $this->getJson($historyUrl.'?draw=9&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('draw', 9)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.ptr', 'relay.example.test')
            ->assertJsonPath('data.0.sender_score', '92');
    }

    public function test_ip_routes_allow_workspace_members_but_remain_workspace_scoped_and_limit_mta_tabs(): void
    {
        $ip = $this->createIp();
        $viewer = User::factory()->create();
        $this->workspace->users()->attach($viewer->getKey(), ['role' => 'viewer']);
        $pageUrl = $this->url('/servers/'.$this->server->id.'/ips');

        $this->actingAs($viewer)->get($pageUrl)->assertOk();
        $this->actingAs($viewer)->patchJson($this->url('/servers/'.$this->server->id.'/ips/'.$ip->getKey()), ['status' => false])
            ->assertOk()->assertJsonPath('data', 'Changed successfully');
        $this->assertFalse($ip->fresh()->is_active);
        $this->actingAs($this->owner)->get($this->url('/servers/'.$this->server->id.'/mtas/invalid'))->assertNotFound();

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other IP workspace',
            'slug' => 'other-ip-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $otherServer = ManagedServer::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'name' => 'OTHER_RELAY',
            'provider' => 'IP test provider',
            'ip_address' => '192.0.2.41',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherDomain = SendingDomain::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'name' => 'other-ip.example.test',
            'state' => 'verified',
            'verification_token' => 'other-domain-token',
            'created_by' => $otherOwner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        $otherIp = ManagedServerIp::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'managed_server_id' => $otherServer->getKey(),
            'sending_domain_id' => $otherDomain->getKey(),
            'ip' => '192.0.2.42',
            'type' => 'ipv4',
            'dns' => 'other-ip.example.test',
            'is_active' => true,
        ]);

        $this->get($this->url('/servers/'.$otherServer->getKey().'/ips'))->assertNotFound();
        $this->patchJson($this->url('/servers/'.$this->server->id.'/ips/'.$otherIp->getKey()), ['status' => false])
            ->assertNotFound();
        $this->assertTrue($otherIp->fresh()->is_active);
    }

    private function createIp(array $overrides = []): ManagedServerIp
    {
        return ManagedServerIp::query()->create(array_replace([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'sending_domain_id' => $this->domain->getKey(),
            'ip' => '192.0.2.43',
            'type' => 'ipv4',
            'dns' => 'ip-detail.example.test',
            'is_active' => true,
        ], $overrides));
    }

    private function createMta(ManagedServerIp $ip, string $type): ManagedServerMta
    {
        return ManagedServerMta::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $this->server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'ip_type' => $ip->type,
            'mta_type' => $type,
            'username' => 'owner',
            'is_active' => true,
            'user_id' => $this->owner->getKey(),
        ]);
    }

    private function url(string $suffix): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure'.$suffix;
    }
}
