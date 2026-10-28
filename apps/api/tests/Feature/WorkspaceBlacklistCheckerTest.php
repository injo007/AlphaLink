<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceBlacklistProcess;
use App\Services\Audiences\ProcessWorkspaceBlacklistFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceBlacklistCheckerTest extends TestCase
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
        ]);
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Blacklist workspace',
            'slug' => 'blacklist-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_email_file_reclassifies_matching_non_lead_memberships_and_refreshes_history(): void
    {
        [$firstList, $secondList, $leadList] = $this->makeLists();
        $alice = $this->makeContact('ALICE@example.test');
        $lead = $this->makeContact('lead@example.test');
        $firstMembership = $this->makeMembership($firstList, $alice, 'fresh');
        $secondMembership = $this->makeMembership($secondList, $alice, 'click');
        $leadMembership = $this->makeMembership($leadList, $lead, 'lead');
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other blacklist workspace',
            'slug' => 'other-blacklist-'.Str::lower(Str::random(8)),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherIsp = Isp::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'name' => 'Other ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);
        $otherList = AudienceList::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'created_by' => $otherOwner->getKey(),
            'name' => 'Other Alice list', 'slug' => 'other-alice-list', 'status' => 'active',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'other-alice-list'),
            'isp_id' => $otherIsp->getKey(),
        ]);
        $otherAlice = Contact::query()->create([
            'workspace_id' => $otherWorkspace->getKey(),
            'email' => 'alice@example.test',
            'email_lookup_hash' => hash_hmac('sha256', 'alice@example.test', str_repeat('k', 32)),
            'lookup_key_version' => 1,
            'profile' => [],
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $otherMembership = ContactListMembership::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'audience_list_id' => $otherList->getKey(),
            'contact_id' => $otherAlice->getKey(), 'email_type' => 'fresh', 'status' => 'active', 'joined_at' => now(),
        ]);

        $response = $this->postJson(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
            'file' => UploadedFile::fake()->createWithContent('review.csv', "ALICE@example.test,Alice\nbad-address\nlead@example.test\nmissing@example.test"),
            'email_type' => 'blacklist',
        ])->assertAccepted()->assertJsonPath('data.status', 'queued');

        $processId = $response->json('data.id');
        $process = WorkspaceBlacklistProcess::query()->findOrFail($processId);
        $privatePath = Storage::disk('local')->path($process->storage_path);
        $this->assertStringStartsWith(Storage::disk('local')->path(''), $privatePath);
        $this->assertStringNotContainsString('/public/', str_replace('\\', '/', $privatePath));
        $this->assertStringNotContainsString('alice@example.test', json_encode($process->getAttributes(), JSON_THROW_ON_ERROR));

        app(ProcessWorkspaceBlacklistFile::class)->processNextChunk($processId);

        $process->refresh();
        $this->assertSame('completed', $process->status);
        $this->assertSame(4, $process->processed_count);
        $this->assertSame(2, $process->matched_count);
        $this->assertSame(2, $process->updated_count);
        $this->assertSame(1, $process->invalid_count);
        $this->assertDatabaseCount('workspace_blacklist_process_lists', 2);
        $this->assertSame('blacklist', $firstMembership->fresh()->email_type);
        $this->assertSame('blacklist', $secondMembership->fresh()->email_type);
        $this->assertSame('lead', $leadMembership->fresh()->email_type);
        $this->assertSame('fresh', $otherMembership->fresh()->email_type);
        $this->assertSame(2, ContactListMembershipEvent::query()->where('source', 'admin_blacklist_check')->count());
        $this->assertDatabaseHas('contact_list_membership_events', [
            'workspace_id' => $this->workspace->getKey(),
            'contact_id' => $alice->getKey(),
            'source' => 'admin_blacklist_check',
        ]);
        $this->assertSame(1, AudienceListHistory::query()->where('audience_list_id', $firstList->getKey())->value('blacklist'));
        $this->assertSame(1, AudienceListHistory::query()->where('audience_list_id', $secondList->getKey())->value('blacklist'));
        $this->assertFalse(Storage::disk('local')->exists($process->storage_path));
    }

    public function test_queued_process_can_be_cancelled_and_its_private_file_is_removed(): void
    {
        $this->makeLists();
        $response = $this->postJson(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
            'file' => UploadedFile::fake()->createWithContent('addresses.txt', "alice@example.test\nbob@example.test"),
            'email_type' => 'proxy',
        ])->assertAccepted();
        $process = WorkspaceBlacklistProcess::query()->findOrFail($response->json('data.id'));

        $this->patchJson(route('console.infrastructure.blacklist-checker.cancel', [$this->workspace->getKey(), $process->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $process->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($process->storage_path));
        $this->assertDatabaseCount('contact_list_membership_events', 0);
    }

    public function test_processing_resumes_after_500_lines_and_keeps_replays_idempotent(): void
    {
        [$list] = $this->makeLists();
        $contact = $this->makeContact('repeat@example.test');
        $membership = $this->makeMembership($list, $contact, 'fresh');
        $content = str_repeat("repeat@example.test\n", 500)."repeat@example.test\n";
        $response = $this->postJson(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
            'file' => UploadedFile::fake()->createWithContent('large.txt', $content),
            'email_type' => 'blacklist',
        ])->assertAccepted();
        $processId = $response->json('data.id');

        app(ProcessWorkspaceBlacklistFile::class)->processNextChunk($processId);
        $process = WorkspaceBlacklistProcess::query()->findOrFail($processId);
        $this->assertSame('running', $process->status);
        $this->assertSame(500, $process->processed_count);
        $this->assertSame(1, $process->updated_count);
        $this->assertGreaterThan(0, $process->byte_offset);

        app(ProcessWorkspaceBlacklistFile::class)->processNextChunk($processId);
        $process->refresh();
        $this->assertSame('completed', $process->status);
        $this->assertSame(501, $process->processed_count);
        $this->assertSame(1, $process->updated_count);
        $this->assertSame('blacklist', $membership->fresh()->email_type);
        $this->assertDatabaseCount('contact_list_membership_events', 1);
    }

    public function test_running_process_cancels_at_a_chunk_boundary_and_refreshes_partial_history(): void
    {
        [$list] = $this->makeLists();
        $contact = $this->makeContact('partial@example.test');
        $membership = $this->makeMembership($list, $contact, 'fresh');
        $response = $this->postJson(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
            'file' => UploadedFile::fake()->createWithContent('partial.txt', str_repeat("partial@example.test\n", 501)),
            'email_type' => 'blacklist',
        ])->assertAccepted();
        $processId = $response->json('data.id');
        app(ProcessWorkspaceBlacklistFile::class)->processNextChunk($processId);

        $this->patchJson(route('console.infrastructure.blacklist-checker.cancel', [$this->workspace->getKey(), $processId]))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelling');

        app(ProcessWorkspaceBlacklistFile::class)->processNextChunk($processId);
        $process = WorkspaceBlacklistProcess::query()->findOrFail($processId);
        $this->assertSame('cancelled', $process->status);
        $this->assertSame(500, $process->processed_count);
        $this->assertSame(1, $process->updated_count);
        $this->assertSame('blacklist', $membership->fresh()->email_type);
        $this->assertSame(1, AudienceListHistory::query()->where('audience_list_id', $list->getKey())->value('blacklist'));
        $this->assertFalse(Storage::disk('local')->exists($process->storage_path));
    }

    public function test_legacy_dms_hash_file_is_rejected_without_creating_a_process(): void
    {
        $this->makeLists();

        $this->withHeader('Accept', 'application/json')
            ->post(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
                'file' => UploadedFile::fake()->createWithContent('hashes.txt', "0123456789abcdef0123456789abcdef\n"),
                'email_type' => 'blacklist',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('workspace_blacklist_processes', 0);
        $this->assertDatabaseCount('contact_list_membership_events', 0);
    }

    public function test_process_listing_is_workspace_scoped_and_requires_workspace_admin_access(): void
    {
        $this->makeLists();
        $response = $this->postJson(route('console.infrastructure.blacklist-checker.upload', $this->workspace->getKey()), [
            'file' => UploadedFile::fake()->createWithContent('addresses.txt', 'alice@example.test'),
            'email_type' => 'bot',
        ])->assertAccepted();

        $this->getJson(route('console.infrastructure.blacklist-checker', $this->workspace->getKey()).'?json=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->followingRedirects()->actingAs($operator)
            ->get(route('console.infrastructure.blacklist-checker', $this->workspace->getKey()))
            ->assertForbidden();
    }

    public function test_blacklist_checker_page_is_linked_from_isp_management_navigation(): void
    {
        $this->followingRedirects()->get(route('console.infrastructure.blacklist-checker', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('Blacklist Checker')
            ->assertSee('UPLOAD AND CHECK')
            ->assertSee('BLACKLIST CHECKER')
            ->assertSee('DMS files whose first value is a 32-character hash are rejected');
    }

    /** @return array{0: AudienceList, 1: AudienceList, 2: AudienceList} */
    private function makeLists(): array
    {
        $isp = Isp::query()->create([
            'workspace_id' => $this->workspace->getKey(), 'name' => 'Blacklist ISP', 'is_active' => true,
            'allowed_users' => [], 'allowed_sponsors' => [], 'tracked_countries' => [], 'quality_emails' => [],
        ]);

        return collect(['First', 'Second', 'Lead'])->map(fn (string $name): AudienceList => AudienceList::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
            'name' => $name.' list',
            'slug' => Str::slug($name.'-'.$this->workspace->getKey()),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name.Str::uuid()),
            'isp_id' => $isp->getKey(),
        ]))->all();
    }

    private function makeContact(string $email): Contact
    {
        $normalized = mb_strtolower($email);

        return Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => $normalized,
            'email_lookup_hash' => hash_hmac('sha256', $normalized, str_repeat('k', 32)),
            'lookup_key_version' => 1,
            'profile' => [],
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function makeMembership(AudienceList $list, Contact $contact, string $type): ContactListMembership
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
}
