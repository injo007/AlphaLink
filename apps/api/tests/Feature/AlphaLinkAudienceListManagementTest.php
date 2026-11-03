<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlphaLinkAudienceListManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private $isp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic list workspace',
            'slug' => 'synthetic-list-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->isp = $this->workspace->isps()->create([
            'name' => 'ManagedISP',
            'tracked_countries' => ['US', 'CA'],
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);
        $this->actingAs($this->owner);
    }

    public function test_list_creation_inherits_isp_access_and_checks_tracked_country(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->workspace->sponsors()->create([
            'name' => 'Fixture sponsor',
            'affiliate_id' => 'fixture-1',
            'url' => 'https://affiliate.example.test',
            'username' => 'user-fixture',
            'password' => 'fixture-pass',
            'api_key' => 'fixture-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $this->isp->forceFill([
            'allowed_users' => [(int) $operator->getKey()],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ])->save();

        $this->postJson($this->storeUrl(), ['name' => 'US_Prospects', 'country' => 'US'])
            ->assertOk()->assertJsonPath('data', 'List created');
        $list = AudienceList::query()->where('workspace_id', $this->workspace->getKey())->sole();
        $this->assertSame($this->isp->getKey(), $list->isp_id);
        $this->assertSame('US', $list->country);
        $this->assertSame([(int) $operator->getKey()], $list->allowed_users);
        $this->assertSame([(int) $sponsor->getKey()], $list->allowed_sponsors);
        $this->assertTrue($list->is_tracked);
        $this->assertTrue($list->is_lead_locked);

        $this->postJson($this->storeUrl(), ['name' => 'Untracked', 'country' => 'GB'])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
    }

    public function test_management_grid_exposes_reference_list_controls_without_member_addresses(): void
    {
        $list = $this->makeList('Safe list');

        $this->get(route('console.infrastructure.lists.index', $this->workspace->id))
            ->assertOk()->assertSee('LOAD LISTS')->assertSee('ALLOWED SPONSORS');

        $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->id, $this->isp->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $list->id)
            ->assertJsonPath('data.0.history.fresh', 0)
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.members');
    }

    public function test_management_grid_counts_current_memberships_without_a_history_snapshot(): void
    {
        $list = $this->makeList('Live count list');
        $this->addMembership($list, 'fresh', 'fresh-member');
        $this->addMembership($list, 'click', 'click-member');
        $this->addMembership($list, 'opt_out', 'opt-out-member');

        $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->getKey(), $this->isp->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.0.history.fresh', 1)
            ->assertJsonPath('data.0.history.click', 1)
            ->assertJsonPath('data.0.history.opt_out', 1);

        $this->assertSame(0, AudienceListHistory::query()->where('audience_list_id', $list->getKey())->count());
    }

    public function test_management_grid_hides_inactive_current_status_members_but_keeps_unsubscribe_action_totals(): void
    {
        $source = $this->makeList('Segment source');
        $freshSegment = $this->makeList('Segment source_fresh');
        $freshSegment->forceFill([
            'segment_source_list_id' => $source->getKey(),
            'segment_action' => 'fresh',
        ])->save();
        $this->addMembership($freshSegment, 'fresh', 'old-fresh-member')
            ->forceFill(['status' => 'inactive', 'left_at' => now()])
            ->save();

        $unsubscribeSegment = $this->makeList('Segment source_unsub');
        $unsubscribeSegment->forceFill([
            'segment_source_list_id' => $source->getKey(),
            'segment_action' => 'unsubscribe',
        ])->save();
        $this->addMembership($unsubscribeSegment, 'unsubscribe', 'unsubscribed-member')
            ->forceFill(['status' => 'inactive', 'left_at' => now()])
            ->save();

        $rows = $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->getKey(), $this->isp->getKey()]))
            ->assertOk()
            ->json('data');
        $rowsByName = collect($rows)->keyBy('name');

        $this->assertSame(0, $rowsByName['Segment source_fresh']['history']['fresh']);
        $this->assertSame(1, $rowsByName['Segment source_unsub']['history']['unsubscribe']);
    }

    public function test_management_grid_uses_the_latest_reference_shaped_daily_membership_counts(): void
    {
        $list = $this->makeList('History count list');
        $this->addMembership($list, 'fresh', 'first');
        $this->addMembership($list, 'fresh', 'second');

        $this->artisan('audience-lists:calculate-history', ['listId' => $list->getKey()])
            ->assertExitCode(0);

        $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->getKey(), $this->isp->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.0.history.bot', 0);

        $this->addMembership($list, 'delivery', 'third');
        $this->addMembership($list, 'open', 'opened');
        $this->addMembership($list, 'click', 'fourth');
        $this->addMembership($list, 'lead', 'lead');
        $this->addMembership($list, 'bot', 'fifth');
        $this->addMembership($list, 'proxy', 'sixth');
        $this->addMembership($list, 'blacklist', 'seventh');
        $this->addMembership($list, 'hard_bounce', 'eighth');
        $this->addMembership($list, 'opt_out', 'ninth');
        $this->artisan('audience-lists:calculate-history', ['listId' => $list->getKey()])
            ->assertExitCode(0);

        $this->assertSame(1, AudienceListHistory::query()->where('audience_list_id', $list->getKey())->count());
        $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->getKey(), $this->isp->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.0.history.fresh', 2)
            ->assertJsonPath('data.0.history.delivery', 1)
            ->assertJsonPath('data.0.history.open', 1)
            ->assertJsonPath('data.0.history.click', 1)
            ->assertJsonPath('data.0.history.lead', 1)
            ->assertJsonPath('data.0.history.bot', 1)
            ->assertJsonPath('data.0.history.unsubscribe', 0)
            ->assertJsonPath('data.0.history.opt_out', 1)
            ->assertJsonPath('data.0.history.proxy', 1)
            ->assertJsonPath('data.0.history.blacklist', 1)
            ->assertJsonPath('data.0.history.hard_bounce', 1)
            ->assertJsonMissingPath('data.0.email');

        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), [
            'isp' => $this->isp->getKey(),
            'list' => $list->getKey(),
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('histories.0.fresh', 2)
            ->assertJsonPath('histories.0.delivery', 1)
            ->assertJsonPath('histories.0.open', 1)
            ->assertJsonPath('histories.0.click', 1)
            ->assertJsonPath('histories.0.lead', 1)
            ->assertJsonPath('histories.0.unsubscribe', 0)
            ->assertJsonPath('histories.0.opt_out', 1)
            ->assertJsonPath('histories.0.proxy', 1)
            ->assertJsonPath('histories.0.bot', 1)
            ->assertJsonPath('histories.0.blacklist', 1)
            ->assertJsonPath('histories.0.hard_bounce', 1);
    }

    public function test_status_tracking_type_lock_and_assignments_are_workspace_scoped(): void
    {
        $list = $this->makeList('Policies');
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->workspace->sponsors()->create([
            'name' => 'Allowed sponsor',
            'affiliate_id' => null,
            'url' => 'https://affiliate.example.test',
            'username' => 'user-fixture',
            'password' => 'fixture-pass',
            'api_key' => 'fixture-key',
            'type' => 'cake',
            'allowed_users' => [],
            'is_active' => true,
        ]);

        $base = '/app/workspaces/'.$this->workspace->id.'/infrastructure/lists/'.$list->id;
        $this->patchJson($base.'/status', ['status' => false])->assertOk();
        $this->patchJson($base.'/track', ['track' => false])->assertOk();
        $this->patchJson($base.'/lock', ['type' => 'fresh', 'status' => true])->assertOk();
        $this->putJson($base.'/assignment/users', ['users' => [$operator->id]])->assertOk();
        $this->putJson($base.'/assignment/sponsors', ['sponsors' => [$sponsor->id]])->assertOk();

        $list->refresh();
        $this->assertSame('inactive', $list->status);
        $this->assertFalse($list->is_tracked);
        $this->assertTrue($list->is_fresh_locked);
        $this->assertSame([(int) $operator->id], $list->allowed_users);
        $this->assertSame([(int) $sponsor->id], $list->allowed_sponsors);

        $this->patchJson($base.'/lock', ['type' => 'opt_out', 'status' => true])->assertUnprocessable();
        $this->putJson($base.'/assignment/users', ['users' => [$this->owner->id]])->assertUnprocessable();
    }

    public function test_list_delete_hides_it_but_retains_memberships_and_audit_history(): void
    {
        $list = $this->makeList('Retained history list');
        $this->addMembership($list, 'fresh', 'retained');
        $membershipId = ContactListMembership::query()->where('audience_list_id', $list->getKey())->value('id');

        $this->get(route('console.infrastructure.lists.index', $this->workspace->id))
            ->assertOk()
            ->assertSee('DELETE LIST');
        $this->deleteJson(route('console.infrastructure.lists.destroy', [$this->workspace->id, $list->getKey()]))
            ->assertOk()
            ->assertJsonPath('data', 'List deleted. Campaign snapshots and history are retained.');

        $this->assertSoftDeleted('audience_lists', ['id' => $list->getKey()]);
        $this->assertDatabaseHas('contact_list_memberships', ['id' => $membershipId, 'audience_list_id' => $list->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'subject_type' => 'audience_list',
            'subject_id' => $list->getKey(),
            'action' => 'audience_list.deleted',
        ]);
        $this->getJson(route('console.infrastructure.isps.lists', [$this->workspace->id, $this->isp->id]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_bulk_access_assignment_replaces_policy_for_only_selected_workspace_lists(): void
    {
        $first = $this->makeList('First list');
        $second = $this->makeList('Second list');
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->putJson(route('console.infrastructure.lists.bulk-assignment.update', [$this->workspace->id, 'users']), [
            'lists' => [$first->id, $second->id],
            'users' => [$operator->id],
        ])->assertOk();

        $this->assertSame([(int) $operator->id], $first->fresh()->allowed_users);
        $this->assertSame([(int) $operator->id], $second->fresh()->allowed_users);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->id,
            'subject_type' => 'audience_list',
            'subject_id' => $first->id,
            'action' => 'audience_list.allowed_users_updated',
        ]);
    }

    public function test_non_manager_cannot_open_list_management_or_mutate_list_state(): void
    {
        $list = $this->makeList('Protected list');
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator);

        $this->get(route('console.infrastructure.lists.index', $this->workspace->id))->assertForbidden();
        $this->patchJson('/app/workspaces/'.$this->workspace->id.'/infrastructure/lists/'.$list->id.'/status', ['status' => false])->assertForbidden();
        $this->deleteJson(route('console.infrastructure.lists.destroy', [$this->workspace->id, $list->id]))->assertForbidden();
    }

    private function makeList(string $name): AudienceList
    {
        return $this->workspace->audienceLists()->create([
            'created_by' => $this->owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
            'isp_id' => $this->isp->getKey(),
            'country' => 'US',
        ]);
    }

    private function storeUrl(): string
    {
        return route('console.infrastructure.lists.store', [$this->workspace->id, $this->isp->id]);
    }

    private function addMembership(AudienceList $list, string $type, string $fixture): ContactListMembership
    {
        $email = $fixture.'-'.Str::lower(Str::random(8)).'@example.test';
        $contact = Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => $type,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
