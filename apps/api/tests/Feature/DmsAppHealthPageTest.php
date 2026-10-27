<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AppHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsAppHealthPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget('alphalink.app_health.latest');
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Health workspace',
            'slug' => 'health-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_owner_sees_dms_shaped_health_report_under_miscellaneous(): void
    {
        $url = route('console.miscellaneous.app-health', $this->workspace->getKey());

        $this->get($url)->assertOk()
            ->assertSee('APP HEALTH')
            ->assertSee('last run:')
            ->assertSee('CHECK')
            ->assertSee('Summary')
            ->assertSee('Message')
            ->assertSee('Uptime')
            ->assertSee('RAM')
            ->assertSee('Database')
            ->assertSee('Application storage')
            ->assertSee('Queue')
            ->assertSee('APP HEALTH', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('SETTINGS');

        $report = Cache::store('file')->get('alphalink.app_health.latest');
        $this->assertIsArray($report);
        $this->assertCount(5, $report['stored_check_results']);
    }

    public function test_cached_dms_health_result_is_displayed_without_running_checks_again(): void
    {
        Cache::store('file')->put('alphalink.app_health.latest', [
            'finished_at' => now()->toIso8601String(),
            'stored_check_results' => [[
                'label' => 'Synthetic check',
                'status' => 'ok',
                'shortSummary' => 'Synthetic cached summary',
                'notificationMessage' => 'Synthetic cached message',
            ]],
            'status' => 'ok',
        ], now()->addDay());

        $this->get(route('console.miscellaneous.app-health', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('Synthetic cached summary')
            ->assertSee('Synthetic cached message')
            ->assertDontSee('Database connection available');
    }

    public function test_health_route_returns_the_latest_report_as_json_when_requested(): void
    {
        Cache::store('file')->put('alphalink.app_health.latest', [
            'finished_at' => '2026-10-24T12:00:00+00:00',
            'stored_check_results' => [[
                'label' => 'Synthetic JSON check',
                'status' => 'ok',
                'shortSummary' => 'Cached JSON summary',
                'notificationMessage' => 'Cached JSON message',
            ]],
            'status' => 'ok',
        ], now()->addDay());

        $this->get(route('console.miscellaneous.app-health', [
            'workspace' => $this->workspace->getKey(),
            'json' => 1,
        ]))->assertOk()
            ->assertJsonPath('stored_check_results.0.label', 'Synthetic JSON check')
            ->assertJsonPath('status', 'ok');
    }

    public function test_health_page_matches_the_dms_admin_role_boundary(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->get(route('console.miscellaneous.app-health', $this->workspace->getKey()))
            ->assertForbidden();
    }

    public function test_ram_check_fails_only_above_the_dms_ninety_percent_threshold(): void
    {
        $health = app(AppHealthReport::class);

        $atThreshold = $health->evaluateMemory(1000, 100);
        $aboveThreshold = $health->evaluateMemory(1000, 99);

        $this->assertSame('ok', $atThreshold['status']);
        $this->assertSame('RAM usage is under 90%', $atThreshold['short_summary']);
        $this->assertSame('failed', $aboveThreshold['status']);
        $this->assertSame('RAM usage is over 90%', $aboveThreshold['short_summary']);
        $this->assertNull($health->evaluateMemory(0, 0));
        $this->assertNull($health->evaluateMemory(1000, 1001));
    }

    public function test_health_command_caches_results_without_sending_notifications(): void
    {
        Notification::fake();

        $this->artisan('alphalink:health-check')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertIsArray(Cache::store('file')->get('alphalink.app_health.latest'));
    }
}
