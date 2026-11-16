<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignNumberClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_are_sequentially_numbered_and_newest_appears_at_top_with_highest_number(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $workspace = Workspace::query()->create([
            'name' => 'Campaign Sequence Workspace',
            'slug' => 'campaign-seq-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        // Create initial campaign (should be #1)
        $c1 = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Initial Campaign 1',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now()->subDays(5),
        ]);
        $this->assertSame(1, $c1->campaign_number);

        // Simulate having 400 campaigns created by setting a campaign with campaign_number = 400
        $c400 = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'campaign_number' => 400,
            'name' => 'Campaign 400',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now()->subHour(),
        ]);
        $this->assertSame(400, $c400->campaign_number);

        // Now create the newest campaign without specifying campaign_number (the 401st campaign)
        $c401 = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Newest Campaign 401',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        // It must automatically receive campaign_number = 401
        $this->assertSame(401, $c401->campaign_number);

        // When viewing My Campaigns, the latest created campaign (#401) must be classified at the top
        $response = $this->actingAs($owner)
            ->get(route('console.campaigns.index', $workspace->getKey()))
            ->assertOk();

        $content = $response->getContent();

        // 401 must appear as a campaign-row-number
        $this->assertStringContainsString('class="campaign-row-number"', $content);
        $this->assertStringContainsString('>401<', $content);
        $this->assertStringContainsString('>400<', $content);

        // Ensure 401 appears before 400 in the HTML table (latest at the top)
        $pos401 = strpos($content, '>401<');
        $pos400 = strpos($content, '>400<');
        $this->assertNotFalse($pos401);
        $this->assertNotFalse($pos400);
        $this->assertTrue($pos401 < $pos400, 'Campaign 401 must appear before Campaign 400 in the table.');

        // Search by numeric '#401' or '401'
        $searchResponse = $this->actingAs($owner)
            ->get(route('console.campaigns.index', [$workspace->getKey(), 'q' => '#401']))
            ->assertOk();

        $searchResponse->assertSee('Newest Campaign 401')
            ->assertDontSee('Campaign 400');
    }
}
