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

        $this->get(route('clean.console.miscellaneous.subscribers'))
            ->assertOk()
            ->assertSee('subscribers')
            ->assertSee('is mobile')
            ->assertSee('SETTINGS')
            ->assertSee('href="'.route('clean.console.miscellaneous.subscribers').'"', false)
            ->assertSee('href="'.route('clean.console.miscellaneous.contacts').'"', false)
            ->assertSee('href="'.route('clean.console.miscellaneous.horizon').'"', false)
            ->assertSee('href="'.route('clean.console.miscellaneous.app-health').'"', false)
            ->assertSee('href="'.route('clean.console.app-settings.index').'"', false)
            ->assertSee('href="'.route('clean.console.miscellaneous.app-errors').'"', false);

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

        $this->get(route('clean.console.miscellaneous.contacts'))
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
            $this->get(route('clean.console.miscellaneous.'.$page))->assertForbidden();
        }
    }

    public function test_horizon_page_embeds_the_dashboard_and_explains_queue_ownership(): void
    {
        $this->get(route('clean.console.miscellaneous.horizon'))
            ->assertOk()
            ->assertSee('Horizon')
            ->assertSee('Laravel Horizon dashboard')
            ->assertSee('Redis-backed default queue')
            ->assertSee('PostgreSQL-backed suppression worker')
            ->assertSee('Restart is not available from this page.');

        if (! class_exists(HorizonServiceProvider::class)) {
            $this->get(route('clean.console.miscellaneous.horizon'))
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
