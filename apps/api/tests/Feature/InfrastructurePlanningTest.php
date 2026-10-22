<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\ManagedServerHistory;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use App\Services\Infrastructure\DomainTxtResolver;
use App\Services\Infrastructure\SshHostProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class InfrastructurePlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private ServerProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Demo', 'slug' => 'demo', 'created_by' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->provider = ServerProvider::query()->create(['name' => 'Example provider']);
        $this->actingAs($this->owner);
    }

    public function test_server_registration_encrypts_password_and_never_returns_it(): void
    {
        $url = $this->url('/servers');
        $data = $this->registration(['159.223.97.39']);
        $created = $this->postJson($url, $data)
            ->assertOk()->assertJsonPath('data', 'Added 1, Restored 0')
            ->assertJsonPath('servers.0.state', 'pending_preflight');
        $this->assertStringNotContainsString($data['password'], $created->getContent());
        $server = ManagedServer::query()->firstOrFail();
        $this->assertSame('PRIMARY RELAY', $server->name);
        $this->assertSame($this->provider->id, $server->servers_provider_id);
        $this->assertSame($data['expire_at'], $server->expire_at->format('Y-m-d'));
        $this->assertSame('password', $server->login_type);
        $credential = ServerCredential::query()->firstOrFail();
        $this->assertNotSame($data['password'], $credential->encrypted_secret);
        $this->assertSame($data['password'], Crypt::decryptString($credential->encrypted_secret));
        $this->assertSame('password', $credential->credential_type);

        $this->postJson($url, $data)->assertOk()->assertJsonPath('data', 'Added 0, Restored 0');
        $this->assertSame(1, ManagedServer::query()->count());
        $this->assertSame(1, ServerCredential::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.registered')->count());

        $this->postJson($url, [...$data, 'ips' => ['not-an-ip']])
            ->assertOk()->assertJsonPath('data', 'Added 0, Restored 0');
        $this->assertSame(1, ManagedServer::query()->count());

        $this->postJson($url, [...$data, 'ips' => ['10.8.0.4']])
            ->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');
        $this->assertDatabaseHas('managed_servers', ['ip_address' => '10.8.0.4']);
        $this->postJson($url, [...$data, 'ips' => [' 159.223.97.41 ']])
            ->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');
        $this->assertDatabaseHas('managed_servers', ['ip_address' => '159.223.97.41']);
        $this->assertSame(3, ManagedServer::query()->count());
    }

    public function test_multi_ip_registration_reports_added_duplicate_and_per_ip_cardinality(): void
    {
        $url = $this->url('/servers');
        $data = $this->registration(['159.223.97.39', '159.223.97.40'], ['username' => 'mailer']);

        $this->postJson($url, $data)->assertOk()->assertJsonPath('added', 2)
            ->assertJsonPath('servers.0.ip_address', '159.223.97.39')
            ->assertJsonPath('servers.1.ip_address', '159.223.97.40');
        $this->assertSame(2, ManagedServer::query()->count());
        $this->assertSame(2, ServerCredential::query()->count());
        $this->assertSame(2, ManagedServer::query()->where('ssh_username', 'mailer')->count());

        $this->postJson($url, $data)->assertOk()->assertJsonPath('data', 'Added 0, Restored 0');
        $this->assertSame(2, ManagedServer::query()->count());

        $this->postJson($url, [...$data, 'ips' => ['159.223.97.39', '159.223.97.41']])
            ->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');
        $this->assertSame(3, ManagedServer::query()->count());
    }

    public function test_registration_restores_a_soft_deleted_server_by_ip_and_refreshes_its_credentials(): void
    {
        $url = $this->url('/servers');
        $this->postJson($url, $this->registration(['159.223.97.40']))->assertOk();
        $server = ManagedServer::query()->firstOrFail();
        $server->delete();

        $this->postJson($url, $this->registration(['159.223.97.40'], [
            'name' => 'replacement relay', 'password' => 'replacement-secret',
        ]))->assertOk()->assertJsonPath('data', 'Added 0, Restored 1');

        $server->refresh();
        $this->assertFalse($server->trashed());
        $this->assertSame('REPLACEMENT RELAY', $server->name);
        $this->assertSame('replacement-secret', Crypt::decryptString($server->credential->encrypted_secret));
        $this->assertSame(1, ManagedServer::query()->count());
        $this->assertSame(1, ServerCredential::query()->count());
    }

    public function test_dms_registration_form_renders_field_contract_and_saves_multiple_server_ips(): void
    {
        $page = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers/create';
        $this->get($page)->assertOk()
            ->assertSee('ADD SERVERS')
            ->assertSee('TEST CONNECTION')
            ->assertSee('Expire At:')
            ->assertSee('Password Type:')
            ->assertSee('placeholder="Ip Or Ips"', false)
            ->assertSee('data-live-search="true"', false);
        $this->postJson('/app/workspaces/'.$this->workspace->id.'/infrastructure/servers',
            $this->registration(['159.223.97.39', '159.223.97.40'], ['username' => 'mailer']))
            ->assertOk()->assertJsonPath('data', 'Added 2, Restored 0');
        $this->assertSame(2, ManagedServer::query()->count());
        $this->assertSame(2, ManagedServer::query()->where('ssh_username', 'mailer')->count());
    }

    public function test_provider_dropdown_preserves_the_observed_dms_option_order(): void
    {
        $expected = ['Digital', 'digital', 'Ovh', 'Linode', 'linode', 'ikoula', 'vultr', 'Azure', 'reg'];
        foreach ($expected as $name) {
            ServerProvider::query()->create(['name' => $name]);
        }

        $response = $this->get('/app/workspaces/'.$this->workspace->id.'/infrastructure/servers/create')->assertOk();
        $html = $response->getContent();
        $positions = array_map(fn (string $name) => strpos($html, '>'.$name.'</option>'), $expected);
        $orderedPositions = $positions;
        sort($orderedPositions);

        $this->assertNotContains(false, $positions);
        $this->assertSame($orderedPositions, $positions);
    }

    public function test_server_list_shell_matches_the_dms_page_contract(): void
    {
        $response = $this->get('/app/workspaces/'.$this->workspace->id.'/infrastructure/servers')->assertOk();

        $response->assertSee('<title>Servers - DMS</title>', false)
            ->assertSee('Servers')
            ->assertSee('RETURNED')
            ->assertSee('id="servers"', false)
            ->assertSee('data-returned="false"', false)
            ->assertSee('/dms-reference/plugins/datatables/datatables.min.css')
            ->assertSee('/dms-reference/plugins/datatables/datatables.mark.js')
            ->assertSee('/dms-reference/assets/js/tables_datatables.js')
            ->assertSee('/dms-reference/server-index.js')
            ->assertSee(route('console.infrastructure.servers.create', $this->workspace->id), false);
    }

    public function test_server_list_json_is_workspace_scoped_and_returned_filter_uses_soft_deletes(): void
    {
        $currentServer = $this->postJson(
            '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers',
            $this->registration(['159.223.97.39'], ['name' => 'Current workspace relay']),
        )->assertOk()->json('servers.0');

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other', 'slug' => 'other', 'created_by' => $this->owner->id,
        ]);
        $otherWorkspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->postJson(
            '/app/workspaces/'.$otherWorkspace->id.'/infrastructure/servers',
            $this->registration(['159.223.97.40'], ['name' => 'Other workspace relay']),
        )->assertOk();

        $listUrl = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $active = $this->getJson($listUrl)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $currentServer['id'])
            ->assertJsonPath('data.0.name', 'CURRENT WORKSPACE RELAY')
            ->assertJsonPath('data.0.ip', '159.223.97.39')
            ->assertJsonPath('data.0.provider.name', $this->provider->name)
            ->assertJsonPath('data.0.is_installed', false)
            ->assertJsonPath('data.0.is_locked', false)
            ->assertJsonPath('data.0.allowed_users', [])
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.ips_count', null);
        $this->assertStringNotContainsString('private-example-password', $active->getContent());
        $this->assertStringNotContainsString('OTHER WORKSPACE RELAY', $active->getContent());

        ManagedServer::query()->whereKey($currentServer['id'])->firstOrFail()->delete();

        $this->getJson($listUrl)->assertOk()->assertJsonCount(0, 'data');
        $returned = $this->getJson($listUrl.'?returned=true')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $currentServer['id']);
        $this->assertNotNull($returned->json('data.0.deleted_at'));
        $this->assertStringNotContainsString('private-example-password', $returned->getContent());
    }

    public function test_server_return_is_soft_delete_locked_servers_cannot_be_returned_and_returned_servers_can_be_restored(): void
    {
        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $this->postJson($base, $this->registration(['159.223.97.39']))->assertOk();
        $this->postJson($base, $this->registration(['159.223.97.40']))->assertOk();
        $servers = ManagedServer::query()->orderBy('ip_address')->get();
        $returnable = $servers[0];
        $locked = $servers[1];

        $this->deleteJson($base, ['ids' => [$returnable->id]])->assertOk()
            ->assertJsonPath('data', 'Servers returned')->assertJsonPath('returned', 1);
        $this->assertSoftDeleted('managed_servers', ['id' => $returnable->id]);

        $this->patchJson($base.'/'.$locked->id.'/lock', ['status' => true])->assertOk()
            ->assertJsonPath('data', 'Changed successfully');
        $lockedRow = collect($this->getJson($base)->assertOk()->json('data'))->firstWhere('id', $locked->id);
        $this->assertTrue($lockedRow['is_locked']);
        $this->assertFalse($lockedRow['can_return']);
        $this->deleteJson($base, ['ids' => [$locked->id]])->assertStatus(400)->assertJsonPath('data', 'Failed');
        $this->assertDatabaseHas('managed_servers', ['id' => $locked->id, 'deleted_at' => null, 'is_locked' => true]);

        $this->getJson($base.'?returned=true')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $returnable->id);
        $this->patchJson($base.'/'.$returnable->id.'/restore')->assertOk()->assertJsonPath('data', 'Server restored');
        $this->assertDatabaseHas('managed_servers', ['id' => $returnable->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'server.returned', 'subject_id' => $returnable->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'server.restored', 'subject_id' => $returnable->id]);
    }

    public function test_server_allowed_users_can_be_managed_individually_and_in_bulk_with_workspace_scope(): void
    {
        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $mailer = User::factory()->create();
        $anotherMailer = User::factory()->create();
        $this->workspace->users()->attach($mailer->id, ['role' => 'operator']);
        $this->workspace->users()->attach($anotherMailer->id, ['role' => 'operator']);
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other', 'slug' => 'other', 'created_by' => $this->owner->id,
        ]);
        $otherWorkspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $outsideUser = User::factory()->create();
        $otherWorkspace->users()->attach($outsideUser->id, ['role' => 'operator']);

        $this->postJson($base, $this->registration(['159.223.97.39']))->assertOk();
        $this->postJson($base, $this->registration(['159.223.97.40']))->assertOk();
        $servers = ManagedServer::query()->orderBy('ip_address')->get();
        $first = $servers[0];
        $second = $servers[1];

        $this->putJson($base.'/'.$first->id.'/users', ['users' => [$mailer->id]])->assertOk()
            ->assertJsonPath('data', 'Updated successfully');
        $this->putJson($base.'/'.$first->id.'/users', ['users' => [$this->owner->id]])->assertUnprocessable();
        $this->assertDatabaseHas('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $first->id, 'user_id' => $mailer->id,
        ]);
        $this->get($base.'/'.$first->id.'/users')->assertOk()
            ->assertSee($mailer->email.' | '.$mailer->name)
            ->assertSee('checked', false);

        $this->putJson($base.'/'.$first->id.'/users', ['users' => [$outsideUser->id]])->assertUnprocessable();
        $this->assertDatabaseHas('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $first->id, 'user_id' => $mailer->id,
        ]);

        $this->get($base.'/bulk-users?ids[]='.$first->id.'&ids[]='.$second->id)->assertOk()
            ->assertSee('Servers Allowed Users')
            ->assertSee($first->name.' - '.$first->id)
            ->assertSee($second->name.' - '.$second->id);
        $this->putJson($base.'/bulk-users', [
            'servers' => [$first->id, $second->id], 'users' => [$anotherMailer->id],
        ])->assertOk()->assertJsonPath('data', 'Updated successfully');
        $this->assertDatabaseHas('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $first->id, 'user_id' => $anotherMailer->id,
        ]);
        $this->assertDatabaseHas('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $second->id, 'user_id' => $anotherMailer->id,
        ]);
        $this->assertDatabaseMissing('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $first->id, 'user_id' => $mailer->id,
        ]);
        $this->putJson($base.'/'.$first->id.'/users', ['users' => []])->assertOk();
        $this->assertDatabaseMissing('managed_server_user', [
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $first->id,
        ]);

        $this->assertSame(4, AuditEvent::query()->where('action', 'server.allowed_users_updated')->count());
    }

    public function test_server_status_toggle_is_recorded_and_all_management_routes_require_workspace_admin(): void
    {
        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $this->postJson($base, $this->registration(['159.223.97.39']))->assertOk();
        $server = ManagedServer::query()->firstOrFail();
        $this->patchJson($base.'/'.$server->id, ['status' => false])->assertOk()
            ->assertJsonPath('data', 'Changed successfully');
        $this->assertDatabaseHas('managed_servers', ['id' => $server->id, 'is_active' => false]);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->id, ['role' => 'operator']);
        $this->actingAs($operator)->putJson($base.'/'.$server->id.'/users', ['users' => []])->assertForbidden();
        $this->actingAs($operator)->patchJson($base.'/'.$server->id.'/lock', ['status' => true])->assertForbidden();
        $this->actingAs($operator)->deleteJson($base, ['ids' => [$server->id]])->assertStatus(400)->assertJsonPath('data', 'Failed');
        $this->actingAs($this->owner)->assertDatabaseHas('audit_events', [
            'action' => 'server.status_changed', 'subject_id' => $server->id,
        ]);
    }

    public function test_assigned_workspace_operator_sees_only_assigned_servers_and_can_toggle_or_return_unlocked_servers(): void
    {
        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $this->postJson($base, $this->registration(['159.223.97.39']))->assertOk();
        $this->postJson($base, $this->registration(['159.223.97.40']))->assertOk();
        $this->postJson($base, $this->registration(['159.223.97.41']))->assertOk();
        $servers = ManagedServer::query()->orderBy('ip_address')->get();
        $assigned = $servers[0];
        $unassigned = $servers[1];
        $otherAssigned = $servers[2];
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $this->workspace->users()->attach($operator->id, ['role' => 'operator']);
        $this->workspace->users()->attach($otherOperator->id, ['role' => 'operator']);
        DB::table('managed_server_user')->insert([
            'workspace_id' => $this->workspace->id,
            'managed_server_id' => $assigned->id,
            'user_id' => $operator->id,
        ]);
        DB::table('managed_server_user')->insert([
            'workspace_id' => $this->workspace->id,
            'managed_server_id' => $otherAssigned->id,
            'user_id' => $otherOperator->id,
        ]);
        $otherAssigned->delete();

        $this->actingAs($operator)->getJson($base)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonPath('data.0.can_manage', false)
            ->assertJsonPath('data.0.can_operate', true)
            ->assertJsonPath('data.0.can_return', true);
        $this->getJson($base.'?returned=true')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($base.'/'.$assigned->id, ['status' => false])->assertOk()
            ->assertJsonPath('data', 'Changed successfully');
        $this->patchJson($base.'/'.$unassigned->id, ['status' => false])->assertForbidden();
        $this->patchJson($base.'/'.$assigned->id.'/lock', ['status' => true])->assertForbidden();
        $this->putJson($base.'/'.$assigned->id.'/users', ['users' => []])->assertForbidden();
        $this->deleteJson($base, ['ids' => [$assigned->id]])->assertOk()->assertJsonPath('data', 'Servers returned');
        $this->assertSoftDeleted('managed_servers', ['id' => $assigned->id]);
        $this->getJson($base.'?returned=true')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_server_history_page_and_datatable_are_scoped_and_show_status_snapshots(): void
    {
        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/servers';
        $server = $this->postJson($base, $this->registration(['159.223.97.39']))->assertOk()->json('servers.0');
        $managedServer = ManagedServer::query()->findOrFail($server['id']);
        ManagedServerHistory::query()->create([
            'workspace_id' => $this->workspace->id,
            'managed_server_id' => $managedServer->id,
            'is_ssh_down' => false,
            'is_apache_down' => true,
            'is_pmta_down' => false,
        ]);
        ManagedServerHistory::query()->create([
            'workspace_id' => $this->workspace->id,
            'managed_server_id' => $managedServer->id,
            'is_ssh_down' => true,
            'is_apache_down' => false,
            'is_pmta_down' => true,
        ]);

        $historyUrl = route('console.infrastructure.servers.history', [$this->workspace->id, $managedServer->id]);
        $this->get($historyUrl)->assertOk()
            ->assertSee('HISTORIES')
            ->assertSee('data-source-url="'.$historyUrl.'"', false)
            ->assertSee('/dms-reference/server-history.js');

        $this->getJson($historyUrl.'?'.http_build_query([
            'draw' => 7,
            'start' => 0,
            'length' => 10,
            'order' => [['column' => 0, 'dir' => 'desc']],
        ]))->assertOk()
            ->assertJsonPath('draw', 7)
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 2)
            ->assertJsonPath('data.0.is_ssh_down', true)
            ->assertJsonPath('data.0.is_pmta_down', true);

        $this->getJson($base)->assertOk()
            ->assertJsonPath('data.0.history.is_ssh_down', true)
            ->assertJsonPath('data.0.history.is_apache_down', false)
            ->assertJsonPath('data.0.history.is_pmta_down', true);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->id, ['role' => 'operator']);
        $this->actingAs($operator)->getJson($historyUrl.'?draw=1')->assertForbidden();
    }

    public function test_bulk_install_page_matches_dms_controls_and_keeps_remote_install_disabled(): void
    {
        $url = '/app/workspaces/'.$this->workspace->id.'/infrastructure/bulk-install';
        $response = $this->get($url)->assertOk();

        $response->assertSee('<title>Bulk Install - DMS</title>', false)
            ->assertSee('Bulk Install Server')
            ->assertSee('id="pmta_version"', false)
            ->assertSee('<option value="40" selected>PMTA 4.0</option>', false)
            ->assertSee('PMTA 4.5')->assertSee('PMTA 5.0')
            ->assertSee('id="tracking"', false)->assertSee('id="proxy"', false)
            ->assertSee('id="imap"', false)->assertSee('id="spf"', false)
            ->assertSee('id="dkim"', false)->assertSee('id="dmarc"', false)
            ->assertSee('id="https"', false)
            ->assertSee('SERVER NAME')->assertSee('SERVER ID')->assertSee('SERVER IP')
            ->assertSee('SELECTED DOMAIN')->assertSee('APPLY')
            ->assertSee('This Grid Is Empty')
            ->assertSee('id="install" disabled', false)
            ->assertSee('/dms-reference/bulk-install.js')
            ->assertSee('/dms-reference/assets/css/theme.css')
            ->assertDontSee('fingerprint');

        $this->assertStringNotContainsString('bulk-install/install', $response->getContent());
    }

    public function test_bulk_install_eligibility_uses_dms_owner_active_install_and_external_domain_filters(): void
    {
        $this->owner->forceFill(['username' => 'mailer'])->save();
        $this->postJson($this->url('/servers'), $this->registration(['159.223.97.39'], ['name' => 'A_MAILER_RELAY']))->assertOk();
        $this->postJson($this->url('/servers'), $this->registration(['159.223.97.40'], ['name' => 'A_MAILER_INACTIVE']))->assertOk();
        $this->postJson($this->url('/servers'), $this->registration(['159.223.97.41'], ['name' => 'OTHER_RELAY']))->assertOk();

        $eligibleServer = ManagedServer::query()->where('name', 'A_MAILER_RELAY')->firstOrFail();
        ManagedServer::query()->where('name', 'A_MAILER_INACTIVE')->update(['is_active' => false]);
        ManagedServer::query()->where('name', 'OTHER_RELAY')->update(['is_installed' => true]);

        $activeProvider = DomainProvider::query()->create(['name' => 'Example provider', 'is_active' => true]);
        $inactiveProvider = DomainProvider::query()->create(['name' => 'Inactive provider', 'is_active' => false]);
        $eligibleDomain = $this->postJson($this->url('/domains'), ['name' => 'bulk.example.org'])->assertCreated()->json('data');
        $newDomain = $this->postJson($this->url('/domains'), ['name' => 'new.example.org'])->assertCreated()->json('data');
        $ownedDomain = $this->postJson($this->url('/domains'), ['name' => 'owned.example.org'])->assertCreated()->json('data');
        $inactiveDomain = $this->postJson($this->url('/domains'), ['name' => 'inactive.example.org'])->assertCreated()->json('data');
        $usedDomain = $this->postJson($this->url('/domains'), ['name' => 'used.example.org'])->assertCreated()->json('data');

        SendingDomain::query()->whereKey($eligibleDomain['id'])->update([
            'domains_provider_id' => $activeProvider->id, 'status' => 'free', 'is_our' => false, 'is_active' => true,
        ]);
        SendingDomain::query()->whereKey($newDomain['id'])->update([
            'domains_provider_id' => $activeProvider->id, 'status' => 'new', 'is_our' => false, 'is_active' => true,
        ]);
        SendingDomain::query()->whereKey($ownedDomain['id'])->update([
            'domains_provider_id' => $activeProvider->id, 'status' => 'new', 'is_our' => true, 'is_active' => true,
        ]);
        SendingDomain::query()->whereKey($inactiveDomain['id'])->update([
            'domains_provider_id' => $inactiveProvider->id, 'status' => 'new', 'is_our' => false, 'is_active' => true,
        ]);
        SendingDomain::query()->whereKey($usedDomain['id'])->update([
            'domains_provider_id' => $activeProvider->id, 'status' => 'used', 'is_our' => false, 'is_active' => true,
        ]);

        $eligible = app(DmsBulkInstallEligibility::class)->for($this->owner, $this->workspace);
        $this->assertSame([$eligibleServer->id], $eligible['servers']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$eligibleDomain['id'], $newDomain['id']], $eligible['domains']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([
            'bulk.example.org | STATUS: free | IS OUR: No',
            'new.example.org | STATUS: new | IS OUR: No',
        ], $eligible['domains']->pluck('label')->all());
        $this->assertFalse($eligible['missing_username']);

        $this->get('/app/workspaces/'.$this->workspace->id.'/infrastructure/bulk-install')
            ->assertOk()
            ->assertSee('A_MAILER_RELAY')
            ->assertSee((string) $eligibleServer->id)
            ->assertSee('bulk.example.org | STATUS: free | IS OUR: No')
            ->assertSee('new.example.org | STATUS: new | IS OUR: No')
            ->assertDontSee('A_MAILER_INACTIVE')
            ->assertDontSee('OTHER_RELAY')
            ->assertDontSee('owned.example.org')
            ->assertDontSee('inactive.example.org')
            ->assertDontSee('used.example.org');
    }

    public function test_server_registration_encrypts_an_ssh_private_key_as_a_credential(): void
    {
        $privateKey = "-----BEGIN OPENSSH PRIVATE KEY-----\nsynthetic-test-key\n-----END OPENSSH PRIVATE KEY-----";
        $data = $this->registration(['159.223.97.39'], [
            'name' => 'Key-auth relay', 'username' => 'mailer', 'login_type' => 'ssh_key', 'password' => $privateKey,
        ]);
        $created = $this->postJson($this->url('/servers'), $data)->assertOk();

        $credential = ServerCredential::query()->firstOrFail();
        $this->assertSame('ssh_key', $credential->credential_type);
        $this->assertSame($privateKey, Crypt::decryptString($credential->encrypted_secret));
        $this->assertStringNotContainsString($privateKey, $created->getContent());
    }

    public function test_local_test_connection_never_requests_a_host_fingerprint_or_persists_test_data(): void
    {
        $url = $this->url('/servers/test-connection');
        $data = [
            'ips' => ['159.223.97.39'], 'port' => 22, 'username' => 'root',
            'login_type' => 'password', 'password' => 'private-example-password',
        ];
        $probe = Mockery::mock(SshHostProbe::class);
        $probe->shouldReceive('testConnection')->once()
            ->with('159.223.97.39', 22, 'root', 'password', 'private-example-password')
            ->andReturn([
                'ip' => '159.223.97.39', 'status' => 'connected',
                'message' => 'CONNECTED ON IP: 159.223.97.39 PORT : 22',
            ]);
        $this->app->instance(SshHostProbe::class, $probe);

        $result = $this->postJson($url, $data)->assertOk()
            ->assertJsonPath('results.0.status', 'connected')
            ->assertJsonPath('data.0', 'CONNECTED ON IP: 159.223.97.39 PORT : 22')
            ->assertJsonMissingPath('host_fingerprints')
            ->assertJsonMissingPath('results.0.observed_fingerprint');

        $this->assertSame(0, ManagedServer::query()->count());
        $this->assertSame(0, ServerCredential::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertStringNotContainsString('fingerprint', strtolower($result->getContent()));
    }

    public function test_connection_probe_blocks_private_reserved_and_documentation_ranges_before_socket_creation(): void
    {
        $probe = app(SshHostProbe::class);
        $blockedAddresses = [
            '10.0.0.7', '100.64.0.7', '127.0.0.1', '192.0.2.1', '198.51.100.1',
            '203.0.113.87', '2001:db8::1', '2002::1', '3fff::1', '64:ff9b::a00:1',
            '64:ff9b:1::1',
            '100:0:0:1::1', '2001:2::1', '2001:10::1', '5f00::1', '::ffff:192.0.2.1',
        ];

        foreach ($blockedAddresses as $ip) {
            $result = $probe->testConnection($ip, 22, 'root', 'password', 'synthetic-only-secret');

            $this->assertSame('blocked', $result['status'], $ip);
            $this->assertArrayNotHasKey('observed_fingerprint', $result, $ip);
        }
    }

    public function test_domain_must_be_verified_before_plan_and_plan_never_enables_install(): void
    {
        $domain = $this->postJson($this->url('/domains'), ['name' => 'Mail.Example.Org'])
            ->assertCreated()->json('data');
        $this->assertSame('mail.example.org', $domain['name']);
        $server = $this->postJson($this->url('/servers'), $this->registration(['159.223.97.39']))
            ->assertOk()->json('servers.0');

        $planData = [
            'server_id' => $server['id'], 'domain_id' => $domain['id'], 'pmta_version' => '4.0',
            'options' => ['tracking' => true, 'dkim' => true, 'spf' => true],
        ];
        $this->postJson($this->url('/installation-plans'), $planData)->assertUnprocessable();

        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')->once()
            ->with('_alphalink-verify.mail.example.org')
            ->andReturn([$domain['verification_value']]);
        $this->app->instance(DomainTxtResolver::class, $resolver);
        $this->postJson($this->url('/domains/'.$domain['id'].'/verify'))->assertOk()
            ->assertJsonPath('verified', true);
        $this->assertSame('verified', SendingDomain::query()->findOrFail($domain['id'])->state);

        $plan = $this->postJson($this->url('/installation-plans'), $planData)
            ->assertCreated()->assertJsonPath('data.preview.pmta.mandatory', true)
            ->assertJsonPath('data.preview.execution_enabled', false)
            ->assertJsonPath('data.options.tracking', true)
            ->assertJsonPath('data.options.proxy', false)
            ->json('data');
        $this->assertNotEmpty($plan['preview']['blockers']);
        $this->assertSame('blocked', $plan['state']);
        $this->postJson($this->url('/installation-plans/'.$plan['id'].'/execute'))->assertNotFound();
    }

    public function test_installation_plan_blocks_platforms_dms_bulk_install_would_reject(): void
    {
        $domain = $this->postJson($this->url('/domains'), ['name' => 'platform.example.org'])
            ->assertCreated()->json('data');
        $server = $this->postJson($this->url('/servers'), $this->registration(['159.223.97.40']))
            ->assertOk()->json('servers.0');
        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')->once()
            ->with('_alphalink-verify.platform.example.org')
            ->andReturn([$domain['verification_value']]);
        $this->app->instance(DomainTxtResolver::class, $resolver);
        $this->postJson($this->url('/domains/'.$domain['id'].'/verify'))->assertOk();

        ManagedServer::query()->whereKey($server['id'])->update([
            'state' => 'verified',
            'host_facts' => json_encode([
                'os_id' => 'ubuntu',
                'os_version' => '24.04',
                'os_pretty_name' => 'Ubuntu 24.04.1 LTS',
                'architecture' => 'aarch64',
            ], JSON_THROW_ON_ERROR),
        ]);

        $plan = $this->postJson($this->url('/installation-plans'), [
            'server_id' => $server['id'],
            'domain_id' => $domain['id'],
            'pmta_version' => '4.0',
        ])->assertCreated()->json('data');

        $this->assertContains('DMS bulk installation supports x86_64 architecture only.', $plan['preview']['blockers']);
        $this->assertContains(
            'DMS bulk installation supports CentOS Linux 7, CentOS Stream 9, Rocky Linux 9, and AlmaLinux 9 only.',
            $plan['preview']['blockers'],
        );
        $this->assertFalse($plan['preview']['execution_enabled']);
    }

    public function test_owner_scope_and_role_are_enforced(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->getJson($this->url('/infrastructure'))->assertNotFound();
        $this->workspace->users()->attach($other->id, ['role' => 'operator']);
        $this->actingAs($other)->getJson($this->url('/infrastructure'))->assertForbidden();
        $this->actingAs($other)->postJson($this->url('/domains'), ['name' => 'example.org'])->assertForbidden();
    }

    public function test_preflight_automatically_pins_the_first_host_key_without_user_input(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $server = $this->postJson($this->url('/servers'), $this->registration(['159.223.97.39']))
            ->assertOk()->json('servers.0');
        $fingerprint = 'SHA256:'.str_repeat('A', 43);
        $probe = Mockery::mock(SshHostProbe::class);
        $probe->shouldReceive('inspect')->once()
            ->withArgs(fn ($host, $password, $credentialType) => $host->id === $server['id']
                && $password === 'private-example-password' && $credentialType === 'password')
            ->andReturn(['fingerprint' => $fingerprint, 'facts' => [
                'os_id' => 'almalinux', 'os_version' => '9.6', 'os_pretty_name' => 'AlmaLinux 9.6', 'architecture' => 'x86_64',
                'package_manager' => 'dnf', 'disk_available_kb' => 10485760,
                'pmta_binary_present' => false,
            ]]);
        $this->app->instance(SshHostProbe::class, $probe);

        $result = $this->postJson($this->url('/servers/'.$server['id'].'/preflight'))
            ->assertOk()->assertJsonPath('data.state', 'verified')
            ->assertJsonPath('data.host_facts.os_id', 'almalinux')
            ->assertJsonPath('data.host_facts.os_pretty_name', 'AlmaLinux 9.6');
        $this->assertStringNotContainsString('private-example-password', $result->getContent());
        $this->assertSame(1, AuditEvent::query()->where('action', 'server.preflight_verified')->count());
        $this->assertStringNotContainsString('fingerprint', strtolower($result->getContent()));
        $this->assertSame($fingerprint, ManagedServer::findOrFail($server['id'])->expected_host_fingerprint);
        $this->assertSame($fingerprint, ManagedServer::findOrFail($server['id'])->observed_host_fingerprint);
    }

    public function test_browser_flow_renders_and_does_not_flash_root_password(): void
    {
        $page = '/app/workspaces/'.$this->workspace->id.'/infrastructure';
        $this->get($page)->assertOk()->assertSee('Server installation')->assertSee('PowerMTA 4.0r8');
        $createPage = $page.'/servers/create';
        $this->get($createPage)->assertOk()->assertSee('Select Server Provider')->assertSee('TEST CONNECTION')
            ->assertDontSee('fingerprint');
        $this->assertStringNotContainsString('fingerprint', strtolower($this->get($page)->getContent()));
        $invalid = $this->postJson($page.'/servers', [
            'servers_provider_id' => $this->provider->id,
            'name' => 'Relay', 'ips' => ['159.223.97.39'], 'port' => 22,
            'username' => 'root', 'login_type' => 'password', 'password' => 'private-example-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expire_at']);
        $this->assertStringNotContainsString('private-example-password', $invalid->getContent());

        $created = $this->postJson($page.'/servers', $this->registration(['159.223.97.39']))
            ->assertOk()->assertJsonPath('data', 'Added 1, Restored 0');
        $this->assertStringNotContainsString('private-example-password', $created->getContent());

        $this->post($page.'/domains', ['name' => 'example.org'])->assertRedirect($page);
        $domain = SendingDomain::query()->firstOrFail();
        $this->get($page)->assertOk()
            ->assertSee('159.223.97.39')
            ->assertSee($domain->verification_token)
            ->assertSee('Check DNS record');

        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')->once()->andReturn([$domain->verification_token]);
        $this->app->instance(DomainTxtResolver::class, $resolver);
        $this->post($page.'/domains/'.$domain->id.'/verify')->assertRedirect($page);

        $response = $this->post($page.'/plans', [
            'server_id' => ManagedServer::query()->firstOrFail()->id,
            'domain_id' => $domain->id,
            'pmta_version' => '5.0',
            'tracking' => '1', 'dkim' => '1',
        ]);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Installation blocked')
            ->assertSee('Tracking + HTTPS')
            ->assertSee('Install server');
    }

    private function url(string $suffix): string
    {
        return '/api/v1/workspaces/'.$this->workspace->id.$suffix;
    }

    private function registration(array $ips, array $overrides = []): array
    {
        return array_replace([
            'servers_provider_id' => $this->provider->id,
            'name' => 'Primary relay',
            'ips' => $ips,
            'port' => 22,
            'username' => 'root',
            'login_type' => 'password',
            'password' => 'private-example-password',
            'expire_at' => now()->addDays(30)->format('Y-m-d'),
        ], $overrides);
    }
}
