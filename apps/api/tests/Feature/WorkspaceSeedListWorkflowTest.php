<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSeedEmail;
use App\Models\WorkspaceSeedList;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\PmtaRemoteConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WorkspaceSeedListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_list_page_is_in_warmups_navigation_and_returns_workspace_scoped_rows(): void
    {
        $owner = User::factory()->create(['username' => 'Seed owner']);
        $workspace = $this->workspace($owner, 'owner');
        $seedList = $this->seedList($workspace, $owner, 'Fixture seed list');
        WorkspaceSeedEmail::factory()->forSeedList($seedList)->create(['email' => 'one@example.test']);
        $otherUser = User::factory()->create(['username' => 'Foreign owner']);
        $otherWorkspace = $this->workspace($otherUser, 'owner');
        $this->seedList($otherWorkspace, $otherUser, 'Foreign list');

        $this->actingAs($owner)->get(route('console.warmups.seeds.index', $workspace->getKey()))
            ->assertOk()->assertSee('Seeds')->assertSee('SEEDS')->assertSee('warmup-seeds-table')
            ->assertSee($this->cleanWorkspaceUrl(route('console.warmups.seeds.index', $workspace->getKey())), false);

        $this->actingAs($owner)->getJson(route('console.warmups.seeds.index', [
            $workspace->getKey(), 'draw' => 4, 'start' => 0, 'length' => 50,
        ]))->assertOk()->assertJsonPath('draw', 4)->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.id', $seedList->getKey())
            ->assertJsonPath('data.0.seed_emails_count', 1)
            ->assertJsonPath('data.0.user.username', 'Seed owner');
    }

    public function test_list_creation_validates_name_and_persists_active_owner_scoped_record(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)->get(route('console.warmups.seeds.create', $workspace->getKey()))
            ->assertOk()->assertSee('Enter Seed Name..');
        $this->actingAs($owner)->postJson(route('console.warmups.seeds.store', $workspace->getKey()), ['name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->postJson(route('console.warmups.seeds.store', $workspace->getKey()), ['name' => str_repeat('S', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($owner)->postJson(route('console.warmups.seeds.store', $workspace->getKey()), ['name' => 'My seed list'])
            ->assertOk()->assertExactJson(['data' => 'Seed List Created']);

        self::assertDatabaseHas('workspace_seed_lists', [
            'workspace_id' => $workspace->getKey(), 'user_id' => $owner->getKey(), 'name' => 'My seed list', 'is_active' => true,
        ]);
    }

    public function test_upload_filters_invalid_addresses_preserves_duplicates_and_reports_dms_insert_result(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $seedList = $this->seedList($workspace, $owner, 'Synthetic list');

        $this->actingAs($owner)->get(route('console.warmups.seeds.upload-form', [$workspace->getKey(), $seedList->getKey()]))
            ->assertOk()->assertSee('name="emails"', false)->assertSee('SAVE');
        $this->actingAs($owner)->postJson(route('console.warmups.seeds.upload', [$workspace->getKey(), $seedList->getKey()]), [
            'emails' => ['valid@example.test', 'not-an-email', 'valid@example.test', 'second@example.test'],
        ])->assertOk()->assertExactJson(['data' => 'Inserted 1']);

        self::assertDatabaseCount('workspace_seed_emails', 3);
        self::assertSame(2, WorkspaceSeedEmail::query()->where('email', 'valid@example.test')->count());
        $this->actingAs($owner)->postJson(route('console.warmups.seeds.upload', [$workspace->getKey(), $seedList->getKey()]), [
            'emails' => ['still-invalid'],
        ])->assertStatus(400)->assertExactJson(['data' => 'Failed inserted 0']);
    }

    public function test_list_owner_can_toggle_status_edit_email_and_remove_only_emails_in_their_list(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($otherUser->getKey(), ['role' => 'operator']);
        $seedList = $this->seedList($workspace, $owner, 'Owner list');
        $otherList = $this->seedList($workspace, $otherUser, 'Operator list');
        $email = WorkspaceSeedEmail::factory()->forSeedList($seedList)->create(['email' => 'old@example.test']);
        $foreignEmail = WorkspaceSeedEmail::factory()->forSeedList($otherList)->create(['email' => 'foreign@example.test']);

        $this->actingAs($owner)->patchJson(route('console.warmups.seeds.status', [$workspace->getKey(), $seedList->getKey()]), ['status' => '0'])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        self::assertDatabaseHas('workspace_seed_lists', ['id' => $seedList->getKey(), 'is_active' => false]);
        $this->actingAs($owner)->get(route('console.warmups.seed-emails.edit', [$workspace->getKey(), $email->getKey()]))
            ->assertOk()->assertSee('old@example.test');
        $this->actingAs($owner)->putJson(route('console.warmups.seed-emails.update', [$workspace->getKey(), $email->getKey()]), ['email' => 'odd text'])
            ->assertOk()->assertExactJson(['data' => 'Email updated']);
        self::assertDatabaseHas('workspace_seed_emails', ['id' => $email->getKey(), 'email' => 'odd text']);

        $this->actingAs($owner)->getJson(route('console.warmups.seeds.show', [$workspace->getKey(), $seedList->getKey()]))
            ->assertOk()->assertJsonPath('recordsTotal', 1)->assertJsonPath('data.0.id', $email->getKey());
        $this->actingAs($owner)->deleteJson(route('console.warmups.seeds.emails.destroy', [$workspace->getKey(), $seedList->getKey()]), [
            'ids' => [$email->getKey(), $foreignEmail->getKey()],
        ])->assertOk()->assertExactJson(['data' => 'Removed 1']);

        self::assertDatabaseMissing('workspace_seed_emails', ['id' => $email->getKey()]);
        self::assertDatabaseHas('workspace_seed_emails', ['id' => $foreignEmail->getKey()]);
    }

    public function test_seed_email_and_list_owner_actions_are_denied_to_non_owner_and_outside_workspace(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $seedList = $this->seedList($workspace, $owner, 'Owner list');
        $email = WorkspaceSeedEmail::factory()->forSeedList($seedList)->create();

        $this->actingAs($operator)->postJson(route('console.warmups.seeds.upload', [$workspace->getKey(), $seedList->getKey()]), ['emails' => ['safe@example.test']])
            ->assertNotFound();
        $this->actingAs($operator)->patchJson(route('console.warmups.seeds.status', [$workspace->getKey(), $seedList->getKey()]), ['status' => '0'])
            ->assertNotFound();
        $this->actingAs($operator)->putJson(route('console.warmups.seed-emails.update', [$workspace->getKey(), $email->getKey()]), ['email' => 'new@example.test'])
            ->assertNotFound();
        $this->actingAs($operator)->getJson(route('console.warmups.seeds.load', [$workspace->getKey(), 'ids' => [$seedList->getKey()]]))
            ->assertOk()->assertExactJson([$email->email]);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('console.warmups.seeds.index', $workspace->getKey()))->assertNotFound();
    }

    public function test_seed_list_removal_is_workspace_admin_only_and_cascades_email_rows(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $seedList = $this->seedList($workspace, $owner, 'Delete fixture');
        $email = WorkspaceSeedEmail::factory()->forSeedList($seedList)->create();

        $this->actingAs($operator)->deleteJson(route('console.warmups.seeds.destroy', [$workspace->getKey(), $seedList->getKey()]))->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('console.warmups.seeds.destroy', [$workspace->getKey(), $seedList->getKey()]))
            ->assertOk()->assertExactJson(['data' => 'List Removed']);
        self::assertDatabaseMissing('workspace_seed_lists', ['id' => $seedList->getKey()]);
        self::assertDatabaseMissing('workspace_seed_emails', ['id' => $email->getKey()]);
    }

    public function test_clean_reads_selected_server_bounce_archive_and_only_removes_the_actor_owned_matches(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $server = $this->server($workspace, $owner);
        $ownList = $this->seedList($workspace, $operator, 'Operator seeds');
        $otherList = $this->seedList($workspace, $owner, 'Owner seeds');
        $ownEmail = WorkspaceSeedEmail::factory()->forSeedList($ownList)->create(['email' => 'operator-hit@example.test']);
        $otherEmail = WorkspaceSeedEmail::factory()->forSeedList($otherList)->create(['email' => 'owner-hit@example.test']);

        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->with("cat /etc/pmta/bounced/archive/2026-10-25/* | awk -F, -v FPAT='[^,]*|\"[^\"]*\"' '$5 ~ /-w/ {print $12}' | sort | uniq")
            ->andReturn("operator-hit@example.test\nowner-hit@example.test");
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->with(Mockery::on(fn (ManagedServer $candidate): bool => $candidate->is($server)))->andReturn($session);
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($operator)->postJson(route('console.warmups.seeds.clean', $workspace->getKey()), [
            'server' => $server->getKey(), 'date' => '2026-10-25',
        ])->assertOk()->assertExactJson(['data' => 'Removed 1 Emails']);

        self::assertDatabaseMissing('workspace_seed_emails', ['id' => $ownEmail->getKey()]);
        self::assertDatabaseHas('workspace_seed_emails', ['id' => $otherEmail->getKey()]);
    }

    public function test_clean_treats_missing_bounce_archive_as_zero_removals(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner);
        $seedList = $this->seedList($workspace, $owner, 'No change list');
        $email = WorkspaceSeedEmail::factory()->forSeedList($seedList)->create(['email' => 'keep@example.test']);

        $session = Mockery::mock(DmsInstallerSshSession::class);
        $session->shouldReceive('exec')->once()->andReturn('cat: /etc/pmta/bounced/archive/2026-10-25/*: No such file or directory');
        $remote = Mockery::mock(PmtaRemoteConfiguration::class);
        $remote->shouldReceive('open')->once()->andReturn($session);
        $this->instance(PmtaRemoteConfiguration::class, $remote);

        $this->actingAs($owner)->postJson(route('console.warmups.seeds.clean', $workspace->getKey()), [
            'server' => $server->getKey(), 'date' => '2026-10-25',
        ])->assertOk()->assertExactJson(['data' => 'Removed 0 Emails']);
        self::assertDatabaseHas('workspace_seed_emails', ['id' => $email->getKey()]);
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Seed workflow workspace',
            'slug' => 'seed-workflow-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function seedList(Workspace $workspace, User $owner, string $name): WorkspaceSeedList
    {
        return $workspace->seedLists()->create([
            'user_id' => $owner->getKey(), 'name' => $name, 'is_active' => true,
        ]);
    }

    private function server(Workspace $workspace, User $owner): ManagedServer
    {
        return $workspace->managedServers()->create([
            'name' => 'Synthetic PMTA server', 'provider' => 'Synthetic provider', 'ip_address' => '203.0.113.17',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified', 'is_active' => true,
            'is_installed' => true, 'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic-seed-server'), 'created_by' => $owner->getKey(),
        ]);
    }
}
