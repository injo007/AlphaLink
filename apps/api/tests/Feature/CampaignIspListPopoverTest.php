<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignIspListPopoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_support_label_replaces_team_tasks_and_messages_in_sidebar_and_page(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = $this->workspaceFor($owner, 'owner');

        $sidebarResponse = $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk();

        $sidebarResponse->assertSee('>Team Support</span>', false)
            ->assertDontSee('TEAM TASKS &amp; MESSAGES', false)
            ->assertDontSee('Team Tasks &amp; Messages', false);

        $supportResponse = $this->actingAs($owner)
            ->get(route('console.workspace-communications.index', $workspace->getKey()))
            ->assertOk();

        $supportResponse->assertSee('<h1>Team Support</h1>', false)
            ->assertDontSee('Tasks &amp; messages', false);
    }

    public function test_campaigns_page_exposes_isp_selected_lists_popover_only_to_campaign_operators(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $viewer = User::factory()->create(['access_role' => User::ACCESS_ROLE_VIEWER]);
        $workspace = $this->workspaceFor($owner, 'owner');
        $workspace->users()->attach($viewer->getKey(), ['role' => 'viewer']);

        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Gmail',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);

        $firstList = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'US',
            'slug' => 'us-list',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'us-list'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);

        $secondList = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'GmDatingUS',
            'slug' => 'gmdatingus-list',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'gmdatingus-list'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);

        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic ISP Popover Campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'kind' => 'marketing',
                'send_setup' => [
                    'isp_id' => $isp->getKey(),
                    'country' => 'US',
                    'audience_segments' => [
                        $firstList->getKey().'_click',
                        $secondList->getKey().'_lead',
                    ],
                ],
            ],
            'content_sha256' => hash('sha256', 'isp-popover-campaign'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'isp-popover-campaign-req'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        $ownerResponse = $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk();

        $ownerResponse->assertSee('id="campaign-isp-popover"', false)
            ->assertSee('data-campaign-isp-popover', false);

        $document = new \DOMDocument;
        @$document->loadHTML($ownerResponse->getContent());
        $xpath = new \DOMXPath($document);
        $trigger = $xpath->query('//button[@data-campaign-isp-popover]')->item(0);

        $this->assertNotNull($trigger, 'Expected interactive ISP trigger button on My Campaigns row.');
        $payload = json_decode((string) $trigger->getAttribute('data-audience-lists'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $payload);
        $this->assertSame('US_click', $payload[0]['label'] ?? null);
        $this->assertSame('click', $payload[0]['type'] ?? null);
        $this->assertSame('GmDatingUS_lead', $payload[1]['label'] ?? null);
        $this->assertSame('lead', $payload[1]['type'] ?? null);

        $viewerResponse = $this->actingAs($viewer)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk();

        $viewerResponse->assertDontSee('class="campaign-isp-trigger"', false)
            ->assertDontSee('data-audience-lists=', false)
            ->assertDontSee('GmDatingUS_lead')
            ->assertDontSee('US_click');
    }

    private function workspaceFor(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Campaign ISP workspace',
            'slug' => 'campaign-isp-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }
}
