<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGoogleAccount;
use App\Models\WorkspaceGoogleProject;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\PmtaRemoteConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsWorkspaceToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Workspace tools', 'slug' => 'workspace-tools-'.Str::lower(Str::random(8)), 'created_by' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_workspace_tool_matches_dms_push_controls_and_links_to_gsend_management(): void
    {
        $this->get(route('console.tools.workspace.index', $this->workspace->id))
            ->assertOk()->assertSee('PUSH WORKSPACE ACCOUNTS')->assertSee('SPLIT ON IPS')
            ->assertDontSee('workspace-project-form')->assertDontSee('workspace-account-form')
            ->assertSee('workspace-search-ips')->assertSee('workspace-search-projects')
            ->assertSee(route('console.gcampaigns.projects', $this->workspace->id))
            ->assertSee(route('console.gcampaigns.accounts', $this->workspace->id))
            ->assertSee('class="form-control selectpicker"', false)
            ->assertSee('bootstrap_select/bootstrap-select.js');
    }

    public function test_workspace_projects_and_accounts_are_separate_dms_nav_pages_and_workspace_scoped(): void
    {
        $project = $this->project();
        $account = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'email' => 'workspace-member@example.test',
        ]);

        $this->get(route('console.gcampaigns.projects', $this->workspace->id))
            ->assertOk()->assertSee('GMAIL PROJECTS')->assertSee($project->name)
            ->assertSee('SAVE & SYNC')->assertSee('workspace-project-table')
            ->assertSee(route('console.gcampaigns.accounts', [$this->workspace->id, 'project' => $project->id]))
            ->assertSee('datatables.min.js')->assertDontSee('BEGIN PRIVATE KEY');
        $otherProject = WorkspaceGoogleProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'other-admin@example.test',
            'service_account_json' => json_encode(['client_email' => 'svc@example.test', 'private_key' => 'synthetic-private-key'], JSON_THROW_ON_ERROR),
            'is_active' => true,
        ]);
        WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $otherProject->id,
            'email' => 'other-member@example.test',
        ]);
        $this->get(route('console.gcampaigns.accounts', $this->workspace->id))
            ->assertOk()->assertSee('GMAIL ACCOUNTS')->assertSee($account->email)
            ->assertSee('ENABLE SELECTED')->assertSee('DISABLE SELECTED')->assertSee('DELETE SELECTED')
            ->assertSee('workspace-account-table')->assertSee('datatables.min.js');
        $this->get(route('console.gcampaigns.accounts', [$this->workspace->id, 'project' => $project->id]))
            ->assertOk()->assertSee($account->email)->assertDontSee('other-member@example.test');

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other Workspace', 'slug' => 'other-workspace-'.Str::lower(Str::random(8)), 'created_by' => $this->owner->id,
        ]);
        $this->get(route('console.gcampaigns.projects', $otherWorkspace->id))->assertNotFound();
        $this->get(route('console.gcampaigns.accounts', $otherWorkspace->id))->assertNotFound();
    }

    public function test_project_creation_uses_dms_google_admin_scope_paginates_and_encrypts_credentials(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $secret = json_encode([
            'type' => 'service_account', 'client_email' => 'svc@example.test', 'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
        $adminRequests = 0;
        Http::fake(function (ClientRequest $request) use (&$adminRequests) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'synthetic-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
            }
            if (parse_url($request->url(), PHP_URL_PATH) === '/admin/directory/v1/users') {
                $adminRequests++;
                if ($adminRequests === 1) {
                    return Http::response(['users' => [['primaryEmail' => 'first@example.test']], 'nextPageToken' => 'page-2']);
                }
                if ($adminRequests === 2) {
                    return Http::response(['users' => [['primaryEmail' => 'second@example.test']]]);
                }

                return Http::response(['users' => [['primaryEmail' => 'second@example.test'], ['primaryEmail' => 'third@example.test']]]);
            }

            return Http::response([], 404);
        });

        $created = $this->postJson(route('console.tools.workspace.projects.store', $this->workspace->id), [
            'name' => 'admin@example.test', 'service_account_json' => $secret,
        ])->assertOk()->assertJsonPath('accounts', 2)->assertJsonPath('message', 'Project created and synced.');
        $project = WorkspaceGoogleProject::query()->sole();
        $this->assertSame('admin@example.test', $project->name);
        $this->assertSame(2, $project->accounts()->count());
        $this->assertSame(2, $adminRequests);
        $encrypted = DB::table('workspace_google_projects')->where('id', $project->id)->value('service_account_json');
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encrypted);
        $this->assertSame($secret, $project->service_account_json);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $this->jwtClaim((string) $request['assertion'], 'sub') === 'admin@example.test'
            && $this->jwtClaim((string) $request['assertion'], 'scope') === 'https://www.googleapis.com/auth/admin.directory.user');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer&maxResults=500'
            && $request->hasHeader('Authorization', 'Bearer synthetic-access-token'));
        $this->assertSame($created->json('project_id'), $project->id);
    }

    public function test_sync_removes_stale_accounts_and_preserves_status_for_accounts_still_in_directory(): void
    {
        $project = $this->project();
        WorkspaceGoogleAccount::query()->create(['workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'old@example.test', 'is_active' => true]);
        WorkspaceGoogleAccount::query()->create(['workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'kept@example.test', 'is_active' => false]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://admin.googleapis.com/admin/directory/v1/users*' => Http::response(['users' => [['primaryEmail' => 'kept@example.test'], ['primaryEmail' => 'new@example.test']]]),
        ]);

        $this->postJson(route('console.tools.workspace.projects.sync', [$this->workspace->id, $project->id]))
            ->assertOk()->assertJsonPath('accounts', 2);
        $this->assertDatabaseMissing('workspace_google_accounts', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('workspace_google_accounts', ['email' => 'kept@example.test', 'is_active' => false]);
        $this->assertDatabaseHas('workspace_google_accounts', ['email' => 'new@example.test', 'is_active' => true]);
    }

    public function test_google_sync_failure_disables_project_and_never_returns_private_key(): void
    {
        $project = $this->project();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://admin.googleapis.com/admin/directory/v1/users*' => Http::response(['error' => 'denied'], 403),
        ]);

        $this->postJson(route('console.tools.workspace.projects.sync', [$this->workspace->id, $project->id]))
            ->assertUnprocessable()->assertJsonPath('message', 'Google account synchronization failed.')
            ->assertJsonMissingPath('service_account_json')->assertJsonMissingPath('private_key');
        $this->assertFalse($project->fresh()->is_active);
        $this->assertStringContainsString('HTTP 403', $project->fresh()->sync_error);
    }

    public function test_account_management_routes_validate_and_scope_to_the_current_workspace(): void
    {
        $project = $this->project();
        $this->postJson(route('console.tools.workspace.accounts.store', $this->workspace->id), [
            'project_id' => $project->id, 'accounts' => [' First@Example.test ', 'not an email', 'first@example.test'],
        ])->assertOk()->assertJsonPath('added', 1);
        $account = WorkspaceGoogleAccount::query()->sole();
        $this->patchJson(route('console.tools.workspace.accounts.status', $this->workspace->id), [
            'accounts' => [$account->id], 'status' => false,
        ])->assertOk()->assertJsonPath('changed', 1);
        $this->assertFalse($account->fresh()->is_active);
        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), ['accounts' => [$account->id]])
            ->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $account->id]);
    }

    public function test_push_returns_dms_bundle_missing_error_before_any_server_write(): void
    {
        $project = $this->project();
        WorkspaceGoogleAccount::query()->create(['workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'user@example.test']);
        $ip = $this->ip();

        $this->postJson(route('console.tools.workspace.push', $this->workspace->id), [
            'ips' => [$ip->id], 'projects' => [$project->id], 'split_on_ips' => false,
        ])->assertBadRequest()->assertJsonPath('data', 'WORKSPACES Files Not Found');
        $this->assertDatabaseCount('managed_server_mtas', 0);
    }

    public function test_reset_deletes_only_selected_users_workspace_smtp_rows_then_reloads_pmta(): void
    {
        $ip = $this->ip();
        $mta = ManagedServerMta::query()->create([
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $ip->managed_server_id,
            'managed_server_ip_id' => $ip->id, 'ip' => $ip->ip, 'dns' => $ip->dns, 'ip_type' => $ip->type,
            'mta_type' => 'smtp', 'username' => 'user@example.test', 'user_id' => $this->owner->id,
        ]);
        $server = $ip->server;
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->with('rm -rf '.escapeshellarg('/etc/pmta/conf/workspace_'.$this->owner->id))->andReturn('');
        $session->shouldReceive('exec')->once()->with('pmta reload')->andReturn('reloaded');
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($server)))->andReturn($session);
        $this->app->instance(PmtaRemoteConfiguration::class, $remote);

        $this->deleteJson(route('console.tools.workspace.reset', $this->workspace->id), ['ips' => [$ip->id]])
            ->assertOk()->assertJsonPath('data.0.data', 'Restored Successfully');
        $this->assertDatabaseMissing('managed_server_mtas', ['id' => $mta->id]);
    }

    private function project(): WorkspaceGoogleProject
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        return WorkspaceGoogleProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'admin@example.test',
            'service_account_json' => json_encode(['client_email' => 'svc@example.test', 'private_key' => $privateKey], JSON_THROW_ON_ERROR),
            'is_active' => true,
        ]);
    }

    private function ip(): ManagedServerIp
    {
        $server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'TEST SMTP SERVER', 'provider' => 'fixture',
            'ip_address' => '192.0.2.50', 'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true, 'created_by' => $this->owner->id,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'mail.example.test', 'state' => 'verified',
            'verification_token' => Str::random(40), 'created_by' => $this->owner->id, 'status' => 'valid',
            'is_our' => true, 'is_active' => true,
        ]);

        return ManagedServerIp::query()->create([
            'workspace_id' => $this->workspace->id, 'managed_server_id' => $server->id, 'sending_domain_id' => $domain->id,
            'ip' => '192.0.2.50', 'type' => 'ipv4', 'dns' => 'mail.example.test', 'is_active' => true,
        ]);
    }

    private function jwtClaim(string $jwt, string $claim): mixed
    {
        $segments = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($segments[1] ?? '', '-_', '+/')), true);

        return is_array($payload) ? ($payload[$claim] ?? null) : null;
    }
}
