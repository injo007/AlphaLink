<?php

namespace Tests\Feature;

use App\Jobs\ProcessWorkspaceDropSuppression;
use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDropSuppression;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use App\Services\Campaigns\DmsCampaignRecipientSnapshotBuilder;
use App\Services\Campaigns\DmsDropSuppressionProcessor;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WorkspaceDropSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private mixed $originalContactLookupKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContactLookupKey = config('alphalink.contact_lookup_key');
        config(['alphalink.contact_lookup_key' => str_repeat('synthetic-drop-suppression-key-', 2)]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        config(['alphalink.contact_lookup_key' => $this->originalContactLookupKey]);
        parent::tearDown();
    }

    public function test_page_and_catalog_reproduce_sponsor_offer_isp_list_filters_and_active_history_total(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner);
        AudienceListHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'at' => now()->toDateString(),
            'fresh' => 12,
            'delivery' => 7,
            'open' => 0,
            'opt_out' => 200,
        ]);

        $this->actingAs($owner)->get(route('clean.console.drops.suppression'))
            ->assertOk()
            ->assertSee('Drop Suppression')
            ->assertSee('START SUPPRESSION')
            ->assertSee('drops-suppression-sponsor')
            ->assertSee('drops/suppression/catalog', false)
            ->assertSee('offer-suppression-file-download')
            ->assertSee('DOWNLOAD SAVED FILE')
            ->assertSee('offer-suppression-file-manage')
            ->assertSee('OPEN FILE MANAGER')
            ->assertSee('data-offer-file-manage-url-template')
            ->assertSee('drops-suppression.js');

        $this->actingAs($owner)->getJson(route('console.drops.suppression', [
            $workspace->getKey(),
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($owner)->getJson(route('console.drops.suppression.catalog', [
            $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isp_ids' => [$isp->getKey()],
        ]))->assertOk()
            ->assertJsonPath('offers.0.id', $offer->getKey())
            ->assertJsonPath('offers.0.has_suppression_file', true)
            ->assertJsonPath('offers.0.can_run_suppression', true)
            ->assertJsonPath('isps.0.id', $isp->getKey())
            ->assertJsonPath('lists.0.id', $list->getKey())
            ->assertJsonPath('lists.0.total', 19);
    }

    public function test_selected_offer_suppression_file_can_be_downloaded_only_by_workspace_managers(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        [, $offer] = $this->eligibleScope($workspace, $owner);
        $offer->forceFill(['is_active' => false])->save();
        $url = route('console.drops.suppression.offer-file.download', [$workspace->getKey(), $offer->getKey()]);

        $this->actingAs($owner)->getJson(route('console.drops.suppression.catalog', [
            $workspace->getKey(),
            'sponsor_id' => $offer->sponsor_id,
        ]))->assertOk()
            ->assertJsonPath('offers.0.has_suppression_file', true)
            ->assertJsonPath('offers.0.can_run_suppression', false);

        $response = $this->actingAs($owner)->get($url)->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'attachment; filename=suppression-offer-'.$offer->getKey().'.txt');

        $this->assertStringContainsString('private', $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertSame("blocked@example.test\n", file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->actingAs($operator)->get($url)->assertForbidden();
    }

    public function test_catalog_includes_inactive_and_missing_file_offers_for_suppression_file_management(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $eligibleOffer] = $this->eligibleScope($workspace, $owner);
        $offerNeedingFile = $eligibleOffer->replicate();
        $offerNeedingFile->forceFill([
            'offer_id' => 'offer-needs-file',
            'name' => 'Offer needs suppression file',
            'is_active' => false,
            'suppression_path' => null,
            'suppression_sha256' => null,
            'suppression_format' => null,
            'suppression_updated_at' => null,
        ])->save();

        $response = $this->actingAs($owner)->getJson(route('console.drops.suppression.catalog', [
            $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
        ]))->assertOk()->assertJsonCount(2, 'offers');

        $response->assertJsonFragment([
            'id' => $offerNeedingFile->getKey(),
            'offer_id' => 'offer-needs-file',
            'has_suppression_file' => false,
            'can_run_suppression' => false,
        ])->assertJsonFragment([
            'id' => $eligibleOffer->getKey(),
            'has_suppression_file' => true,
            'can_run_suppression' => true,
        ]);
    }

    public function test_offer_file_upload_from_suppression_page_saves_private_file_and_refreshes_offer_metadata(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer] = $this->eligibleScope($workspace, $owner, null);
        $url = route('console.drops.suppression.offer-file', $workspace->getKey());

        $response = $this->actingAs($owner)->post($url, [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('suppression.csv', "Blocked@example.test\n"),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.message', 'Suppression file uploaded and verified.')
            ->assertJsonPath('data.format', 'email')
            ->assertJsonPath('data.entry_count', 1)
            ->assertJsonPath('data.can_run_suppression', true)
            ->assertJsonMissingPath('data.path');

        $offer->refresh();
        $this->assertSame('email', $offer->suppression_format);
        $this->assertSame(now()->toDateString(), $offer->suppression_updated_at?->toDateString());
        Storage::disk('local')->assertExists($offer->suppression_path);
        $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($offer->suppression_path));
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $workspace->getKey(),
            'action' => 'offer.suppression_uploaded',
            'subject_id' => (string) $offer->getKey(),
        ]);
        $this->assertStringNotContainsString($offer->suppression_path, $response->getContent());
    }

    public function test_offer_suppression_file_upload_is_manager_only_and_scoped_to_the_selected_sponsor(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        [$sponsor, $offer] = $this->eligibleScope($workspace, $owner, null);
        $url = route('console.drops.suppression.offer-file', $workspace->getKey());
        $file = UploadedFile::fake()->createWithContent('suppression.txt', "blocked@example.test\n");

        $this->actingAs($operator)->post($url, [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => $file,
        ], ['Accept' => 'application/json'])->assertForbidden();

        $otherSponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Other suppression sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://other-affiliate.example.test',
            'username' => 'other-user',
            'password' => 'other-password',
            'api_key' => 'other-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $this->actingAs($owner)->post($url, [
            'sponsor_id' => $otherSponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('suppression.txt', "blocked@example.test\n"),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertNull($offer->fresh()->suppression_path);
    }

    public function test_start_persists_only_stale_lists_and_processor_writes_offer_list_exclusions_used_by_campaign_snapshots(): void
    {
        config(['alphalink.contact_lookup_key' => str_repeat('synthetic-drop-suppression-key-', 2)]);
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, null);
        $this->history($workspace, $list, 2);
        $blocked = $this->contact($workspace, $list, 'blocked@example.test');
        $eligible = $this->contact($workspace, $list, 'eligible@example.test');

        $this->actingAs($owner)->post(route('console.drops.suppression.offer-file', $workspace->getKey()), [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('suppression.txt', "blocked@example.test\n"),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.entry_count', 1)
            ->assertJsonPath('data.can_run_suppression', true);
        $offer->refresh();
        $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($offer->suppression_path));

        $this->actingAs($owner)->postJson(route('console.drops.suppression.start', $workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'offer' => $offer->getKey(),
            'isp' => [$isp->getKey()],
            'lists' => [$list->getKey()],
        ])->assertOk()->assertJsonPath('data', 'Process Started');

        $run = WorkspaceDropSuppression::query()->sole();
        $this->assertSame('start', $run->status);
        $this->assertSame([(string) $list->getKey()], $run->lists);
        Queue::assertPushedOn('drop-suppression', ProcessWorkspaceDropSuppression::class);
        $queuedJob = Queue::pushed(ProcessWorkspaceDropSuppression::class)->sole();
        $this->assertSame('drop_suppression', $queuedJob->connection);
        $this->assertSame('drop-suppression', $queuedJob->queue);
        $this->assertSame(1, $queuedJob->tries);
        $this->assertSame(0, $queuedJob->timeout);
        $this->assertSame('database', config('queue.connections.drop_suppression.driver'));
        $this->assertSame(86400, config('queue.connections.drop_suppression.retry_after'));
        (new ProcessWorkspaceDropSuppression((int) $run->getKey()))->handle(app(DmsDropSuppressionProcessor::class));

        $run->refresh();
        $this->assertSame('complete', $run->status);
        $this->assertSame(1, $run->found);
        $this->assertDatabaseHas('workspace_offer_list_suppressions', [
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $blocked->getKey(),
            'suppression_run_id' => $run->getKey(),
        ]);
        $this->assertDatabaseMissing('workspace_offer_list_suppressions', ['contact_id' => $eligible->getKey()]);

        $currentSuppression = "different@example.test\n";
        Storage::disk('local')->put($offer->suppression_path, $currentSuppression);
        $offer->forceFill(['suppression_sha256' => hash('sha256', $currentSuppression)])->save();
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Suppression consumer',
            'kind' => 'campaign',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['fixture' => true],
            'content_sha256' => hash('sha256', 'suppression-consumer'),
            'created_by' => $owner->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'suppression-revision'),
            'created_at' => now(),
        ]);

        $snapshot = app(DmsCampaignRecipientSnapshotBuilder::class)->build($workspace, $campaign, (string) $revision->getKey(), [
            'offer_id' => $offer->getKey(),
            'audience_segments' => [$list->getKey().'_fresh'],
            'order_by' => 'default',
            'start_from' => 0,
        ]);

        $this->assertInstanceOf(CampaignRecipientSnapshot::class, $snapshot);
        $this->assertSame(1, $snapshot->recipient_count);
        $this->assertSame(1, $snapshot->offer_suppressed_count);
        $this->assertSame([$eligible->getKey()], DB::table('campaign_recipient_snapshot_items')
            ->where('snapshot_id', $snapshot->getKey())->pluck('contact_id')->all());

        $this->actingAs($owner)->postJson(route('console.drops.suppression.start', $workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'offer' => $offer->getKey(),
            'isp' => [$isp->getKey()],
            'lists' => [$list->getKey()],
        ])->assertStatus(400)->assertJsonPath('data', 'Suppression up to date');
        $this->assertSame(1, WorkspaceDropSuppression::query()->count());
    }

    public function test_missing_suppression_file_is_recorded_as_terminal_error_and_operator_cannot_run_unassigned_scope(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, null);
        $this->history($workspace, $list, 1);
        $offer->forceFill([
            'suppression_path' => 'offers/missing-suppression.txt',
            'suppression_sha256' => hash('sha256', 'missing-but-fresh'),
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ])->save();

        $this->actingAs($owner)->postJson(route('console.drops.suppression.start', $workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'offer' => $offer->getKey(),
            'isp' => [$isp->getKey()],
            'lists' => [$list->getKey()],
        ])->assertOk();
        $run = WorkspaceDropSuppression::query()->sole();
        (new ProcessWorkspaceDropSuppression((int) $run->getKey()))->handle(app(DmsDropSuppressionProcessor::class));
        $this->assertSame('error NFS', $run->fresh()->status);
        $this->assertSame('The current suppression file could not be refreshed from its affiliate source.', $run->fresh()->failure_message);

        $this->actingAs($operator)->postJson(route('console.drops.suppression.start', $workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'offer' => $offer->getKey(),
            'isp' => [$isp->getKey()],
            'lists' => [$list->getKey()],
        ])->assertNotFound();
    }

    public function test_md5_suppression_matches_normalized_contact_email(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, null);
        $email = 'blocked-md5@example.test';
        $hash = md5($email);
        $path = 'offers/fixture-md5-suppression.txt';
        Storage::disk('local')->put($path, $hash."\n");
        $offer->forceFill([
            'suppression_path' => $path,
            'suppression_sha256' => hash('sha256', $hash."\n"),
            'suppression_format' => 'md5',
        ])->save();
        $contact = $this->contact($workspace, $list, strtoupper($email));
        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => [$isp->getKey()],
            'lists' => [(string) $list->getKey()],
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());

        $this->assertSame('complete', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->found);
        $this->assertDatabaseHas('workspace_offer_list_suppressions', [
            'offer_id' => $offer->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
    }

    public function test_sha512_suppression_matches_normalized_contact_email(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, null);
        $email = 'blocked-sha512@example.test';
        $hash = strtoupper(hash('sha512', $email));
        $sourcePath = tempnam(sys_get_temp_dir(), 'alphalink-sha512-suppression-');
        $this->assertIsString($sourcePath);
        file_put_contents($sourcePath, $hash."\n");
        try {
            $stored = app(DmsOfferSuppressionFile::class)->storeDownloadedFile($workspace, $offer, $sourcePath);
        } finally {
            @unlink($sourcePath);
        }
        $offer->forceFill([
            'suppression_path' => $stored['path'],
            'suppression_sha256' => $stored['sha256'],
            'suppression_format' => $stored['format'],
            'suppression_updated_at' => now()->toDateString(),
        ])->save();
        $contact = $this->contact($workspace, $list, strtoupper($email));
        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => [$isp->getKey()],
            'lists' => [(string) $list->getKey()],
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());

        $this->assertSame('sha512', $offer->fresh()->suppression_format);
        $this->assertSame('complete', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->found);
        $this->assertDatabaseHas('workspace_offer_list_suppressions', [
            'offer_id' => $offer->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
    }

    public function test_campaign_snapshot_excludes_sha512_suppressed_email(): void
    {
        config(['alphalink.contact_lookup_key' => str_repeat('synthetic-campaign-contact-key-', 2)]);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [, $offer, , $list] = $this->eligibleScope($workspace, $owner, null);
        $email = 'blocked-campaign-sha512@example.test';
        $contact = $this->contact($workspace, $list, strtoupper($email));
        $eligible = $this->contact($workspace, $list, 'eligible-campaign@example.test');
        $stored = app(DmsOfferSuppressionFile::class)
            ->storeContent($workspace, $offer, hash('sha512', $email)."\n");
        $offer->forceFill([
            'suppression_path' => $stored['path'],
            'suppression_sha256' => $stored['sha256'],
            'suppression_format' => $stored['format'],
            'suppression_updated_at' => now()->toDateString(),
        ])->save();
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'SHA-512 suppression snapshot',
            'kind' => 'campaign',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['fixture' => true],
            'content_sha256' => hash('sha256', 'sha512-campaign-suppression'),
            'created_by' => $owner->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'sha512-suppression-revision'),
            'created_at' => now(),
        ]);

        $snapshot = app(DmsCampaignRecipientSnapshotBuilder::class)->build($workspace, $campaign, (string) $revision->getKey(), [
            'offer_id' => $offer->getKey(),
            'audience_segments' => [$list->getKey().'_fresh'],
            'order_by' => 'default',
            'start_from' => 0,
        ]);

        $this->assertSame(1, $snapshot->recipient_count);
        $this->assertSame(1, $snapshot->offer_suppressed_count);
        $this->assertDatabaseMissing('campaign_recipient_snapshot_items', [
            'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseHas('campaign_recipient_snapshot_items', [
            'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $eligible->getKey(),
        ]);
    }

    public function test_failed_match_import_rolls_back_all_exclusions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $emails = collect(range(1, 101))->map(fn (int $number): string => "blocked-{$number}@example.test");
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, $emails->implode("\n")."\n");
        $contacts = $emails->map(fn (string $email): Contact => $this->contact($workspace, $list, $email));
        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => [$isp->getKey()],
            'lists' => [(string) $list->getKey()],
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);
        $failedContactId = $contacts->last()->getKey();
        DB::unprepared("CREATE TRIGGER fail_suppression_import BEFORE INSERT ON workspace_offer_list_suppressions WHEN NEW.contact_id = '{$failedContactId}' BEGIN SELECT RAISE(ABORT, 'synthetic import failure'); END");

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());

        $this->assertSame('error EX', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->found);
        $this->assertDatabaseCount('workspace_offer_list_suppressions', 0);
    }

    public function test_empty_suppression_file_uses_dms_complete_ns_status(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, '');
        $this->contact($workspace, $list, 'eligible@example.test');
        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => [$isp->getKey()],
            'lists' => [(string) $list->getKey()],
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());

        $this->assertSame('complete NS', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->found);
        $this->assertDatabaseCount('workspace_offer_list_suppressions', 0);
        $this->actingAs($owner)->postJson(route('console.drops.suppression.kill', $workspace->getKey()), [
            'suppression_id' => $run->getKey(),
        ])->assertStatus(400)->assertJsonPath('data', 'Error');
    }

    public function test_kill_marks_a_queued_run_and_processor_does_not_apply_exclusions_afterward(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner);
        $this->history($workspace, $list, 1);
        $contact = $this->contact($workspace, $list, 'blocked@example.test');

        $this->actingAs($owner)->postJson(route('console.drops.suppression.start', $workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'offer' => $offer->getKey(),
            'isp' => [$isp->getKey()],
            'lists' => [$list->getKey()],
        ])->assertOk();
        $run = WorkspaceDropSuppression::query()->sole();

        $this->actingAs($owner)->postJson(route('console.drops.suppression.kill', $workspace->getKey()), [
            'suppression_id' => $run->getKey(),
        ])->assertOk()->assertJsonPath('data', 'Killed Successfully');

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());
        $this->assertSame('killed', $run->fresh()->status);
        $this->assertDatabaseMissing('workspace_offer_list_suppressions', ['contact_id' => $contact->getKey()]);
        $this->actingAs($owner)->getJson(route('console.drops.suppression.monitor', [$workspace->getKey(), $run->getKey()]))
            ->assertOk()->assertJsonPath('status', 'killed');
    }

    public function test_kill_during_affiliate_refresh_remains_terminal_when_refresh_fails(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        [$sponsor, $offer, $isp, $list] = $this->eligibleScope($workspace, $owner, null);
        $run = WorkspaceDropSuppression::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isps' => [$isp->getKey()],
            'lists' => [(string) $list->getKey()],
            'status' => 'start',
            'found' => 0,
            'elapsed' => 'NA',
            'started_at' => now(),
        ]);

        $refresher = Mockery::mock(DmsAffiliateSuppressionRefresher::class);
        $refresher->shouldReceive('refresh')->once()->andReturnUsing(function () use ($run): bool {
            $run->forceFill(['status' => 'killed', 'finished_at' => now()])->save();

            return false;
        });
        $this->app->instance(DmsAffiliateSuppressionRefresher::class, $refresher);

        app(DmsDropSuppressionProcessor::class)->process((int) $run->getKey());

        $this->assertSame('killed', $run->fresh()->status);
        $this->assertNull($run->fresh()->failure_message);
        $this->assertDatabaseCount('workspace_offer_list_suppressions', 0);
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Drop suppression workspace',
            'slug' => 'drop-suppression-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    /** @return array{WorkspaceSponsor, WorkspaceOffer, Isp, AudienceList} */
    private function eligibleScope(Workspace $workspace, User $owner, ?string $suppressionContent = "blocked@example.test\n"): array
    {
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Fixture suppression sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'fixture-offer',
            'name' => 'Fixture suppression offer',
            'type' => 'cpa',
            'vertical' => 'testing',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'suppression_path' => null,
            'suppression_sha256' => $suppressionContent === null ? null : hash('sha256', $suppressionContent),
            'suppression_format' => $suppressionContent === null ? null : 'email',
            'suppression_updated_at' => now()->toDateString(),
            'allowed_users' => [],
            'is_active' => true,
        ]);
        if ($suppressionContent !== null) {
            $sha256 = hash('sha256', $suppressionContent);
            $path = sprintf('offers/%s/%d/suppression/%s.txt', $workspace->getKey(), $offer->getKey(), $sha256);
            Storage::disk('local')->put($path, $suppressionContent);
            $offer->forceFill(['suppression_path' => $path])->save();
        }
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Fixture ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [$sponsor->getKey()],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Fixture audience',
            'slug' => 'fixture-audience-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'fixture-list-'.Str::uuid()),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [$sponsor->getKey()],
        ]);

        return [$sponsor, $offer, $isp, $list];
    }

    private function contact(Workspace $workspace, AudienceList $list, string $email): Contact
    {
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => is_string(config('alphalink.contact_lookup_key')) && strlen((string) config('alphalink.contact_lookup_key')) >= 32
                ? hash_hmac('sha256', mb_strtolower($email), (string) config('alphalink.contact_lookup_key'))
                : hash('sha256', mb_strtolower($email)),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $contact;
    }

    private function history(Workspace $workspace, AudienceList $list, int $fresh): void
    {
        AudienceListHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'at' => now()->toDateString(),
            'fresh' => $fresh,
        ]);
    }
}
