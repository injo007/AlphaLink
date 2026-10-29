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
use Illuminate\Support\Facades\File;
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
            ->assertSee($this->cleanWorkspaceUrl(route('console.gcampaigns.projects', $this->workspace->id)))
            ->assertSee($this->cleanWorkspaceUrl(route('console.gcampaigns.accounts', $this->workspace->id)))
            ->assertSee('class="form-control selectpicker"', false)
            ->assertSee('bootstrap_select/bootstrap-select.js');
    }

    public function test_workspace_relay_assets_default_to_the_repository_release_bundle(): void
    {
        $this->assertSame(base_path('../../pmta/workspace_relay'), config('infrastructure.workspace_relay_assets_directory'));
    }

    public function test_workspace_projects_and_accounts_are_separate_dms_nav_pages_and_workspace_scoped(): void
    {
        $project = $this->project();
        $account = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'email' => 'workspace-member@example.test',
        ]);
        WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'email' => 'inactive-member@example.test',
            'is_active' => false,
        ]);

        $projectsPage = $this->get(route('console.gcampaigns.projects', $this->workspace->id));
        $projectsPage
            ->assertOk()->assertSee('GMAIL PROJECTS')->assertSee($project->name)
            ->assertSee('SAVE & SYNC')->assertSee('workspace-project-table')
            ->assertSee('ENABLE / DISABLE PROJECTS')->assertSee('DELETE SELECTED')
            ->assertSee('<th>type</th><th>name</th><th>total accounts</th><th>created at</th>', false)
            ->assertSee('<td>Workspace</td>', false)
            ->assertSee('workspace-projects-all')
            ->assertSee($this->cleanWorkspaceUrl(route('console.gcampaigns.accounts', [$this->workspace->id, 'project' => $project->id])))
            ->assertSee('datatables.min.js')->assertDontSee('BEGIN PRIVATE KEY');
        $this->assertSame(2, $projectsPage->viewData('projects')->first()->accounts_count);
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
            ->assertDontSee('ENABLE SELECTED')->assertSee('DISABLE SELECTED')->assertSee('DELETE SELECTED')->assertSee('SEARCH ACCOUNTS')
            ->assertSee('<th>project</th><th>email</th><th>is auth</th><th>is active</th><th>created at</th>', false)
            ->assertSee('Per-account OAuth authentication is not configured in AlphaLink')
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

    public function test_bulk_project_status_and_delete_are_workspace_scoped(): void
    {
        $first = $this->project(name: 'first-admin@example.test');
        $second = $this->project(name: 'second-admin@example.test');
        $retained = $this->project(name: 'retained-admin@example.test');
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace', 'slug' => 'foreign-workspace-'.Str::lower(Str::random(8)), 'created_by' => $this->owner->id,
        ]);
        $foreignProject = $this->project($foreignWorkspace, 'foreign-admin@example.test');

        $this->patchJson(route('console.tools.workspace.projects.bulk-status', $this->workspace->id), [
            'ids' => [$first->id, $second->id], 'status' => false,
        ])->assertOk()->assertJsonPath('changed', 2);
        $this->assertFalse($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($retained->fresh()->is_active);
        $this->assertTrue($foreignProject->fresh()->is_active);

        $this->patchJson(route('console.tools.workspace.projects.bulk-status', $this->workspace->id), [
            'ids' => [$first->id, $foreignProject->id], 'status' => true,
        ])->assertNotFound();
        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($foreignProject->fresh()->is_active);

        $firstAccount = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $first->id, 'email' => 'first@example.test',
        ]);
        $secondAccount = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $second->id, 'email' => 'second@example.test',
        ]);
        $retainedAccount = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $retained->id, 'email' => 'retained@example.test',
        ]);

        $this->deleteJson(route('console.tools.workspace.projects.bulk-destroy', $this->workspace->id), [
            'ids' => [$first->id, $second->id],
        ])->assertOk()->assertJsonPath('deleted', 2);
        $this->assertDatabaseMissing('workspace_google_projects', ['id' => $first->id]);
        $this->assertDatabaseMissing('workspace_google_projects', ['id' => $second->id]);
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $firstAccount->id]);
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $secondAccount->id]);
        $this->assertDatabaseHas('workspace_google_projects', ['id' => $retained->id]);
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $retainedAccount->id]);
        $this->assertDatabaseHas('workspace_google_projects', ['id' => $foreignProject->id]);
    }

    public function test_bulk_project_actions_reject_empty_and_duplicate_ids(): void
    {
        $project = $this->project();

        $this->patchJson(route('console.tools.workspace.projects.bulk-status', $this->workspace->id), [
            'ids' => [], 'status' => true,
        ])->assertUnprocessable();
        $this->patchJson(route('console.tools.workspace.projects.bulk-status', $this->workspace->id), [
            'ids' => [$project->id, $project->id], 'status' => true,
        ])->assertUnprocessable();
        $this->deleteJson(route('console.tools.workspace.projects.bulk-destroy', $this->workspace->id), [
            'ids' => [],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('workspace_google_projects', ['id' => $project->id]);
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
        ])->assertOk()->assertJsonPath('changed', 1)->assertJsonPath('data', 'Account status changed.');
        $this->assertFalse($account->fresh()->is_active);
        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), ['accounts' => [$account->id]])
            ->assertUnprocessable();
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $account->id]);
        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), ['ids' => [$account->id]])
            ->assertOk()->assertJsonPath('deleted', 1)->assertJsonPath('data', 'Accounts deleted.');
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $account->id]);
    }

    public function test_bulk_account_actions_are_atomic_and_workspace_scoped(): void
    {
        $project = $this->project();
        $first = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'first@example.test',
        ]);
        $second = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'second@example.test',
        ]);
        $retained = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'retained@example.test',
        ]);
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace', 'slug' => 'foreign-workspace-'.Str::lower(Str::random(8)), 'created_by' => $this->owner->id,
        ]);
        $foreignProject = $this->project($foreignWorkspace, 'foreign-admin@example.test');
        $foreignAccount = WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $foreignWorkspace->id, 'project_id' => $foreignProject->id, 'email' => 'foreign@example.test',
        ]);

        $this->patchJson(route('console.tools.workspace.accounts.status', $this->workspace->id), [
            'accounts' => [$first->id, $second->id], 'status' => false,
        ])->assertOk()->assertJsonPath('changed', 2);
        $this->assertFalse($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($retained->fresh()->is_active);
        $this->assertTrue($foreignAccount->fresh()->is_active);

        $this->patchJson(route('console.tools.workspace.accounts.status', $this->workspace->id), [
            'accounts' => [$first->id, $foreignAccount->id], 'status' => true,
        ])->assertNotFound();
        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($foreignAccount->fresh()->is_active);

        $this->patchJson(route('console.tools.workspace.accounts.status', $this->workspace->id), [
            'accounts' => [$retained->id, $retained->id], 'status' => false,
        ])->assertUnprocessable();
        $this->assertTrue($retained->fresh()->is_active);

        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), [
            'ids' => [$first->id, $second->id],
        ])->assertOk()->assertJsonPath('deleted', 2);
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $first->id]);
        $this->assertDatabaseMissing('workspace_google_accounts', ['id' => $second->id]);
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $retained->id]);
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $foreignAccount->id]);

        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), [
            'ids' => [$retained->id, $foreignAccount->id],
        ])->assertNotFound();
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $retained->id]);
        $this->assertDatabaseHas('workspace_google_accounts', ['id' => $foreignAccount->id]);
        $this->deleteJson(route('console.tools.workspace.accounts.destroy', $this->workspace->id), ['ids' => []])
            ->assertUnprocessable();
    }

    public function test_push_fails_before_server_access_when_the_deployment_bundle_is_missing(): void
    {
        $project = $this->project();
        WorkspaceGoogleAccount::query()->create(['workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'email' => 'user@example.test']);
        $ip = $this->ip();
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldNotReceive('open');
        $this->app->instance(PmtaRemoteConfiguration::class, $remote);
        config(['infrastructure.workspace_relay_assets_directory' => storage_path('framework/testing/missing-workspace-relay')]);

        $this->postJson(route('console.tools.workspace.push', $this->workspace->id), [
            'ips' => [$ip->id], 'projects' => [$project->id], 'split_on_ips' => false,
        ])->assertBadRequest()->assertJsonPath('data', 'Workspace relay deployment assets are missing or invalid.');
        $this->assertDatabaseCount('managed_server_mtas', 0);
    }

    public function test_push_uses_bundled_relay_assets_and_excludes_inactive_accounts(): void
    {
        $project = $this->project();
        WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'email' => 'active@example.test', 'is_active' => true,
        ]);
        WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'email' => 'inactive@example.test', 'is_active' => false,
        ]);
        $ip = $this->ip();
        $server = $ip->server;
        $assetDirectory = storage_path('framework/testing/workspace-relay-'.Str::uuid());
        File::ensureDirectoryExists($assetDirectory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($assetDirectory));
        file_put_contents($assetDirectory.'/workspace_relay', 'synthetic executable');
        file_put_contents($assetDirectory.'/workspace_relay.service', '[Service]');
        file_put_contents($assetDirectory.'/config.json', json_encode([
            'listen_addr' => '127.0.0.1:1028',
            'service_accounts_dir' => '/etc/workspace_relay/accounts',
        ], JSON_THROW_ON_ERROR));
        // The relay runs on the target server, where installRelay applies +x.
        // The application host only needs to read and upload this asset.
        chmod($assetDirectory.'/workspace_relay', 0644);
        config(['infrastructure.workspace_relay_assets_directory' => $assetDirectory]);

        $uploadedConfiguration = null;
        $executedCommands = [];
        $expectedAssets = [
            $assetDirectory.'/workspace_relay',
            $assetDirectory.'/config.json',
            $assetDirectory.'/workspace_relay.service',
        ];
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$executedCommands): string {
            $executedCommands[] = $command;
            foreach ([
                '__WORKSPACE_DIRS_READY__',
                '__WORKSPACE_ACCOUNT_READY__',
                '__WORKSPACE_RELAY_READY__',
                '__WORKSPACE_CONFIG_APPENDED__',
            ] as $marker) {
                if (str_contains($command, $marker)) {
                    return $marker;
                }
            }
            if ($command === 'pmta reload') {
                return 'reloaded';
            }

            if (str_contains($command, 'test -f')) {
                return 'no';
            }

            return '';
        });
        $session->shouldReceive('uploadFile')->times(3)->andReturnUsing(function (string $source, string $destination) use ($expectedAssets): bool {
            $this->assertFileExists($source);
            $this->assertContains($source, $expectedAssets);
            $this->assertMatchesRegularExpression('/^\/(?:usr\/sbin|etc\/workspace_relay|etc\/systemd\/system)\/\.?(?:workspace_relay|config|account)-[0-9a-f-]+\.new$/', $destination);

            return true;
        });
        $session->shouldReceive('upload')->twice()->andReturnUsing(function (string $contents, string $destination) use (&$uploadedConfiguration): bool {
            if (str_starts_with($destination, '/etc/pmta/conf/tmp_workspace_')) {
                $uploadedConfiguration = $contents;
            }

            return true;
        });
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($server)))->andReturn($session);
        $this->app->instance(PmtaRemoteConfiguration::class, $remote);

        $this->postJson(route('console.tools.workspace.push', $this->workspace->id), [
            'ips' => [$ip->id], 'projects' => [$project->id], 'split_on_ips' => false,
        ])->assertOk()->assertJsonPath('data.0.data', 'Workspace Pushed Successfully');

        $relayActivation = collect($executedCommands)->first(fn (string $command): bool => str_contains($command, 'systemctl restart workspace_relay.service'));
        $this->assertNotNull($relayActivation);
        $this->assertStringContainsString('systemctl is-active --quiet workspace_relay.service', $relayActivation);
        $this->assertStringContainsString('ss -H -ltn sport = :1028', $relayActivation);
        $this->assertStringNotContainsString('kill -9', implode("\n", $executedCommands));
        $this->assertNotNull($uploadedConfiguration);
        $this->assertStringContainsString('smtp-source-ip '.$ip->ip, $uploadedConfiguration);
        $this->assertStringContainsString('auth-username active@example.test', $uploadedConfiguration);
        $this->assertStringNotContainsString('inactive@example.test', $uploadedConfiguration);
        $this->assertDatabaseHas('managed_server_mtas', ['username' => 'active@example.test', 'user_id' => $this->owner->id]);
        $this->assertDatabaseMissing('managed_server_mtas', ['username' => 'inactive@example.test', 'user_id' => $this->owner->id]);
    }

    public function test_push_rolls_back_local_smtp_rows_when_final_pmta_reload_rejects_the_configuration(): void
    {
        $project = $this->project();
        WorkspaceGoogleAccount::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'email' => 'active@example.test',
            'is_active' => true,
        ]);
        $ip = $this->ip();
        $server = $ip->server;
        $assetDirectory = storage_path('framework/testing/workspace-relay-'.Str::uuid());
        File::ensureDirectoryExists($assetDirectory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($assetDirectory));
        file_put_contents($assetDirectory.'/workspace_relay', 'synthetic executable');
        file_put_contents($assetDirectory.'/workspace_relay.service', '[Service]');
        file_put_contents($assetDirectory.'/config.json', json_encode([
            'listen_addr' => '127.0.0.1:1028',
            'service_accounts_dir' => '/etc/workspace_relay/accounts',
        ], JSON_THROW_ON_ERROR));
        config(['infrastructure.workspace_relay_assets_directory' => $assetDirectory]);

        $executedCommands = [];
        $reloadCount = 0;
        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$executedCommands, &$reloadCount): string {
            $executedCommands[] = $command;
            foreach ([
                '__WORKSPACE_DIRS_READY__',
                '__WORKSPACE_ACCOUNT_READY__',
                '__WORKSPACE_RELAY_READY__',
                '__WORKSPACE_CONFIG_APPENDED__',
            ] as $marker) {
                if (str_contains($command, $marker)) {
                    return $marker;
                }
            }
            if ($command === 'pmta reload') {
                $reloadCount++;

                return $reloadCount === 2 ? 'Invalid PMTA configuration' : 'reloaded';
            }
            if (str_contains($command, 'test -f')) {
                return 'no';
            }

            return '';
        });
        $session->shouldReceive('uploadFile')->times(3)->andReturnTrue();
        $session->shouldReceive('upload')->twice()->andReturnTrue();
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($server)))->andReturn($session);
        $this->app->instance(PmtaRemoteConfiguration::class, $remote);

        $this->postJson(route('console.tools.workspace.push', $this->workspace->id), [
            'ips' => [$ip->id],
            'projects' => [$project->id],
            'split_on_ips' => false,
        ])->assertOk()->assertJsonPath('data.0.data', 'Invalid PMTA configuration');

        $this->assertSame(3, $reloadCount);
        $this->assertDatabaseCount('managed_server_mtas', 0);
        $this->assertTrue(collect($executedCommands)->contains(
            fn (string $command): bool => str_contains($command, 'rm -f '.escapeshellarg('/etc/pmta/conf/workspace_'.$this->owner->id)),
        ));
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

    private function project(?Workspace $workspace = null, string $name = 'admin@example.test'): WorkspaceGoogleProject
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        return WorkspaceGoogleProject::query()->create([
            'workspace_id' => ($workspace ?? $this->workspace)->id,
            'name' => $name,
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
