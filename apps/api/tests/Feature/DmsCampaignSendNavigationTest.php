<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCampaignSendNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_send_is_a_route_backed_active_dms_navigation_item(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $url = route('console.campaigns.index', $workspace->getKey());

        $response = $this->actingAs($owner)->get($url)->assertOk();

        $response->assertSee('id="nav-submenu-campaigns"', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('class="nav-main-link active" href="'.$this->cleanWorkspaceUrl($url).'"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('fas fa-envelope', false)
            ->assertSee('My campaigns')
            ->assertSee('aria-label="Create campaign"', false)
            ->assertSee('SHOW DISABLED')
            ->assertSee('Show 50 rows')
            ->assertSee('aria-label="New test message"', false)
            ->assertSee('aria-label="Filter campaigns"', false)
            ->assertSee('TOTAL SEND')
            ->assertSee('FRACTION IP')
            ->assertSee('SLEEP EMAIL')
            ->assertSee('SLEEP IP')
            ->assertSee('SEND TYPE')
            ->assertSee('PROGRESS')
            ->assertSee('BACKGROUND SENDS')
            ->assertSee('This Grid Is Empty')
            ->assertDontSee('DMS-mapped recipient suppression snapshot is built when a campaign draft is saved')
            ->assertDontSee('no campaign can be launched');
    }

    public function test_campaign_send_is_available_to_workspace_operators_but_not_privacy_officers(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('SEND')
            ->assertSee('This Grid Is Empty');

        $privacyOfficer = User::factory()->create();
        $workspace->users()->attach($privacyOfficer->getKey(), ['role' => 'privacy_officer']);

        $this->actingAs($privacyOfficer)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertForbidden();
    }

    public function test_campaign_register_remains_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'owner');

        $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Visible synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $workspace->campaigns()->create([
            'created_by' => $operator->getKey(),
            'name' => 'Other owner synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $otherWorkspace->campaigns()->create([
            'created_by' => $otherOwner->getKey(),
            'name' => 'Foreign synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);

        $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('Visible synthetic campaign')
            ->assertDontSee('Foreign synthetic campaign')
            ->assertDontSee('Other owner synthetic campaign');

        $otherCampaign = $workspace->campaigns()->where('name', 'Other owner synthetic campaign')->firstOrFail();
        $this->actingAs($owner)
            ->get(route('console.campaigns.show', [$workspace->getKey(), $otherCampaign->getKey()]))
            ->assertNotFound();
    }

    public function test_campaign_register_uses_the_dms_date_window_and_searches_workspace_campaign_fields(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $recent = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Current synthetic launch',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $historical = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Historical synthetic launch',
            'kind' => 'transactional',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $historical->forceFill(['created_at' => now()->subDays(45)])->saveQuietly();

        $url = route('console.campaigns.index', $workspace->getKey());

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee($recent->name)
            ->assertDontSee($historical->name)
            ->assertSee('Date from')
            ->assertSee('Showing 1 to 1 of 1 campaigns in the selected date range.');

        $this->get($url.'?'.http_build_query([
            'from' => now()->subDays(60)->toDateString(),
            'to' => now()->toDateString(),
            'q' => 'Historical',
        ]))
            ->assertOk()
            ->assertSee($historical->name)
            ->assertDontSee($recent->name)
            ->assertSee('name="q" type="search" class="form-control form-control-sm mr-1" value="Historical"', false);
    }

    public function test_campaign_register_uses_the_dms_active_vs_disabled_filter(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $active = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Active synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $disabled = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Disabled synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => false,
            'idempotency_key' => Str::random(24),
        ]);

        $url = route('console.campaigns.index', $workspace->getKey());

        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee($disabled->name)
            ->assertSee('SHOW DISABLED');

        $this->get($url.'?disabled=1')
            ->assertOk()
            ->assertDontSee($active->name)
            ->assertSee($disabled->name)
            ->assertSee('id="campaign-disabled" name="disabled" value="1" type="checkbox" class="custom-control-input" checked', false);
    }

    public function test_campaign_send_action_waits_for_a_saved_recipient_snapshot_and_route(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Unsent source-mapped draft',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);

        $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk()
            ->assertSee('aria-label="Send not ready"', false)
            ->assertSee('SEND NOT READY')
            ->assertSee('The campaign must have a completed recipient snapshot and valid saved server routes before sending.')
            ->assertSee('aria-label="Schedule unavailable for '.$campaign->name.'"', false)
            ->assertDontSee('no campaign can be launched');
    }

    public function test_campaign_register_page_length_control_changes_the_paginated_result(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');

        foreach (range(1, 21) as $number) {
            $workspace->campaigns()->create([
                'created_by' => $owner->getKey(),
                'name' => 'Page length campaign '.$number,
                'kind' => 'marketing',
                'status' => 'draft',
                'idempotency_key' => Str::random(24),
            ]);
        }

        $url = route('console.campaigns.index', $workspace->getKey());
        $this->actingAs($owner)
            ->get($url.'?per_page=20')
            ->assertOk()
            ->assertSee('Showing 1 to 20 of 21 campaigns in the selected date range.')
            ->assertSee('<option value="20" selected>Show 20 rows</option>', false);

        $this->get($url.'?per_page=200')
            ->assertSessionHasErrors('per_page');
    }

    public function test_campaign_can_be_disabled_and_restored_with_a_scoped_audit_event(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaign = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Availability synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $otherOperator = User::factory()->create();
        $workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $otherCampaign = $workspace->campaigns()->create([
            'created_by' => $otherOperator->getKey(),
            'name' => 'Another owner synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $url = route('console.campaigns.status', [$workspace->getKey(), $campaign->getKey()]);
        $otherUrl = route('console.campaigns.status', [$workspace->getKey(), $otherCampaign->getKey()]);

        $this->actingAs($owner)
            ->patch($otherUrl, ['is_active' => false])
            ->assertNotFound();
        $this->assertDatabaseHas('campaigns', ['id' => $otherCampaign->getKey(), 'is_active' => true]);

        $this->from(route('console.campaigns.index', $workspace->getKey()))
            ->actingAs($owner)
            ->patch($url, ['is_active' => false])
            ->assertRedirect('/mailer/campaigns')
            ->assertSessionHas('status', 'Campaign disabled.');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_active' => false]);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'campaign.availability_changed',
            'subject_id' => $campaign->getKey(),
        ]);

        $this->patch($url, ['is_active' => true])
            ->assertRedirect('/mailer/campaigns')
            ->assertSessionHas('status', 'Campaign restored.');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_active' => true]);
        $this->assertSame(2, AuditEvent::query()->where('subject_id', $campaign->getKey())->count());
    }

    public function test_bulk_disable_is_atomic_creator_scoped_and_audited(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'owner');
        $campaigns = collect(['Bulk first', 'Bulk second'])->map(fn (string $name) => $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => $name,
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]));
        $operator = User::factory()->create();
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $foreignCampaign = $workspace->campaigns()->create([
            'created_by' => $operator->getKey(),
            'name' => 'Not owned by current actor',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => Str::random(24),
        ]);
        $url = route('console.campaigns.bulk-disable', $workspace->getKey());

        $this->actingAs($owner)->from(route('console.campaigns.index', $workspace->getKey()))
            ->patch($url, ['campaign_ids' => [$campaigns[0]->getKey(), $foreignCampaign->getKey()]])
            ->assertNotFound();
        $this->assertDatabaseHas('campaigns', ['id' => $campaigns[0]->getKey(), 'is_active' => true]);
        $this->assertDatabaseHas('campaigns', ['id' => $foreignCampaign->getKey(), 'is_active' => true]);

        $this->patch($url, ['campaign_ids' => $campaigns->pluck('id')->all()])
            ->assertRedirect('/mailer/campaigns')
            ->assertSessionHas('status', '2 campaigns were disabled.');

        foreach ($campaigns as $campaign) {
            $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey(), 'is_active' => false]);
            $this->assertDatabaseHas('audit_events', [
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $owner->getKey(),
                'action' => 'campaign.availability_changed',
                'subject_id' => $campaign->getKey(),
            ]);
        }
    }

    private function workspaceFor(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Campaign workspace',
            'slug' => 'campaign-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }
}
