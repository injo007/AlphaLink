<?php

namespace Tests\Feature;

use App\Jobs\DownloadWorkspaceOfferSuppressionFile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferSuppressionDownload;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DmsOfferManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'DMS offer management',
            'slug' => 'offer-test-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_manual_create_matches_dms_fields_inherits_sponsor_access_and_saves_from_and_subject_assets(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor('Example Network', ['allowed_users' => [(int) $operator->getKey()]]);

        $this->postJson(route('console.offers.store', $this->workspace->getKey()), $this->offerPayload($sponsor))
            ->assertOk()->assertExactJson(['data' => 'Saved successfully']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('external-42', $offer->offer_id);
        $this->assertSame('cpa', $offer->type);
        $this->assertSame('insurance', $offer->vertical);
        $this->assertSame(['US', 'CA'], $offer->geo_target);
        $this->assertSame(WorkspaceOffer::ALLOWED_DAYS, $offer->allowed_days);
        $this->assertSame([(int) $operator->getKey()], $offer->allowed_users);
        $this->assertSame('live', $offer->status);
        $this->assertFalse($offer->is_active);
        $this->assertSame(['from', 'from', 'subject'], $offer->assets->pluck('type')->sort()->values()->all());
        $this->assertSame(['hello@example.test', 'offers@example.test'], $offer->fromAssets()->pluck('value')->sort()->values()->all());
        $this->assertSame(['A clear offer'], $offer->subjectAssets()->pluck('value')->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'offer.created', 'subject_id' => (string) $offer->getKey()]);
    }

    public function test_a_new_offer_can_receive_its_suppression_file_from_the_dedicated_drops_page(): void
    {
        $sponsor = $this->createSponsor('Suppression After Create');
        $this->postJson(route('console.offers.store', $this->workspace->getKey()), $this->offerPayload($sponsor))
            ->assertOk();
        $offer = $this->workspace->offers()->sole();
        $this->assertNull($offer->suppression_path);
        $this->assertFalse($offer->is_active);

        $response = $this->post(route('console.drops.suppression.offer-file', $this->workspace->getKey()), [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('offer-suppression.txt', "blocked@example.test\n"),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.message', 'Suppression file uploaded and verified.')
            ->assertJsonPath('data.offer_id', $offer->getKey());

        $offer->refresh();
        $this->assertSame('email', $offer->suppression_format);
        $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer));
        Storage::disk('local')->assertExists($offer->suppression_path);
        $this->assertStringNotContainsString($offer->suppression_path, $response->getContent());
    }

    public function test_offer_download_action_queues_one_private_sponsor_fetch_and_exposes_scoped_progress(): void
    {
        Queue::fake();
        $sponsor = $this->createSponsor('Sponsor suppression download');
        $offer = $this->createOffer($sponsor, ['offer_id' => '71234']);
        $startUrl = route('console.offers.suppression-file.download', [$this->workspace->getKey(), $offer->getKey()]);

        $started = $this->postJson($startUrl)->assertAccepted()
            ->assertJsonPath('download.status', 'queued')
            ->assertJsonMissing(['api_key' => $sponsor->api_key]);
        $downloadId = $started->json('download.id');
        Queue::assertPushedOn('drop-suppression', DownloadWorkspaceOfferSuppressionFile::class, fn ($job): bool => $job->downloadId === $downloadId);

        $this->postJson($startUrl)->assertAccepted()->assertJsonPath('download.id', $downloadId);
        $this->assertSame(1, WorkspaceOfferSuppressionDownload::query()->count());

        $this->getJson(route('console.offers.suppression-file.download-status', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $downloadId,
        ]))->assertOk()
            ->assertJsonPath('download.status', 'queued')
            ->assertJsonPath('download.percent', null)
            ->assertJsonPath('download.manage_url', route('clean.console.drops.suppression', [
                'sponsor_id' => $sponsor->getKey(),
                'offer_id' => $offer->getKey(),
            ]));

        $download = WorkspaceOfferSuppressionDownload::query()->findOrFail($downloadId);
        $download->forceFill(['status' => 'saving', 'entry_count' => 100, 'processed_entries' => 45])->save();
        $this->getJson(route('console.offers.suppression-file.download-status', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $downloadId,
        ]))->assertOk()
            ->assertJsonPath('download.status', 'saving')
            ->assertJsonPath('download.percent', 45)
            ->assertJsonPath('download.processed_entries', 45);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)->getJson(route('console.offers.suppression-file.download-status', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $downloadId,
        ]))->assertForbidden();
    }

    public function test_download_job_saves_a_valid_synthetic_provider_file_and_final_progress(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
        $sponsor = $this->createSponsor('Synthetic Everflow suppression');
        $offer = $this->createOffer($sponsor, ['offer_id' => '71234']);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'queued',
        ]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/*' => Http::response([
                'relationship' => ['email_optout' => ['suppression_file_link' => 'https://files.example.test/suppression.txt']],
            ]),
            'https://files.example.test/suppression.txt' => Http::response("blocked@example.test\n", 200),
        ]);

        (new DownloadWorkspaceOfferSuppressionFile($download->getKey()))->handle(app(DmsAffiliateSuppressionRefresher::class));

        $download->refresh();
        $offer->refresh();
        $this->assertSame('completed', $download->status, (string) $download->failure_message);
        $this->assertGreaterThan(0, $download->downloaded_bytes);
        $this->assertSame($download->downloaded_bytes, $download->total_bytes);
        $this->assertSame(1, $download->entry_count);
        $this->assertSame(1, $download->processed_entries);
        $this->assertSame('email', $download->format);
        $this->assertSame(hash('sha256', "blocked@example.test\n"), $download->sha256);
        $this->assertSame($download->sha256, $offer->suppression_sha256);
        $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer));
        $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($offer->suppression_path));
        Http::assertSentCount(2);
    }

    public function test_stale_suppression_download_is_marked_failed_and_can_be_retried(): void
    {
        Queue::fake();
        $sponsor = $this->createSponsor('Stalled suppression');
        $offer = $this->createOffer($sponsor, ['offer_id' => '71236']);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'validating',
        ]);
        DB::table('workspace_offer_suppression_downloads')->where('id', $download->getKey())
            ->update(['updated_at' => now()->subMinutes(11)]);

        $this->getJson(route('console.offers.suppression-file.download-status', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $download->getKey(),
        ]))->assertOk()
            ->assertJsonPath('download.status', 'failed')
            ->assertJsonPath('download.failure_message', 'Suppression processing stopped without updating for 10 minutes. Check the suppression worker and retry the download.');

        $this->postJson(route('console.offers.suppression-file.download', [
            $this->workspace->getKey(),
            $offer->getKey(),
        ]))->assertAccepted()->assertJsonPath('download.status', 'queued')
            ->assertJsonPath('download.failure_message', null);
        $this->assertSame(2, WorkspaceOfferSuppressionDownload::query()->count());
    }

    public function test_large_suppression_normalization_reports_validation_and_save_progress(): void
    {
        $offer = $this->createOffer($this->createSponsor('Suppression progress'));
        $sourcePath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-progress-');
        $this->assertIsString($sourcePath);
        $hashes = [];
        for ($index = 0; $index < 12_000; $index++) {
            $hashes[] = md5('suppression-'.$index);
        }
        $hashes[] = $hashes[0];
        $hashes[] = $hashes[1];
        file_put_contents($sourcePath, implode("\n", $hashes)."\n");
        $progress = [];

        try {
            $stored = app(DmsOfferSuppressionFile::class)->storeDownloadedFile(
                $this->workspace,
                $offer,
                $sourcePath,
                static function (int $bytes, ?int $total, string $stage, ?int $processed = null, ?int $entriesTotal = null) use (&$progress): void {
                    $progress[] = [$stage, $bytes, $total, $processed, $entriesTotal];
                },
                filesize($sourcePath),
            );
        } finally {
            @unlink($sourcePath);
        }

        $this->assertSame('md5', $stored['format']);
        $this->assertSame(12_000, $stored['count']);
        $this->assertContains('validating', array_column($progress, 0));
        $this->assertContains('saving', array_column($progress, 0));
        $savingProgress = array_values(array_filter($progress, static fn (array $item): bool => $item[0] === 'saving'));
        $this->assertSame(12_000, end($savingProgress)[3]);
        $this->assertSame(12_000, end($savingProgress)[4]);
    }

    public function test_suppression_worker_reports_a_safe_actionable_dns_failure(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['127.0.0.1']),
        );
        $sponsor = $this->createSponsor('Synthetic Everflow suppression');
        $offer = $this->createOffer($sponsor, ['offer_id' => '71235']);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'queued',
        ]);
        Http::fake();

        (new DownloadWorkspaceOfferSuppressionFile($download->getKey()))->handle(app(DmsAffiliateSuppressionRefresher::class));

        $this->assertSame(
            'The sponsor endpoint could not be reached safely: The affiliate provider host did not resolve to a public address.',
            $download->fresh()->failure_message,
        );
        Http::assertNothingSent();
    }

    public function test_suppression_worker_reports_a_failed_large_file_transfer_as_storage_or_network_issue(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
        $sponsor = $this->createSponsor('Synthetic Everflow suppression');
        $offer = $this->createOffer($sponsor, ['offer_id' => '71236']);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'queued',
        ]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/*' => Http::response([
                'relationship' => ['email_optout' => ['suppression_file_link' => 'https://files.example.test/suppression.txt']],
            ]),
            'https://files.example.test/suppression.txt' => Http::response('', 503),
        ]);

        (new DownloadWorkspaceOfferSuppressionFile($download->getKey()))->handle(app(DmsAffiliateSuppressionRefresher::class));

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertSame(
            'The sponsor returned a download link, but AlphaLink could not finish saving the file. Check free space in app storage and outbound HTTPS from the app server, then retry.',
            $download->fresh()->failure_message,
        );
        $this->assertNull($offer->fresh()->suppression_path);
        Http::assertSentCount(2);
    }

    public function test_suppression_worker_does_not_report_server_processing_errors_as_invalid_file_content(): void
    {
        $sponsor = $this->createSponsor('Suppression processing failure');
        $offer = $this->createOffer($sponsor);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'queued',
        ]);
        $refresher = Mockery::mock(DmsAffiliateSuppressionRefresher::class);
        $refresher->shouldReceive('refreshWithProgress')->once()->andReturnUsing(function (Workspace $workspace, WorkspaceOffer $offer, ?\Closure $onProgress): never {
            $onProgress(100, 100, 'validating');
            throw new \ErrorException('Synthetic storage write failure.');
        });
        $this->app->instance(DmsAffiliateSuppressionRefresher::class, $refresher);

        (new DownloadWorkspaceOfferSuppressionFile($download->getKey()))->handle($refresher);

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertSame(
            'The suppression download failed inside AlphaLink. Diagnostic ID '.$download->getKey().'; check the application log and retry.',
            $download->fresh()->failure_message,
        );
        $this->assertStringNotContainsString('invalid', strtolower($download->fresh()->failure_message));
    }

    public function test_suppression_worker_keeps_file_validation_feedback_for_actual_validation_errors(): void
    {
        $sponsor = $this->createSponsor('Suppression validation failure');
        $offer = $this->createOffer($sponsor);
        $download = WorkspaceOfferSuppressionDownload::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'requested_by' => $this->owner->getKey(),
            'status' => 'queued',
        ]);
        $refresher = Mockery::mock(DmsAffiliateSuppressionRefresher::class);
        $refresher->shouldReceive('refreshWithProgress')->once()->andReturnUsing(function (Workspace $workspace, WorkspaceOffer $offer, ?\Closure $onProgress): never {
            $onProgress(100, 100, 'validating');
            throw ValidationException::withMessages(['suppression_file' => 'Synthetic invalid content.']);
        });

        (new DownloadWorkspaceOfferSuppressionFile($download->getKey()))->handle($refresher);

        $this->assertSame(
            'The downloaded file failed suppression validation. Confirm it contains valid email addresses, MD5 hashes, or SHA-512 hashes, then retry.',
            $download->fresh()->failure_message,
        );
    }

    public function test_offer_id_is_unique_per_sponsor_even_when_previous_offer_is_soft_deleted(): void
    {
        $sponsor = $this->createSponsor('Unique ID Network');
        $offer = $this->createOffer($sponsor, ['offer_id' => 'same-id']);
        $offer->delete();

        $payload = $this->offerPayload($sponsor);
        $payload['offer_id'] = 'same-id';
        $this->postJson(route('console.offers.store', $this->workspace->getKey()), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('offer_id');
    }

    public function test_manual_offer_validation_rejects_missing_dms_required_fields(): void
    {
        $sponsor = $this->createSponsor('Validation Network');
        $this->postJson(route('console.offers.store', $this->workspace->getKey()), [
            'sponsor_id' => $sponsor->getKey(),
            'name' => 'Incomplete offer',
        ])->assertUnprocessable()->assertJsonValidationErrors(['offer_id', 'type', 'vertical', 'payout', 'geo_target', 'allowed_days', 'redirect', 'from', 'subjects']);
    }

    public function test_offer_edits_keep_asset_rows_and_do_not_silently_replace_access(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor('Edit Network', ['allowed_users' => [(int) $operator->getKey()]]);
        $offer = $this->createOffer($sponsor, ['allowed_users' => []]);
        $offer->assets()->create(['workspace_id' => $this->workspace->getKey(), 'type' => 'from', 'value' => 'keep@example.test']);

        $payload = $this->offerPayload($sponsor);
        $payload['name'] = 'Edited offer';
        unset($payload['from'], $payload['subjects']);
        $this->putJson(route('console.offers.update', [$this->workspace->getKey(), $offer->getKey()]), $payload)
            ->assertOk()->assertExactJson(['data' => 'Offer Updated']);

        $this->assertSame('Edited offer', $offer->fresh()->name);
        $this->assertSame([], $offer->fresh()->allowed_users);
        $this->assertDatabaseHas('workspace_offer_assets', ['offer_id' => $offer->getKey(), 'value' => 'keep@example.test']);
    }

    public function test_offer_activation_fails_until_suppression_is_fresh_and_other_dms_requirements_hold(): void
    {
        $offer = $this->createOffer($this->createSponsor('Activation Network'));
        $url = route('console.offers.active', [$this->workspace->getKey(), $offer->getKey()]);

        $this->patchJson($url, ['status' => true])->assertStatus(400)->assertJsonPath('data', 'Something is empty, all fields must be filled (redirect, status, sup file ...)');
        $this->assertFalse($offer->fresh()->is_active);

        $this->attachSuppressionFile($offer, "blocked@example.test\n");
        $this->patchJson($url, ['status' => true])->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertTrue($offer->fresh()->is_active);

        $offer->forceFill(['suppression_updated_at' => now()->subDays(8)->toDateString()])->save();
        $this->patchJson($url, ['status' => true])->assertStatus(400);
    }

    public function test_single_sponsor_user_assignment_cascades_to_offers_but_bulk_assignment_does_not(): void
    {
        $firstOperator = User::factory()->create();
        $secondOperator = User::factory()->create();
        $this->workspace->users()->attach($firstOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($secondOperator->getKey(), ['role' => 'operator']);
        $first = $this->createSponsor('Cascade Sponsor');
        $second = $this->createSponsor('Bulk Sponsor');
        $firstOffer = $this->createOffer($first);
        $secondOffer = $this->createOffer($second);

        $this->putJson(route('console.sponsors.users.update', [$this->workspace->getKey(), $first->getKey()]), [
            'users' => [$firstOperator->getKey()],
        ])->assertOk();
        $this->assertSame([(int) $firstOperator->getKey()], $firstOffer->fresh()->allowed_users);

        $this->putJson(route('console.sponsors.bulk-users.update', $this->workspace->getKey()), [
            'sponsors' => [$second->getKey()],
            'users' => [$secondOperator->getKey()],
        ])->assertOk();
        $this->assertSame([(int) $secondOperator->getKey()], $second->fresh()->allowed_users);
        $this->assertSame([], $secondOffer->fresh()->allowed_users);
    }

    public function test_operator_offers_require_both_sponsor_and_offer_access_and_never_return_internal_assignment_ids(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor('Assigned Network', ['allowed_users' => [(int) $operator->getKey()]]);
        $visible = $this->createOffer($sponsor, [
            'allowed_users' => [(int) $operator->getKey()],
            'is_active' => true,
            'suppression_path' => 'test/suppression.csv',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $this->attachSuppressionFile($visible, "blocked@example.test\n");
        $this->createOffer($sponsor, ['offer_id' => 'offer-else', 'is_active' => true, 'allowed_users' => []]);
        $inactiveSponsor = $this->createSponsor('Inactive Network', ['is_active' => false, 'allowed_users' => [(int) $operator->getKey()]]);
        $this->createOffer($inactiveSponsor, ['offer_id' => 'inactive-sponsor-offer', 'is_active' => true, 'allowed_users' => [(int) $operator->getKey()]]);
        $url = route('console.networks.offers.index', $this->workspace->getKey());

        $response = $this->actingAs($operator)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->getKey())
            ->assertJsonMissingPath('data.0.allowed_users')
            ->assertJsonMissing(['name' => 'Inactive Network']);
        $this->assertSame([(int) $operator->getKey()], $visible->fresh()->allowed_users);
        $response->assertJsonMissing(['name' => 'offer-else']);
    }

    public function test_delete_clears_suppression_and_restore_is_workspace_scoped(): void
    {
        $offer = $this->createOffer($this->createSponsor('Delete Network'), [
            'suppression_path' => 'test/suppression.csv',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $url = route('console.offers.destroy', [$this->workspace->getKey(), $offer->getKey()]);
        $this->deleteJson($url)->assertOk()->assertExactJson(['data' => 'Offer deleted']);
        $this->assertSoftDeleted('workspace_offers', ['id' => $offer->getKey()]);
        $this->assertNull($offer->fresh()->suppression_path);
        $this->assertNull($offer->fresh()->suppression_sha256);
        $this->assertNull($offer->fresh()->suppression_format);
        $this->assertNull($offer->fresh()->suppression_updated_at);
        $this->patchJson(route('console.offers.restore', [$this->workspace->getKey(), $offer->getKey()]))
            ->assertOk()->assertExactJson(['data' => 'Offer restored']);

        $other = $this->otherWorkspace();
        $this->deleteJson(route('console.offers.destroy', [$other->getKey(), $offer->getKey()]))->assertNotFound();
    }

    public function test_offer_and_asset_pages_are_available_and_provider_import_is_not_misrepresented_as_working(): void
    {
        $sponsor = $this->createSponsor('View Network');
        $offer = $this->createOffer($sponsor);

        $this->get(route('clean.console.offers.index'))
            ->assertOk()->assertSee('Offers')
            ->assertSee('download-suppression')
            ->assertSee('offer-suppression-download-modal')
            ->assertSee('OPEN SUPPRESSION');
        $this->get(route('clean.console.offers.create'))
            ->assertOk()
            ->assertSee('ADD NEW OFFER')
            ->assertSee('offer-import-mode')
            ->assertSee('Offer Ids:')
            ->assertSee('From:')
            ->assertSee('Subjects:')
            ->assertSee('Choose a HitPath, NewHitPath, Cake or EverFlow sponsor.')
            ->assertSee('HitPath imports selected campaign IDs')
            ->assertSee('NewHitPath imports selected campaign metadata and email assets')
            ->assertSee('Cake imports selected campaign metadata')
            ->assertSee('EverFlow imports selected offer IDs')
            ->assertSee('After saving this offer, upload its suppression file from')
            ->assertSee('/drops/suppression', false);
        $this->get(route('clean.console.offers.edit', $offer->getKey()))
            ->assertOk()->assertSee('Drops → Suppression')
            ->assertSee('MANAGE SUPPRESSION FILE')
            ->assertDontSee('UPLOAD SUPPRESSION FILE')
            ->assertSee('/drops/suppression?sponsor_id=', false);
        $this->get(route('clean.console.offers.assets', $offer->getKey()))
            ->assertOk()->assertSee('Offer Assets')->assertSee('CREATIVES')->assertSee('UNSUBSCRIBE')->assertSee('HTML')
            ->assertSee('provider import flow remains unavailable');
        $this->get(route('clean.console.offers.users', $offer->getKey()))->assertOk()->assertSee('Allowed Mailers');
    }

    private function offerPayload(WorkspaceSponsor $sponsor): array
    {
        return [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'external-42',
            'name' => 'Insurance quote',
            'type' => 'CPA',
            'vertical' => ' Insurance ',
            'payout' => 12.5,
            'geo_target' => ['us', 'ca', 'US'],
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'note' => 'manual source-aligned offer',
            'redirect' => 'https://offers.example.test/track?id=42',
            'unsubscribe' => 'https://offers.example.test/unsubscribe',
            'from' => [' hello@example.test ', 'offers@example.test', 'hello@example.test'],
            'subjects' => ['A clear offer'],
        ];
    }

    private function createSponsor(string $name, array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => $name,
            'affiliate_id' => null,
            'url' => 'https://'.Str::slug($name).'.example.test',
            'username' => 'test-user-'.Str::lower(Str::random(6)),
            'password' => 'test-password-'.Str::random(8),
            'api_key' => 'test-api-key-'.Str::random(16),
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function createOffer(WorkspaceSponsor $sponsor, array $overrides = []): WorkspaceOffer
    {
        return $this->workspace->offers()->create(array_merge([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'test-'.Str::lower(Str::random(10)),
            'name' => 'Test offer',
            'type' => 'cpa',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 10,
            'redirect' => 'https://offers.example.test/redirect',
            'unsubscribe' => null,
            'note' => null,
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'suppression_path' => null,
            'suppression_updated_at' => null,
            'allowed_users' => [],
            'is_active' => false,
        ], $overrides));
    }

    public function test_suppression_upload_normalizes_dms_plaintext_and_hash_files_and_requires_actual_bytes(): void
    {
        $sponsor = $this->createSponsor('Suppression Upload');
        $offer = $this->createOffer($sponsor);
        $url = route('console.drops.suppression.offer-file', $this->workspace->getKey());

        $payload = ['sponsor_id' => $sponsor->getKey(), 'offer_id' => $offer->getKey()];
        $this->post($url, $payload + ['suppression_file' => UploadedFile::fake()->createWithContent('suppression.csv', "B@example.test,a@example.test\na@example.test\n")], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.format', 'email')
            ->assertJsonPath('data.entry_count', 2)
            ->assertJsonPath('data.can_run_suppression', false);

        $offer->refresh();
        $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer));
        $this->assertSame("a@example.test\nb@example.test\n", Storage::disk('local')->get($offer->suppression_path));
        $this->assertSame([$offer->suppression_path], Storage::disk('local')->allFiles(dirname($offer->suppression_path)));
        $this->assertStringNotContainsString('suppression_path', json_encode($this->getJson(route('console.offers.index', $this->workspace->getKey()))->json()));

        $this->post($url, $payload + ['suppression_file' => UploadedFile::fake()->createWithContent('hashes.txt', md5('a@example.test')."\n")], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.format', 'md5')->assertJsonPath('data.entry_count', 1);
        $this->assertSame('md5', $offer->fresh()->suppression_format);
    }

    public function test_suppression_upload_accepts_files_larger_than_the_former_ten_megabyte_ceiling(): void
    {
        $sponsor = $this->createSponsor('Large Suppression Upload');
        $offer = $this->createOffer($sponsor);
        $content = "blocked@example.test\n".str_repeat("\n", 10 * 1024 * 1024);

        $this->post(route('console.drops.suppression.offer-file', $this->workspace->getKey()), [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('large-suppression.txt', $content),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.entry_count', 1)
            ->assertJsonPath('data.format', 'email');

        $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($offer->fresh()->suppression_path));
    }

    public function test_suppression_upload_deduplicates_across_database_batches_and_input_chunks(): void
    {
        $sponsor = $this->createSponsor('Chunked Suppression Upload');
        $offer = $this->createOffer($sponsor);
        $uniqueEntries = array_map(
            static fn (int $index): string => sprintf('recipient%04d@example.test', $index),
            range(0, 1000),
        );
        $content = str_repeat("repeat@example.test\n", 3276)
            .implode("\n", $uniqueEntries)
            ."\nrepeat@example.test\nrecipient0000@example.test\n";
        $expectedEntries = array_merge(['repeat@example.test'], $uniqueEntries);
        sort($expectedEntries, SORT_STRING);
        $expectedContent = implode("\n", $expectedEntries)."\n";

        $this->post(route('console.drops.suppression.offer-file', $this->workspace->getKey()), [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('chunked-suppression.txt', $content),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.entry_count', 1002)
            ->assertJsonPath('data.format', 'email');

        $offer->refresh();
        $this->assertSame($expectedContent, Storage::disk('local')->get($offer->suppression_path));
        $this->assertSame(hash('sha256', $expectedContent), $offer->suppression_sha256);
        $this->assertSame([$offer->suppression_path], Storage::disk('local')->allFiles(dirname($offer->suppression_path)));
    }

    public function test_suppression_normalization_cleans_staging_file_when_digest_publish_fails(): void
    {
        $sponsor = $this->createSponsor('Failed Suppression Publish');
        $offer = $this->createOffer($sponsor);
        $content = "blocked@example.test\n";
        $digestPath = sprintf(
            'offers/%s/%d/suppression/%s.txt',
            $this->workspace->getKey(),
            $offer->getKey(),
            hash('sha256', $content),
        );
        Storage::disk('local')->makeDirectory($digestPath);

        try {
            app(DmsOfferSuppressionFile::class)->store(
                $this->workspace,
                $offer,
                UploadedFile::fake()->createWithContent('suppression.txt', $content),
            );
            $this->fail('A directory at the digest destination must prevent artifact publication.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unable to store the validated suppression file.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles(dirname($digestPath)));
        $this->assertNull($offer->fresh()->suppression_path);
    }

    public function test_suppression_upload_rejects_mixed_or_malformed_entries_without_replacing_current_file(): void
    {
        $sponsor = $this->createSponsor('Suppression Validation');
        $offer = $this->createOffer($sponsor);
        $this->attachSuppressionFile($offer, "kept@example.test\n");
        $oldPath = $offer->fresh()->suppression_path;
        $url = route('console.drops.suppression.offer-file', $this->workspace->getKey());

        $this->post($url, [
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_file' => UploadedFile::fake()->createWithContent('mixed.csv', "valid@example.test\nnot-an-email\n"),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('suppression_file');

        $this->assertSame($oldPath, $offer->fresh()->suppression_path);
        $this->assertSame("kept@example.test\n", Storage::disk('local')->get($oldPath));
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::query()->create([
            'name' => 'Other workspace',
            'slug' => 'other-offer-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
    }

    private function attachSuppressionFile(WorkspaceOffer $offer, string $content): void
    {
        $path = 'offers/'.$this->workspace->getKey().'/'.$offer->getKey().'/suppression/'.hash('sha256', $content).'.txt';
        Storage::disk('local')->put($path, $content);
        $offer->forceFill([
            'suppression_path' => $path,
            'suppression_sha256' => hash('sha256', $content),
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ])->save();
    }
}
