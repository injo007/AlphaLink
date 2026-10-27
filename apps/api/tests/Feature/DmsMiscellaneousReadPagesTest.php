<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceContactSubmission;
use App\Models\WorkspaceSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Horizon\HorizonServiceProvider;
use Tests\TestCase;

class DmsMiscellaneousReadPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace($this->owner, 'owner');
        $this->actingAs($this->owner);
    }

    public function test_subscribers_page_and_datatable_are_workspace_scoped_and_searchable(): void
    {
        WorkspaceSubscriber::query()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'alice.synthetic@example.test',
            'country' => 'US',
            'city' => 'Denver',
            'is_mobile' => true,
        ]);
        WorkspaceSubscriber::query()->create([
            'workspace_id' => $this->workspace->id,
            'email' => 'bob.synthetic@example.test',
            'country' => 'GB',
            'is_mobile' => false,
        ]);

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->makeWorkspace($otherOwner, 'owner');
        WorkspaceSubscriber::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'email' => 'outside.synthetic@example.test',
        ]);

        $this->get(route('console.miscellaneous.subscribers', $this->workspace->id))
            ->assertOk()
            ->assertSee('subscribers')
            ->assertSee('is mobile')
            ->assertSee('SETTINGS')
            ->assertSee('href="'.route('console.miscellaneous.subscribers', $this->workspace->id).'"', false)
            ->assertSee('href="'.route('console.miscellaneous.contacts', $this->workspace->id).'"', false)
            ->assertSee('href="'.route('console.miscellaneous.horizon', $this->workspace->id).'"', false)
            ->assertSee('href="'.route('console.miscellaneous.app-health', $this->workspace->id).'"', false)
            ->assertSee('href="'.route('console.app-settings.index', $this->workspace->id).'"', false)
            ->assertSee('href="'.route('console.miscellaneous.app-errors', $this->workspace->id).'"', false);

        $this->getJson(route('console.miscellaneous.subscribers', $this->workspace->id).'?draw=4&start=0&length=10&search%5Bvalue%5D=alice')
            ->assertOk()
            ->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.email', 'alice.synthetic@example.test')
            ->assertJsonPath('data.0.is_mobile', true)
            ->assertJsonMissing(['email' => 'outside.synthetic@example.test']);
    }

    public function test_contacts_page_returns_details_only_from_the_selected_workspace(): void
    {
        $submission = WorkspaceContactSubmission::query()->create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Synthetic Contact',
            'email' => 'contact.synthetic@example.test',
            'phone' => '+10000000000',
            'message' => 'Synthetic support question.',
            'ip' => '203.0.113.22',
            'country' => 'US',
            'state' => 'CO',
            'city' => 'Denver',
            'device' => 'Desktop',
            'platform' => 'Linux',
            'browser' => 'Firefox',
            'is_mobile' => false,
        ]);

        $this->get(route('console.miscellaneous.contacts', $this->workspace->id))
            ->assertOk()
            ->assertSee('contact us')
            ->assertSee('SEE MORE')
            ->assertSee('<dialog', false)
            ->assertSee('data-contact-field="message"', false);

        $this->getJson(route('console.miscellaneous.contacts', $this->workspace->id).'?draw=2&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.id', $submission->id)
            ->assertJsonPath('data.0.message', 'Synthetic support question.')
            ->assertJsonPath('data.0.ip', '203.0.113.22');
    }

    public function test_miscellaneous_read_pages_keep_the_dms_admin_role_boundary(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator);
        foreach (['subscribers', 'contacts', 'horizon'] as $page) {
            $this->get(route('console.miscellaneous.'.$page, $this->workspace->id))->assertForbidden();
        }
    }

    public function test_horizon_page_explains_the_missing_dashboard_without_a_restart_action(): void
    {
        $this->get(route('console.miscellaneous.horizon', $this->workspace->id))
            ->assertOk()
            ->assertSee('Horizon')
            ->assertSee('Queue connection:')
            ->assertSee('Worker activity is not verified here.')
            ->assertSee('No restart action is available.');

        if (! class_exists(HorizonServiceProvider::class)) {
            $this->get(route('console.miscellaneous.horizon', $this->workspace->id))
                ->assertSee('Horizon dashboard unavailable');
        }
    }

    private function makeWorkspace(User $user, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Misc '.Str::random(6),
            'slug' => 'misc-'.Str::lower(Str::random(10)),
            'created_by' => $user->getKey(),
        ]);
        $workspace->users()->attach($user->getKey(), ['role' => $role]);

        return $workspace;
    }
}
