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

class DmsAudienceListHistoryTest extends TestCase
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
            'name' => 'Synthetic history workspace',
            'slug' => 'synthetic-history-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->isp = $this->workspace->isps()->create([
            'name' => 'History ISP',
            'tracked_countries' => ['US'],
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'is_active' => true,
        ]);
        $this->actingAs($this->owner);
    }

    public function test_history_page_is_linked_and_uses_dms_history_controls(): void
    {
        $this->get(route('console.infrastructure.list-history.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('List History')
            ->assertSee('ALL LISTS')
            ->assertSee('LOAD HISTORY')
            ->assertSee('Older history rows can show NA')
            ->assertDontSee('Proxy, Blacklist, or Hard Bounce recipient state')
            ->assertSee(route('console.infrastructure.list-history.index', $this->workspace->getKey()), false);
    }

    public function test_list_selector_only_returns_lists_for_active_isp_in_the_workspace(): void
    {
        $first = $this->makeList('Alpha');
        $this->makeList('Bravo');

        $this->getJson(route('console.infrastructure.list-history.lists', [$this->workspace->getKey(), $this->isp->getKey()]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->getKey())
            ->assertJsonPath('data.0.name', 'Alpha');

        $otherWorkspace = $this->makeOtherWorkspace();
        $otherIsp = $otherWorkspace->isps()->create([
            'name' => 'Private ISP',
            'tracked_countries' => [],
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'is_active' => true,
        ]);

        $this->getJson(route('console.infrastructure.list-history.lists', [$this->workspace->getKey(), $otherIsp->getKey()]))
            ->assertNotFound();
    }

    public function test_single_list_history_is_date_filtered_and_reports_backed_dms_types(): void
    {
        $list = $this->makeList('Alpha');
        $this->history($list, '2026-10-20', ['fresh' => 12, 'open' => 3]);
        $this->history($list, '2026-10-22', [
            'fresh' => 10,
            'delivery' => 5,
            'bot' => 2,
            'proxy' => 3,
            'blacklist' => 4,
            'hard_bounce' => 5,
        ]);

        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), [
            'isp' => $this->isp->getKey(),
            'list' => $list->getKey(),
            'from' => '2026-10-21',
            'to' => '2026-10-24',
        ])->assertOk()
            ->assertJsonPath('scope', 'single_list')
            ->assertJsonPath('list.name', 'Alpha')
            ->assertJsonCount(1, 'histories')
            ->assertJsonPath('histories.0.at', '2026-10-22')
            ->assertJsonPath('histories.0.fresh', 10)
            ->assertJsonPath('histories.0.delivery', 5)
            ->assertJsonPath('histories.0.bot', 2)
            ->assertJsonPath('histories.0.opt_out', 'NA')
            ->assertJsonPath('histories.0.proxy', 3)
            ->assertJsonPath('histories.0.blacklist', 4)
            ->assertJsonPath('histories.0.hard_bounce', 5)
            ->assertJsonMissingPath('histories.0.email');
    }

    public function test_existing_history_rows_keep_na_for_nullable_counts_until_recalculated(): void
    {
        $list = $this->makeList('Older Alpha');
        $this->history($list, '2026-10-20', ['fresh' => 12, 'open' => 3]);

        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), [
            'isp' => $this->isp->getKey(),
            'list' => $list->getKey(),
            'from' => '2026-10-20',
            'to' => '2026-10-20',
        ])->assertOk()
            ->assertJsonPath('histories.0.opt_out', 'NA')
            ->assertJsonPath('histories.0.proxy', 'NA')
            ->assertJsonPath('histories.0.blacklist', 'NA')
            ->assertJsonPath('histories.0.hard_bounce', 'NA');
    }

    public function test_recalculated_history_counts_opt_out_memberships(): void
    {
        $list = $this->makeList('Opt out history');
        $email = 'opt-out-'.Str::lower(Str::random(8)).'@example.test';
        $contact = Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'opt_out',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->artisan('audience-lists:calculate-history', ['listId' => $list->getKey()])
            ->assertExitCode(0);

        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), [
            'isp' => $this->isp->getKey(),
            'list' => $list->getKey(),
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('histories.0.opt_out', 1);
    }

    public function test_all_lists_history_sums_each_day_within_the_selected_isp(): void
    {
        $first = $this->makeList('Alpha');
        $second = $this->makeList('Bravo');
        $this->history($first, '2026-10-22', ['fresh' => 10, 'delivery' => 2, 'proxy' => 2]);
        $this->history($second, '2026-10-22', ['fresh' => 5, 'open' => 4, 'proxy' => 3]);
        $this->history($second, '2026-10-23', ['click' => 3]);

        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), [
            'isp' => $this->isp->getKey(),
            'list' => '0',
            'from' => '2026-10-20',
            'to' => '2026-10-24',
        ])->assertOk()
            ->assertJsonPath('scope', 'all_lists')
            ->assertJsonPath('list.name', 'History ISP')
            ->assertJsonCount(2, 'histories')
            ->assertJsonPath('histories.0.at', '2026-10-22')
            ->assertJsonPath('histories.0.fresh', 15)
            ->assertJsonPath('histories.0.delivery', 2)
            ->assertJsonPath('histories.0.open', 4)
            ->assertJsonPath('histories.0.proxy', 5)
            ->assertJsonPath('histories.1.click', 3)
            ->assertJsonPath('histories.1.opt_out', 'NA');
    }

    public function test_history_rejects_cross_isp_lists_invalid_dates_and_non_manager_roles(): void
    {
        $list = $this->makeList('Alpha');
        $otherIsp = $this->workspace->isps()->create([
            'name' => 'Other ISP',
            'tracked_countries' => [],
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'is_active' => true,
        ]);

        $base = ['isp' => $otherIsp->getKey(), 'list' => $list->getKey(), 'from' => '2026-10-20', 'to' => '2026-10-24'];
        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), $base)->assertNotFound();

        $base['isp'] = $this->isp->getKey();
        $base['from'] = '2026-10-25';
        $this->postJson(route('console.infrastructure.list-history.data', $this->workspace->getKey()), $base)
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator);
        $this->get(route('console.infrastructure.list-history.index', $this->workspace->getKey()))->assertForbidden();
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

    private function history(AudienceList $list, string $date, array $counts): void
    {
        AudienceListHistory::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'at' => $date,
            ...array_fill_keys(['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'], 0),
            ...$counts,
        ]);
    }

    private function makeOtherWorkspace(): Workspace
    {
        $otherOwner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Other history workspace',
            'slug' => 'other-history-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $workspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
