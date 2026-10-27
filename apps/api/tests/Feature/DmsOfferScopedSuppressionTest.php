<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\SuppressionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsOfferScopedSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_offer_unsubscribe_is_scoped_to_its_workspace_offer(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Offer suppression workspace',
            'slug' => 'offer-suppression-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic suppression sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://example.test',
            'username' => 'fixture',
            'password' => 'fixture',
            'api_key' => 'fixture',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic suppression offer',
            'type' => 'CPA',
            'vertical' => 'fixture',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test/offer',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $email = 'person@example.test';
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($owner)->postJson(
            '/api/v1/workspaces/'.$workspace->getKey().'/contacts/'.$contact->getKey().'/suppressions',
            [
                'reason' => 'unsubscribe',
                'offer_id' => $offer->getKey(),
                'effective_at' => now()->toIso8601String(),
            ],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertCreated()
            ->assertJsonPath('data.scope_type', 'offer')
            ->assertJsonPath('data.scope_key', (string) $offer->getKey());

        $this->assertDatabaseHas('suppression_events', [
            'id' => $response->json('data.id'),
            'workspace_id' => $workspace->getKey(),
            'contact_id' => $contact->getKey(),
            'scope_type' => 'offer',
            'scope_key' => (string) $offer->getKey(),
            'reason' => 'unsubscribe',
        ]);
    }

    public function test_offer_scoped_suppression_requires_unsubscribe_and_rejects_foreign_offers(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Scoped suppression workspace',
            'slug' => 'scoped-suppression-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $email = 'person@example.test';
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic scoped sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://example.test',
            'username' => 'fixture',
            'password' => 'fixture',
            'api_key' => 'fixture',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic scoped offer',
            'type' => 'CPA',
            'vertical' => 'fixture',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test/offer',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $endpoint = '/api/v1/workspaces/'.$workspace->getKey().'/contacts/'.$contact->getKey().'/suppressions';
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $this->actingAs($owner)->postJson($endpoint, [
            'reason' => 'complaint',
            'offer_id' => $offer->getKey(),
            'effective_at' => now()->toIso8601String(),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($endpoint, [
            'reason' => 'unsubscribe',
            'offer_id' => 999999,
            'effective_at' => now()->toIso8601String(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offer_id');

        $this->assertSame(0, SuppressionEvent::query()->count());
    }
}
