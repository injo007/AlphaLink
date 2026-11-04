<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceDropStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_aggregate_supported_events_by_drop_and_dimensions_without_recipient_data(): void
    {
        Carbon::setTestNow('2026-11-04 12:00:00');
        $owner = User::factory()->create(['name' => 'Synthetic statistics owner', 'username' => 'synthetic-statistics-owner']);
        $workspace = $this->workspace($owner, 'owner');
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic Drop ISP', 'is_active' => true,
            'allowed_users' => [],
        ]);
        $list = $this->audienceList($workspace, $owner, 'Synthetic Drop list');
        $drop = $this->drop($workspace, $owner, [
            'isp_id' => $isp->getKey(), 'send_type' => 'send', 'created_at' => now()->subHour(),
        ]);
        $statisticsQuery = null;
        DB::listen(function (QueryExecuted $query) use (&$statisticsQuery): void {
            if (str_contains($query->sql, 'event_isp')) {
                $statisticsQuery = $query->sql;
            }
        });

        foreach ([
            ['type' => 'open'], ['type' => 'open'], ['type' => 'click'],
            ['type' => 'lead', 'revenue' => 7.5], ['type' => 'unsubscribe'], ['type' => 'bot'],
        ] as $event) {
            WorkspaceDropVisitor::factory()->forDrop($drop)->create([
                ...$event,
                'isp_id' => (string) $isp->getKey(),
                'list_id' => (string) $list->getKey(),
                'list_type' => 'fresh',
                'country' => 'US',
                'creative_id' => 'creative-html-asset-91',
                'ip' => '198.51.100.77',
                'email_id' => 'private-contact-id',
                'created_at' => now()->subMinutes(20),
            ]);
        }

        $response = $this->actingAs($owner)->getJson(route('console.drops.statistics', [
            $workspace->getKey(),
            'from' => '2026-11-04',
            'to' => '2026-11-04',
            'drop_id' => $drop->getKey(),
            'isp_id' => $isp->getKey(),
            'list_id' => $list->getKey(),
            'country' => 'US',
            'send_type' => 'send',
        ]));

        $response->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.at', '2026-11-04')
            ->assertJsonPath('0.drop_id', (string) $drop->getKey())
            ->assertJsonPath('0.user', 'Synthetic statistics owner')
            ->assertJsonPath('0.isp', 'Synthetic Drop ISP')
            ->assertJsonPath('0.list', 'Synthetic Drop list')
            ->assertJsonPath('0.list_type', 'fresh')
            ->assertJsonPath('0.country', 'US')
            ->assertJsonPath('0.creative', 'creative-html-asset-91')
            ->assertJsonPath('0.opens', 2)
            ->assertJsonPath('0.clicks', 1)
            ->assertJsonPath('0.leads', 1)
            ->assertJsonPath('0.conversions', 1)
            ->assertJsonPath('0.unsubscribes', 1)
            ->assertJsonPath('0.bots', 1)
            ->assertJsonPath('0.lead_revenue', 7.5);
        $this->assertNotNull($statisticsQuery);
        $this->assertStringContainsString('"event_isp"."id" = "v"."isp_id"', $statisticsQuery);
        $this->assertStringNotContainsString('CAST("event_isp"."id" AS TEXT)', $statisticsQuery);
        $this->assertStringNotContainsString('private-contact-id', $response->getContent());
        $this->assertStringNotContainsString('198.51.100.77', $response->getContent());
        $this->assertStringNotContainsString('email', strtolower($response->getContent()));
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));

        Carbon::setTestNow();
    }

    public function test_drop_statistics_html_renders_filters_coverage_and_safe_empty_state(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $drop = $this->drop($workspace, $owner);

        $this->actingAs($owner)
            ->get(route('console.drops.statistics', $workspace->getKey()))
            ->assertOk()
            ->assertSee('DROP STATISTICS')
            ->assertSee('drop-stats-drop')
            ->assertSee('drop-stats-isp')
            ->assertSee('drop-stats-list')
            ->assertSee('drop-stats-country')
            ->assertSee('data-analytics-date-range-form', false)
            ->assertSee('Delivery and bounce totals are not stored in this report source', false)
            ->assertSee('No recorded Drop activity matches these filters.')
            ->assertSee($this->cleanWorkspaceUrl(route('console.drops.statistics', $workspace->getKey())), false)
            ->assertSee('#'.$drop->getKey())
            ->assertSee('analytics-pivot.js');
    }

    public function test_drop_statistics_are_limited_to_workspace_and_operator_owned_drops(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $otherOperator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $ownedDrop = $this->drop($workspace, $operator);
        $otherDrop = $this->drop($workspace, $otherOperator);
        WorkspaceDropVisitor::factory()->forDrop($ownedDrop)->create(['type' => 'click', 'country' => 'US']);
        WorkspaceDropVisitor::factory()->forDrop($otherDrop)->create(['type' => 'open', 'country' => 'US']);

        $secondOwner = User::factory()->create();
        $otherWorkspace = $this->workspace($secondOwner, 'owner');
        $foreignDrop = $this->drop($otherWorkspace, $secondOwner);
        WorkspaceDropVisitor::factory()->forDrop($foreignDrop)->create(['type' => 'open', 'country' => 'CA']);

        $this->actingAs($operator)->getJson(route('console.drops.statistics', [
            $workspace->getKey(), 'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.drop_id', (string) $ownedDrop->getKey())
            ->assertJsonPath('0.clicks', 1)->assertJsonPath('0.opens', 0);

        $this->actingAs($operator)->getJson(route('console.drops.statistics', [
            $workspace->getKey(), 'drop_id' => $otherDrop->getKey(),
        ]))->assertNotFound();

        $this->actingAs($owner)->getJson(route('console.drops.statistics', [
            $workspace->getKey(), 'drop_id' => $foreignDrop->getKey(),
        ]))->assertNotFound();
    }

    public function test_drop_statistics_reject_invalid_date_ranges_and_invalid_filters(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)->getJson(route('console.drops.statistics', [
            $workspace->getKey(), 'from' => today()->addDay()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);

        $this->actingAs($owner)->getJson(route('console.drops.statistics', [
            $workspace->getKey(), 'send_type' => 'unknown',
        ]))->assertUnprocessable()->assertJsonValidationErrors('send_type');
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Drop statistics workspace',
            'slug' => 'drop-stats-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function audienceList(Workspace $workspace, User $owner, string $name): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function drop(Workspace $workspace, User $user, array $attributes = []): WorkspaceDrop
    {
        return WorkspaceDrop::factory()->forWorkspace($workspace, $user)->create($attributes);
    }
}
