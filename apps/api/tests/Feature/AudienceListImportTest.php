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

    public function test_reimport_does_not_move_a_contact_to_a_second_list(): void
    {
        $firstList = $this->makeList('First list');
        $secondList = $this->makeList('Second list');
        $url = $this->importUrl($firstList);

        $this->postJson($url, ['addresses' => 'same@example.com'])->assertOk()
            ->assertJsonPath('data.imported_count', 1);
        $contact = Contact::query()->firstOrFail();

        $this->postJson($this->importUrl($secondList), ['addresses' => 'SAME@example.com'])->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.duplicate_count', 1);

        $this->assertDatabaseHas('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $firstList->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseMissing('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        $this->postJson($this->listApiUrl($secondList).'/members', [
            'contact_id' => $contact->getKey(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('code', 'contact_already_assigned');
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
