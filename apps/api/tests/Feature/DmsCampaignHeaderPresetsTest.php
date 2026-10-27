<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCampaignHeaderPresetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Campaign header test',
            'slug' => 'campaign-header-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_header_can_be_saved_listed_selected_and_deleted_with_audited_metadata_only(): void
    {
        $text = "MIME-version: 1.0\nContent-type: text/html\nSubject: [subject]";
        $storeUrl = route('console.campaign-headers.store', $this->workspace->getKey());

        $created = $this->postJson($storeUrl, ['name' => 'Default HTML', 'text' => $text])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('headers.0.name', 'Default HTML');
        $headerId = (int) $created->json('header_id');

        $this->getJson(route('console.campaign-headers.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertExactJson(['headers' => [['id' => $headerId, 'name' => 'Default HTML']]])
            ->assertDontSee($text);

        $this->getJson(route('console.campaign-headers.show', [$this->workspace->getKey(), $headerId]))
            ->assertOk()
            ->assertExactJson(['data' => $text]);

        $this->assertDatabaseHas('workspace_campaign_headers', [
            'id' => $headerId,
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
            'text' => $text,
        ]);
        $createdAudit = AuditEvent::query()->where('action', 'campaign.header.created')->sole();
        $this->assertSame(['name' => 'Default HTML'], $createdAudit->properties);
        $this->assertStringNotContainsString($text, json_encode($createdAudit->properties));

        $this->deleteJson(route('console.campaign-headers.destroy', [$this->workspace->getKey(), $headerId]))
            ->assertOk()
            ->assertExactJson(['status' => true, 'headers' => []]);
        $this->assertDatabaseMissing('workspace_campaign_headers', ['id' => $headerId]);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'campaign.header.deleted',
            'subject_id' => (string) $headerId,
        ]);
    }

    public function test_operators_only_load_and_delete_their_own_workspace_headers(): void
    {
        $otherOperator = User::factory()->create();
        $operator = User::factory()->create();
        $this->workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $otherHeader = $this->workspace->campaignHeaders()->create([
            'created_by' => $otherOperator->getKey(), 'name' => 'Private preset', 'text' => 'private body',
        ]);

        $this->actingAs($operator)
            ->getJson(route('console.campaign-headers.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertExactJson(['headers' => []]);
        $this->getJson(route('console.campaign-headers.show', [$this->workspace->getKey(), $otherHeader->getKey()]))
            ->assertNotFound();
        $this->deleteJson(route('console.campaign-headers.destroy', [$this->workspace->getKey(), $otherHeader->getKey()]))
            ->assertForbidden();

        $created = $this->postJson(route('console.campaign-headers.store', $this->workspace->getKey()), [
            'name' => 'My preset', 'text' => 'my header body',
        ])->assertOk();
        $myHeaderId = (int) $created->json('header_id');
        $this->assertDatabaseHas('workspace_campaign_headers', ['id' => $myHeaderId, 'created_by' => $operator->getKey()]);

        $this->deleteJson(route('console.campaign-headers.destroy', [$this->workspace->getKey(), $myHeaderId]))
            ->assertOk();
    }

    public function test_headers_are_workspace_scoped_and_campaign_forms_wire_the_library_for_create_and_revision(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other header workspace',
            'slug' => 'other-header-'.Str::lower(Str::random(8)),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $header = $otherWorkspace->campaignHeaders()->create([
            'created_by' => $otherOwner->getKey(), 'name' => 'Foreign preset', 'text' => 'foreign body',
        ]);

        $this->getJson(route('console.campaign-headers.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertExactJson(['headers' => []]);
        $this->getJson(route('console.campaign-headers.show', [$this->workspace->getKey(), $header->getKey()]))
            ->assertNotFound();
        $this->deleteJson(route('console.campaign-headers.destroy', [$this->workspace->getKey(), $header->getKey()]))
            ->assertNotFound();

        $createUrl = route('console.campaigns.create', $this->workspace->getKey());
        $this->get($createUrl)
            ->assertOk()
            ->assertSee('Saved Headers:')
            ->assertSee('campaign-header-library.js')
            ->assertSee('id="campaign-dms-header"', false);

        $campaign = $this->workspace->campaigns()->create([
            'created_by' => $this->owner->getKey(),
            'name' => 'Synthetic header revision campaign',
            'kind' => 'transactional',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revisionPayload = [
            'subject' => 'Synthetic subject',
            'from_name' => 'Synthetic sender',
            'from_email' => 'sender@example.test',
            'return_path' => '[rnd]@[domain]',
            'email_tests' => ['qa@example.test'],
            'header' => "MIME-version: 1.0\nSubject: [subject]",
        ];
        $campaign->revisions()->create([
            'revision_number' => 1,
            'payload' => $revisionPayload,
            'content_sha256' => hash('sha256', json_encode($revisionPayload)),
            'idempotency_key' => 'header-revision-test-'.Str::random(8),
            'request_fingerprint' => hash('sha256', $campaign->getKey()),
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
        ]);

        $this->get(route('console.campaigns.show', [$this->workspace->getKey(), $campaign->getKey()]))
            ->assertOk()
            ->assertSee('Saved Headers:')
            ->assertSee('campaign-header-library.js')
            ->assertSee('id="revision-dms-header"', false);
    }

    public function test_header_validation_and_workspace_role_are_enforced(): void
    {
        $url = route('console.campaign-headers.store', $this->workspace->getKey());
        $this->postJson($url, ['name' => '', 'text' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'text']);

        $privacyOfficer = User::factory()->create();
        $this->workspace->users()->attach($privacyOfficer->getKey(), ['role' => 'privacy_officer']);
        $this->actingAs($privacyOfficer)
            ->getJson(route('console.campaign-headers.index', $this->workspace->getKey()))
            ->assertForbidden();
    }
}
