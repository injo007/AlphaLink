<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use App\Services\Campaigns\DmsOfferSuppressionFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsAffiliateSuppressionRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
    }

    public function test_hitpath_download_refreshes_and_normalizes_the_offer_suppression_file(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Suppression refresh',
            'slug' => 'suppression-refresh-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = $workspace->sponsors()->create([
            'name' => 'Synthetic HitPath',
            'affiliate_id' => null,
            'url' => 'https://affiliate.hitpath.example.test',
            'username' => 'fixture',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'hitpath',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = $workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => '9913',
            'name' => 'Synthetic offer',
            'type' => 'CPA',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://tracking.example.test/click',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::response(
                '<response><data><suppurl>https://cdn.example.test/suppression.txt</suppurl></data></response>',
                200,
            ),
            'https://cdn.example.test/suppression.txt' => Http::response("BLOCKED@example.test\nblocked@example.test\n", 200),
        ]);

        $refreshed = app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer);
        $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->implode(', ');
        $responses = Http::recorded()->map(fn (array $pair): string => $pair[1]->status().':'.strlen($pair[1]->body()))->implode(', ');
        $this->assertTrue($refreshed, 'Recorded synthetic requests: '.$requests.'; responses '.$responses);
        $offer->refresh();

        $this->assertSame('email', $offer->suppression_format);
        $this->assertSame(now()->toDateString(), $offer->suppression_updated_at->toDateString());
        $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($offer->suppression_path));
        $this->assertSame(hash('sha256', "blocked@example.test\n"), $offer->suppression_sha256);
        $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer));

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.hitpath.example.test/pubapi.php'
            && $request->method() === 'POST'
            && $request->data()['apikey'] === 'fixture-api-key'
            && $request->data()['apifunc'] === 'getsuppression'
            && $request->data()['campaignid'] === 9913);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://cdn.example.test/suppression.txt');
    }

    public function test_streamed_sponsor_files_over_the_manual_upload_limit_are_normalized_without_retaining_duplicate_rows(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('everflow', 'https://partner.everflow.example.test', null, '9925');
        $path = tempnam(sys_get_temp_dir(), 'alphalink-large-suppression-test-');
        $stream = fopen($path, 'wb');
        $line = "blocked@example.test\n";
        $targetBytes = 10_500_000;

        try {
            $batch = str_repeat($line, 10_000);
            $written = 0;
            while ($written < $targetBytes) {
                $chunk = $written + strlen($batch) > $targetBytes
                    ? substr($batch, 0, $targetBytes - $written)
                    : $batch;
                $written += fwrite($stream, $chunk);
            }
            fclose($stream);
            $stream = null;

            $stored = app(DmsOfferSuppressionFile::class)->storeDownloadedFile($workspace, $offer, $path);
            $offer->forceFill([
                'suppression_path' => $stored['path'],
                'suppression_sha256' => $stored['sha256'],
                'suppression_format' => $stored['format'],
                'suppression_updated_at' => now()->toDateString(),
            ])->save();

            $this->assertGreaterThan(10_000_000, filesize($path));
            $this->assertSame(1, $stored['count']);
            $this->assertSame("blocked@example.test\n", Storage::disk('local')->get($stored['path']));
            $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer->fresh()));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($path);
        }
    }

    public function test_streamed_sponsor_archive_extracts_and_stores_its_largest_valid_list_entry(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('everflow', 'https://partner.everflow.example.test', null, '9926');
        $path = tempnam(sys_get_temp_dir(), 'alphalink-suppression-archive-test-');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $archive->addFromString('small.txt', "blocked-archive@example.test\n");
        $archive->addFromString('nested/list.csv', "BLOCKED-ARCHIVE@example.test\nsecond@example.test\n");
        $archive->close();

        try {
            $stored = app(DmsOfferSuppressionFile::class)->storeDownloadedFile($workspace, $offer, $path);

            $this->assertSame('email', $stored['format']);
            $this->assertSame(2, $stored['count']);
            $this->assertSame("blocked-archive@example.test\nsecond@example.test\n", Storage::disk('local')->get($stored['path']));
        } finally {
            @unlink($path);
        }
    }

    public function test_new_hitpath_refresh_uses_the_dms_campaign_suppression_endpoint_and_bearer_token(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('newhitpath', 'https://affiliate.newhitpath.example.test', null, '9915');
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9915/suppression' => Http::response([
                'data' => ['download_url' => 'https://files.example.test/new-hitpath.txt'],
            ]),
            'https://files.example.test/new-hitpath.txt' => Http::response("blocked-new@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-new@example.test\n");
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://affiliate.newhitpath.example.test/api/campaigns/9915/suppression'
            && $request->method() === 'GET'
            && ($request->headers()['Authorization'][0] ?? null) === 'Bearer fixture-api-key');
    }

    public function test_cake_refresh_uses_the_offer_record_suppression_identifier(): void
    {
        [$workspace, $sponsor, $offer] = $this->suppressionOffer('cake', 'https://network.cake.example.test', 'affiliate-731', '9916');
        Http::fake([
            'https://network.cake.example.test/affiliates/api/Offers/Campaign*' => Http::response([
                'data' => [[
                    'campaign_id' => 9916,
                    'offer_id' => 6319,
                    'offer_name' => 'Synthetic Cake campaign',
                ]],
            ]),
            'https://network.cake.example.test/affiliates/api/Offers/SuppressionList*' => Http::response([
                'download_url' => 'https://files.example.test/cake-suppression.txt',
            ]),
            'https://files.example.test/cake-suppression.txt' => Http::response("blocked-cake@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-cake@example.test\n");
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/Offers/Campaign')
            && $request->method() === 'GET'
            && $this->queryValue($request, 'campaign_id') === '9916'
            && $this->queryValue($request, 'affiliate_id') === 'affiliate-731'
            && $this->queryValue($request, 'api_key') === $sponsor->api_key);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/Offers/SuppressionList')
            && $this->queryValue($request, 'offer_id') === '6319');
    }

    public function test_everflow_refresh_reads_the_email_optout_suppression_file_link(): void
    {
        [$workspace, $sponsor, $offer] = $this->suppressionOffer('everflow', 'https://partner.everflow.example.test', null, '9917');
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9917' => Http::response([
                'relationship' => [
                    'email_optout' => ['suppression_file_link' => 'https://files.example.test/everflow-suppression.txt'],
                ],
            ]),
            'https://files.example.test/everflow-suppression.txt' => Http::response("blocked-everflow@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-everflow@example.test\n");
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.eflow.team/v1/affiliates/offers/9917'
            && ($request->headers()['x-eflow-api-key'][0] ?? null) === $sponsor->api_key);
    }

    public function test_everflow_optizmo_refresh_uses_the_encrypted_workspace_app_setting(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('everflow', 'https://partner.everflow.example.test', null, '9926');
        $workspace->appSettings()->create([
            'settings' => ['optizmo_token' => 'workspace-optizmo-token'],
        ]);
        config(['services.optizmo.token' => 'environment-fallback-token']);

        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9926' => Http::response([
                'relationship' => [
                    'integrations' => [
                        'optizmo' => ['mailer_access_key' => 'https://app.optizmo.net/accesskey?mak=offer-mailer-key'],
                    ],
                ],
            ]),
            'https://mailer-api.optizmo.net/accesskey/download/offer-mailer-key*' => Http::response([
                'result' => 'success',
                'download_link' => 'https://mailer-api.optizmo.net/temp/offer-mailer-key',
            ]),
            'https://mailer-api.optizmo.net/temp/offer-mailer-key' => Http::response('', 302, [
                'Location' => 'https://temp-storage.optizmo.com/suppression/offer-mailer-key?signature=synthetic',
            ]),
            'https://temp-storage.optizmo.com/suppression/offer-mailer-key?signature=synthetic' => Http::response(
                "blocked-optizmo@example.test\n",
                200,
            ),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-optizmo@example.test\n");
        Http::assertSentCount(4);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(
            $request->url(),
            'https://mailer-api.optizmo.net/accesskey/download/offer-mailer-key',
        )
            && $this->queryValue($request, 'token') === 'workspace-optizmo-token'
            && $this->queryValue($request, 'deltas') === '0');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://temp-storage.optizmo.com/suppression/offer-mailer-key?signature=synthetic');
        Http::assertNotSent(fn (ClientRequest $request): bool => $this->queryValue($request, 'token') === 'environment-fallback-token');
    }

    public function test_everflow_optizmo_refresh_keeps_environment_token_fallback_for_legacy_installs(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('everflow', 'https://partner.everflow.example.test', null, '9927');
        config(['services.optizmo.token' => 'environment-fallback-token']);

        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9927' => Http::response([
                'relationship' => [
                    'integrations' => [
                        'optizmo' => ['mailer_access_key' => 'https://app.optizmo.net/accesskey?mak=legacy-mailer-key'],
                    ],
                ],
            ]),
            'https://mailer-api.optizmo.net/accesskey/download/legacy-mailer-key*' => Http::response([
                'result' => 'success',
                'download_link' => 'https://temp-storage.optizmo.com/suppression/legacy-mailer-key',
            ]),
            'https://temp-storage.optizmo.com/suppression/legacy-mailer-key' => Http::response(
                "blocked-legacy@example.test\n",
                200,
            ),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        Http::assertSent(fn (ClientRequest $request): bool => $this->queryValue($request, 'token') === 'environment-fallback-token');
    }

    public function test_hasoffers_refresh_uses_the_network_download_url_before_crm_fallback(): void
    {
        [$workspace, $sponsor, $offer] = $this->suppressionOffer('hasoffers', 'https://offers.partner.example.test', 'network731', '9918');
        Http::fake([
            'https://network731.api.hasoffers.com/Apiv3/json*' => Http::response([
                'response' => [
                    'status' => 1,
                    'data' => ['Offer' => ['dne_download_url' => 'https://files.example.test/hasoffers-suppression.txt']],
                ],
            ]),
            'https://files.example.test/hasoffers-suppression.txt' => Http::response("blocked-hasoffers@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-hasoffers@example.test\n");
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'network731.api.hasoffers.com/Apiv3/json')
            && $request->method() === 'GET'
            && $this->queryValue($request, 'Target') === 'Affiliate_Offer'
            && $this->queryValue($request, 'Method') === 'findById'
            && $this->queryValue($request, 'id') === '9918'
            && $this->queryValue($request, 'api_key') === $sponsor->api_key);
    }

    public function test_hasoffers_refresh_uses_the_dms_crm_fallback_when_the_offer_has_no_download_url(): void
    {
        [$workspace, $sponsor, $offer] = $this->suppressionOffer('hasoffers', 'https://offers.partner.example.test', 'network731', '9919');
        Http::fake([
            'https://network731.api.hasoffers.com/Apiv3/json*' => Http::response([
                'response' => ['status' => 1, 'data' => ['Offer' => ['dne_download_url' => null]]],
            ]),
            'https://crm3-java.example.test/api/v1/proxy/offers/9919*' => Http::response([
                'suppressionFile' => 'https://files.example.test/hasoffers-crm-suppression.txt',
            ]),
            'https://files.example.test/hasoffers-crm-suppression.txt' => Http::response("blocked-crm@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));

        $this->assertSuppressionFile($offer, "blocked-crm@example.test\n");
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/api/v1/proxy/offers/9919')
            && $this->queryValue($request, 'apiKey') === $sponsor->api_key);
    }

    public function test_provider_api_failures_leave_the_offer_unmodified(): void
    {
        $providers = [
            ['hitpath', 'https://affiliate.hitpath.example.test', null, '9920', 1],
            ['newhitpath', 'https://affiliate.newhitpath.example.test', null, '9921', 1],
            ['cake', 'https://network.cake.example.test', 'affiliate-731', '9922', 1],
            ['everflow', 'https://partner.everflow.example.test', null, '9923', 1],
            ['hasoffers', 'https://offers.partner.example.test', 'network731', '9924', 2],
        ];

        foreach ($providers as [$provider, $url, $affiliateId, $offerId, $requestCount]) {
            [$workspace, , $offer] = $this->suppressionOffer($provider, $url, $affiliateId, $offerId);
            Http::fake(static fn (ClientRequest $request) => Http::response([], 503));

            $this->assertFalse(
                app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer),
                "A failed {$provider} API response must not refresh the suppression file.",
            );
            $this->assertNull($offer->fresh()->suppression_path);
            $this->assertSame($requestCount, Http::recorded()->count());
        }
    }

    public function test_suppression_download_failure_leaves_the_offer_unmodified(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('hitpath', 'https://affiliate.hitpath.example.test', null, '9925');
        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::response(
                '<response><data><suppurl>https://cdn.example.test/unavailable.txt</suppurl></data></response>',
                200,
            ),
            'https://cdn.example.test/unavailable.txt' => Http::response('', 503),
        ]);

        $this->assertFalse(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));
        $this->assertNull($offer->fresh()->suppression_path);
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_refresh_rejects_a_private_download_host_from_a_provider_response(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Suppression refresh private URL',
            'slug' => 'suppression-private-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $sponsor = $workspace->sponsors()->create([
            'name' => 'Synthetic HitPath',
            'affiliate_id' => null,
            'url' => 'https://affiliate.hitpath.example.test',
            'username' => 'fixture',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'hitpath',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = $workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => '9914',
            'name' => 'Synthetic offer',
            'type' => 'CPA',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://tracking.example.test/click',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::response(
                '<response><data><suppurl>https://127.0.0.1/private.txt</suppurl></data></response>',
                200,
            ),
        ]);

        $this->assertFalse(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));
        Http::assertSentCount(1);
        $this->assertNull($offer->fresh()->suppression_path);
    }

    public function test_refresh_uses_a_public_address_when_dns_also_returns_private_answers(): void
    {
        [$workspace, , $offer] = $this->suppressionOffer('hitpath', 'https://affiliate.hitpath.example.test', null, '9930');
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['fd00::1', '127.0.0.1', '1.1.1.1']),
        );
        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::response(
                '<response><data><suppurl>https://cdn.example.test/suppression.txt</suppurl></data></response>',
                200,
            ),
            'https://cdn.example.test/suppression.txt' => Http::response("mixed-dns@example.test\n", 200),
        ]);

        $this->assertTrue(app(DmsAffiliateSuppressionRefresher::class)->refresh($workspace, $offer));
        $this->assertSuppressionFile($offer, "mixed-dns@example.test\n");
    }

    /** @return array{Workspace, WorkspaceSponsor, WorkspaceOffer} */
    private function suppressionOffer(string $provider, string $url, ?string $affiliateId, string $offerId): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Suppression '.$provider,
            'slug' => 'suppression-'.$provider.'-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $sponsor = $workspace->sponsors()->create([
            'name' => 'Synthetic '.ucfirst($provider),
            'affiliate_id' => $affiliateId,
            'url' => $url,
            'username' => 'fixture',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => $provider,
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $offer = $workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offerId,
            'name' => 'Synthetic offer',
            'type' => 'CPA',
            'vertical' => 'testing',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://tracking.example.test/click',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);

        return [$workspace, $sponsor, $offer];
    }

    private function assertSuppressionFile(WorkspaceOffer $offer, string $expected): void
    {
        $offer->refresh();
        $this->assertSame('email', $offer->suppression_format);
        $this->assertSame(now()->toDateString(), $offer->suppression_updated_at->toDateString());
        $this->assertSame($expected, Storage::disk('local')->get($offer->suppression_path));
        $this->assertSame(hash('sha256', $expected), $offer->suppression_sha256);
        $this->assertTrue(app(DmsOfferSuppressionFile::class)->isEligible($offer));
    }

    private function queryValue(ClientRequest $request, string $key): ?string
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query[$key]) ? (string) $query[$key] : null;
    }
}
