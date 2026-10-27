<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudienceListImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'alphalink.contact_lookup_key' => str_repeat('k', 32),
            'alphalink.contact_lookup_key_version' => 1,
        ]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic workspace',
            'slug' => 'synthetic-workspace',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_import_normalizes_addresses_validates_tokens_and_deduplicates_by_address(): void
    {
        $list = $this->makeList('Primary list');

        $response = $this->postJson($this->importUrl($list), [
            'addresses' => "ALICE@EXAMPLE.COM,invalid\nbob@example.com, alice@example.com, \"quoted@example.com\"",
        ])->assertOk()
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.duplicate_count', 1)
            ->assertJsonPath('data.invalid_count', 2);

        $this->assertStringNotContainsString('alice@example.com', $response->getContent());
        $this->assertStringNotContainsString('bob@example.com', $response->getContent());
        $this->assertSame(['alice@example.com', 'bob@example.com'], Contact::query()->orderBy('email_lookup_hash')->get()->pluck('email')->sort()->values()->all());
        $this->assertSame(2, ContactListMembership::query()->where('audience_list_id', $list->getKey())->count());
        $this->assertSame(2, ContactListMembershipEvent::query()->where('audience_list_id', $list->getKey())->count());
        $this->assertSame(2, OutboxMessage::query()->where('event_type', 'contact.created')->count());
        $this->assertSame(2, OutboxMessage::query()->where('event_type', 'audience_list.member_added')->count());

        $encrypted = (string) Contact::query()->firstOrFail()->getRawOriginal('email');
        $this->assertStringNotContainsString('@example.com', $encrypted);
    }

    public function test_contact_can_belong_to_multiple_lists_while_reimports_are_idempotent(): void
    {
        $firstList = $this->makeList('First list');
        $secondList = $this->makeList('Second list');
        $url = $this->importUrl($firstList);

        $this->postJson($url, ['addresses' => 'same@example.com'])->assertOk()
            ->assertJsonPath('data.imported_count', 1);
        $contact = Contact::query()->firstOrFail();

        $this->postJson($this->importUrl($secondList), ['addresses' => 'SAME@example.com'])->assertOk()
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.duplicate_count', 0);

        $this->postJson($this->importUrl($firstList), ['addresses' => 'SAME@example.com'])->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.duplicate_count', 1);

        $this->assertDatabaseHas('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $firstList->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseHas('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        $thirdList = $this->makeList('Third list');
        $this->postJson($this->listApiUrl($thirdList).'/members', [
            'contact_id' => $contact->getKey(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseCount('contact_list_memberships', 3);
    }

    public function test_import_does_not_reactivate_an_opt_out_membership(): void
    {
        $list = $this->makeList('Opt-out list');
        $this->postJson($this->importUrl($list), ['addresses' => 'opted-out@example.com'])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 1);
        $membership = ContactListMembership::query()->sole();
        $membership->forceFill(['email_type' => 'opt_out', 'status' => 'removed'])->save();

        $this->postJson($this->importUrl($list), ['addresses' => 'opted-out@example.com'])
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.duplicate_count', 1);

        $this->assertSame('opt_out', $membership->fresh()->email_type);
        $this->assertSame('removed', $membership->fresh()->status);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_list_memberships', 1);
    }

    public function test_membership_profile_migration_refuses_a_data_losing_rollback(): void
    {
        $list = $this->makeList('Migration guard list');
        $contact = Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => 'profile@example.com',
            'email_lookup_hash' => hash_hmac('sha256', 'profile@example.com', str_repeat('k', 32)),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
            'profile' => ['vertical' => 'retail'],
        ]);
        $migration = require database_path('migrations/2026_10_27_182526_add_profile_to_contact_list_memberships.php');

        try {
            $migration->down();
            $this->fail('Expected populated membership profiles to block the destructive rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Use a forward migration', $exception->getMessage());
        }

        $this->assertDatabaseHas('contact_list_memberships', [
            'id' => $membership->getKey(),
            'profile' => $membership->getRawOriginal('profile'),
        ]);
    }

    public function test_only_workspace_operators_can_import_contacts(): void
    {
        $list = $this->makeList('Restricted list');
        $privacyOfficer = User::factory()->create();
        $this->workspace->users()->attach($privacyOfficer->getKey(), ['role' => 'privacy_officer']);

        $this->actingAs($privacyOfficer)
            ->postJson($this->importUrl($list), ['addresses' => 'person@example.com'])
            ->assertForbidden();

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_import_fails_closed_when_the_contact_lookup_key_is_unavailable(): void
    {
        $list = $this->makeList('Protected list');
        config(['alphalink.contact_lookup_key' => null]);

        $this->postJson($this->importUrl($list), ['addresses' => 'person@example.com'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'contact_storage_unavailable');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_import_rejects_more_than_five_thousand_candidates_before_writing(): void
    {
        $list = $this->makeList('Bounded list');
        $addresses = str_repeat('a@example.com,', 5000).'a@example.com';

        $this->postJson($this->importUrl($list), ['addresses' => $addresses])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['addresses']);

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_list_memberships', 0);
    }

    public function test_new_list_can_be_linked_to_a_workspace_isp_and_inherits_its_access_rules(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $isp = $this->workspace->isps()->create([
            'name' => 'AudienceISP',
            'tracked_countries' => ['US', 'CA'],
            'allowed_users' => [(int) $operator->getKey()],
            'allowed_sponsors' => [27],
        ]);
        $url = '/api/v1/workspaces/'.$this->workspace->getKey().'/audience-lists';
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $response = $this->postJson($url, [
            'name' => 'United States list',
            'isp_id' => $isp->getKey(),
            'country' => 'US',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.isp_id', $isp->getKey())
            ->assertJsonPath('data.country', 'US')
            ->assertJsonMissingPath('data.allowed_users')
            ->assertJsonMissingPath('data.allowed_sponsors');

        $list = AudienceList::query()->where('workspace_id', $this->workspace->getKey())->sole();
        $this->assertSame($isp->getKey(), $list->isp_id);
        $this->assertSame([(int) $operator->getKey()], $list->allowed_users);
        $this->assertSame([27], $list->allowed_sponsors);
        $this->assertSame($response->json('data.id'), $list->getKey());
    }

    public function test_list_creation_rejects_an_isp_outside_the_workspace_and_untracked_country(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other list workspace',
            'slug' => 'other-list-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $foreignIsp = $otherWorkspace->isps()->create(['name' => 'ForeignISP']);
        $localIsp = $this->workspace->isps()->create([
            'name' => 'LocalISP',
            'tracked_countries' => ['US'],
        ]);
        $url = '/api/v1/workspaces/'.$this->workspace->getKey().'/audience-lists';

        $this->postJson($url, [
            'name' => 'Foreign assignment',
            'isp_id' => $foreignIsp->getKey(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isp_id');

        $this->postJson($url, [
            'name' => 'Unsupported country',
            'isp_id' => $localIsp->getKey(),
            'country' => 'CA',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');

        $this->assertDatabaseCount('audience_lists', 0);
    }

    public function test_operator_cannot_attach_a_list_to_an_isp_without_management_access(): void
    {
        $isp = $this->workspace->isps()->create(['name' => 'ManagedISP']);
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->postJson('/api/v1/workspaces/'.$this->workspace->getKey().'/audience-lists', [
                'name' => 'Operator list',
                'isp_id' => $isp->getKey(),
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden();

        $this->assertDatabaseCount('audience_lists', 0);
    }

    private function makeList(string $name): AudienceList
    {
        return $this->workspace->audienceLists()->create([
            'created_by' => $this->owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
        ]);
    }

    private function importUrl(AudienceList $list): string
    {
        return $this->listApiUrl($list).'/members/import';
    }

    private function listApiUrl(AudienceList $list): string
    {
        return '/api/v1/workspaces/'.$this->workspace->getKey().'/audience-lists/'.$list->getKey();
    }
}
