<?php

namespace Tests\Feature;

use App\Jobs\ProcessWorkspacePublicOptOutJob;
use App\Models\AudienceList;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use App\Services\Campaigns\ProcessWorkspacePublicOptOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsPublicLandingApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private string $workspaceKey = 'synthetic-workspace-mini-app-key';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'alphalink.contact_lookup_key' => str_repeat('k', 32),
            'alphalink.contact_lookup_key_version' => 1,
            'infrastructure.ip_api_key' => 'synthetic-ip-api-key',
        ]);

        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic public intake workspace',
            'slug' => 'synthetic-public-intake-workspace',
        ]);
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'mini_app_key' => $this->workspaceKey,
        ]);

        Http::fake([
            'https://pro.ip-api.com/json/*' => Http::response([
                'status' => 'success',
                'countryCode' => 'US',
                'country' => 'United States',
                'regionName' => 'Synthetic Region',
                'city' => 'Synthetic City',
                'proxy' => false,
                'hosting' => false,
                'org' => 'Synthetic ISP',
            ]),
        ]);
    }

    public function test_subscribe_persists_dms_request_and_visitor_metadata(): void
    {
        $this->postJson('/api/v1/subscribe', [
            ...$this->authPayload(),
            'email' => 'reader@example.test',
            'ip' => '192.0.2.19',
            'user_agent' => $this->iphoneUserAgent(),
            'referer' => 'https://landing.example.test/subscribe',
        ])->assertNoContent();

        $this->assertDatabaseHas('workspace_subscribers', [
            'workspace_id' => $this->workspace->getKey(),
            'email' => 'reader@example.test',
            'ip' => '192.0.2.19',
            'country' => 'United States',
            'state' => 'Synthetic Region',
            'city' => 'Synthetic City',
            'device' => 'iPhone',
            'platform' => 'iOS',
            'browser' => 'Safari',
            'is_mobile' => true,
        ]);

        $owner = User::factory()->create();
        $this->workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $this->actingAs($owner)
            ->getJson(route('console.miscellaneous.subscribers', $this->workspace->getKey()).'?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.email', 'reader@example.test');
    }

    public function test_contact_persists_the_dms_required_fields_and_visitor_metadata(): void
    {
        $this->postJson('/api/v1/contact', [
            ...$this->authPayload(),
            'full_name' => 'Synthetic Recipient',
            'email' => 'recipient@example.test',
            'phone' => '+1 555 0100',
            'message' => 'Please send product details.',
            'ip' => '192.0.2.20',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0',
            'referer' => 'https://landing.example.test/contact',
        ])->assertNoContent();

        $this->assertDatabaseHas('workspace_contact_submissions', [
            'workspace_id' => $this->workspace->getKey(),
            'full_name' => 'Synthetic Recipient',
            'email' => 'recipient@example.test',
            'phone' => '+1 555 0100',
            'message' => 'Please send product details.',
            'country' => 'United States',
            'device' => null,
            'platform' => 'Linux',
            'browser' => 'Firefox',
            'is_mobile' => false,
        ]);

        $owner = User::factory()->create();
        $this->workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $this->actingAs($owner)
            ->getJson(route('console.miscellaneous.contacts', $this->workspace->getKey()).'?draw=1&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.message', 'Please send product details.');
    }

    public function test_public_intake_rejects_an_invalid_workspace_key_and_contact_validation_matches_dms(): void
    {
        $this->postJson('/api/v1/subscribe', [
            ...$this->authPayload('wrong-key'),
            'email' => 'reader@example.test',
            'ip' => '192.0.2.19',
            'user_agent' => 'Synthetic Browser',
        ])->assertForbidden();

        $this->postJson('/api/v1/contact', [
            ...$this->authPayload(),
            'full_name' => str_repeat('n', 256),
            'email' => 'not-an-email',
            'phone' => '+1 555 0100',
            'message' => 'Details',
            'ip' => '192.0.2.20',
            'user_agent' => 'Synthetic Browser',
        ])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'email']);

        $this->assertSame(0, $this->workspace->subscribers()->count());
        $this->assertSame(0, $this->workspace->contactSubmissions()->count());
    }

    public function test_opt_out_queues_only_a_workspace_match_and_updates_the_list_without_country_movement(): void
    {
        $list = $this->makeList();
        $contact = $this->makeContact('reader@example.test');
        $membership = $this->addMembership($list, $contact, 'click');
        Queue::fake();

        $this->postJson('/api/v1/opt-out', [
            ...$this->authPayload(),
            'email' => 'READER@example.test',
            'ip' => '192.0.2.21',
            'user_agent' => $this->iphoneUserAgent(),
        ])->assertNoContent();

        Queue::assertPushed(ProcessWorkspacePublicOptOutJob::class, fn (ProcessWorkspacePublicOptOutJob $job): bool => $job->workspaceId === $this->workspace->getKey()
            && $job->contactId === $contact->getKey()
            && $job->ip === '192.0.2.21');
        $this->assertSame('click', $membership->fresh()->email_type);

        app(ProcessWorkspacePublicOptOut::class)->process(
            (string) $this->workspace->getKey(),
            (string) $contact->getKey(),
            '192.0.2.21',
            $this->iphoneUserAgent(),
        );
        app(ProcessWorkspacePublicOptOut::class)->process(
            (string) $this->workspace->getKey(),
            (string) $contact->getKey(),
            '192.0.2.21',
            $this->iphoneUserAgent(),
        );

        $this->assertSame('opt_out', $membership->fresh()->email_type);
        $this->assertSame($list->getKey(), $membership->fresh()->audience_list_id);
        $this->assertSame(1, ContactListMembershipEvent::query()
            ->where('contact_id', $contact->getKey())
            ->where('action', 'opt_out')
            ->count());
        $this->assertDatabaseMissing('suppression_events', ['contact_id' => $contact->getKey()]);
        $this->assertSame('United States', $contact->fresh()->profile['country']);
    }

    public function test_opt_out_returns_no_content_for_a_miss_and_preserves_leads(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/opt-out', [
            ...$this->authPayload(),
            'email' => 'missing@example.test',
            'ip' => '192.0.2.22',
            'user_agent' => 'Synthetic Browser',
        ])->assertNoContent();
        Queue::assertNothingPushed();

        $list = $this->makeList();
        $contact = $this->makeContact('lead@example.test');
        $membership = $this->addMembership($list, $contact, 'lead');
        app(ProcessWorkspacePublicOptOut::class)->process(
            (string) $this->workspace->getKey(),
            (string) $contact->getKey(),
            null,
            'Synthetic Browser',
        );

        $this->assertSame('lead', $membership->fresh()->email_type);
        $this->assertDatabaseHas('contact_list_membership_events', [
            'workspace_id' => $this->workspace->getKey(),
            'contact_id' => $contact->getKey(),
            'action' => 'opt_out',
        ]);
    }

    public function test_intake_endpoints_apply_the_dms_two_requests_per_minute_limit(): void
    {
        $payload = [
            ...$this->authPayload(),
            'email' => 'reader@example.test',
            'ip' => '192.0.2.23',
            'user_agent' => 'Synthetic Browser',
        ];

        $this->postJson('/api/v1/subscribe', $payload)->assertNoContent();
        $this->postJson('/api/v1/subscribe', $payload)->assertNoContent();
        $this->postJson('/api/v1/subscribe', $payload)->assertTooManyRequests();
        $this->assertSame(2, $this->workspace->subscribers()->count());
    }

    /** @return array{workspace_id: string, key: string} */
    private function authPayload(?string $key = null): array
    {
        return [
            'workspace_id' => (string) $this->workspace->getKey(),
            'key' => $key ?? $this->workspaceKey,
        ];
    }

    private function makeList(): AudienceList
    {
        return $this->workspace->audienceLists()->create([
            'name' => 'Synthetic intake list',
            'slug' => 'synthetic-intake-list-'.Str::lower(Str::random(6)),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ]);
    }

    private function makeContact(string $email): Contact
    {
        $normalizedEmail = mb_strtolower(trim($email));

        return Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => $normalizedEmail,
            'email_lookup_hash' => hash_hmac('sha256', $normalizedEmail, (string) config('alphalink.contact_lookup_key')),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function addMembership(AudienceList $list, Contact $contact, string $type): ContactListMembership
    {
        return ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => $type,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function iphoneUserAgent(): string
    {
        return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    }
}
