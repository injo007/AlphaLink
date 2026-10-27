<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DmsCakeOfferImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Cake import fixture',
            'slug' => 'cake-import-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
    }

    public function test_selected_cake_campaign_maps_dms_fields_and_imports_sender_assets(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor(['allowed_users' => [(int) $operator->getKey()]]);
        Http::fake([
            'https://cake.example.test/affiliates/api/Offers/Campaign*' => Http::response([
                'data' => [$this->cakeOffer()],
            ]),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('9001', $offer->offer_id);
        $this->assertSame('Paused Auto Insurance Mon-Fri', $offer->name);
        $this->assertSame('auto insurance', $offer->vertical);
        $this->assertSame(['US', 'GB'], $offer->geo_target);
        $this->assertSame(23.5, (float) $offer->payout);
        $this->assertSame('CPL', $offer->type);
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $offer->allowed_days);
        $this->assertSame('https://tracking.example.test/click/9001', $offer->redirect);
        $this->assertSame('https://unsubscribe.example.test/optout', $offer->unsubscribe);
        $this->assertSame('Terms and conditions apply', $offer->note);
        $this->assertSame([(int) $operator->getKey()], $offer->allowed_users);
        $this->assertSame('paused', $offer->status);
        $this->assertFalse($offer->is_active);
        $this->assertSame(['from', 'subject'], $offer->assets->pluck('type')->all());
        $this->assertSame('Alpha Support', $offer->assets->firstWhere('type', 'from')->value);
        $this->assertSame('A clear subject', $offer->assets->firstWhere('type', 'subject')->value);

        Http::assertSent(static function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/affiliates/api/Offers/Campaign'
                && $query === [
                    'api_key' => 'synthetic-cake-key',
                    'affiliate_id' => 'affiliate-42',
                    'campaign_id' => '9001',
                ];
        });
    }

    public function test_cake_uses_dms_us_default_and_skips_invalid_or_failed_selected_campaigns(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake([
            'https://cake.example.test/affiliates/api/Offers/Campaign*' => Http::sequence()
                ->push(['data' => [$this->cakeOffer(['allowed_countries' => []])]])
                ->push(['data' => []], 200)
                ->push(['message' => 'unavailable'], 404),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['not-numeric', '9001', '9002', '9003'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $this->assertSame(['US'], $this->workspace->offers()->sole()->geo_target);
        Http::assertSentCount(3);
    }

    public function test_cake_import_requires_affiliate_id_before_requesting_provider(): void
    {
        $sponsor = $this->createSponsor(['affiliate_id' => null]);
        Http::fake();

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertUnprocessable()->assertJsonValidationErrors('sponsor');

        Http::assertNothingSent();
        $this->assertDatabaseCount('workspace_offers', 0);
    }

    public function test_cake_import_uses_dms_creative_bundle_links_and_safe_workspace_image_assets(): void
    {
        $sponsor = $this->createSponsor();
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8WQAAAAASUVORK5CYII=');
        $bundle = $this->zip([
            'mail/index.html' => '<html><body><img src="images/logo.png"><a href="https://merchant.example/click">Shop</a><a href="https://merchant.example/unsubscribe">Unsubscribe</a></body></html>',
            'mail/images/logo.png' => $image,
        ]);
        Http::fake([
            'https://cake.example.test/affiliates/api/Offers/Campaign*' => Http::response([
                'data' => [$this->cakeOffer(['creatives_download_url' => 'https://8.8.8.8/creative.zip'])],
            ]),
            'https://8.8.8.8/creative.zip' => Http::response($bundle),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->sole();
        $creative = $offer->assets()->where('type', 'creative')->sole();
        $html = $offer->assets()->where('type', 'html')->sole();
        $imageUrl = route('public.offer-assets.file', [$this->workspace->getKey(), $offer->getKey(), $creative->getKey()], true);
        Storage::disk('local')->assertExists($creative->value);
        $this->assertStringContainsString($imageUrl, $html->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]r[token]', $html->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]u[token]', $html->value);
        $this->assertStringNotContainsString('merchant.example', $html->value);
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $overrides */
    private function createSponsor(array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => 'Cake synthetic sponsor',
            'affiliate_id' => 'affiliate-42',
            'url' => 'https://cake.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-cake-key',
            'type' => 'cake',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function cakeOffer(array $overrides = []): array
    {
        return array_merge([
            'campaign_id' => 9001,
            'offer_id' => 701,
            'offer_name' => 'Paused Auto Insurance Mon-Fri',
            'vertical_name' => ' Auto Insurance ',
            'allowed_countries' => [['country_code' => 'US'], ['country_code' => 'uk']],
            'price' => '23.50',
            'price_format' => 'CPL - fixed',
            'from_lines' => ['  Alpha   Support  ', 'Alpha Support', ''],
            'subject_lines' => ["A clear\t subject"],
            'creatives' => [
                ['unique_link' => 'https://tracking.example.test/click/9001'],
                ['unique_link' => 'https://tracking.example.test/secondary'],
            ],
            'unsubscribe_link' => 'https://unsubscribe.example.test/optout',
            'restrictions' => 'Terms and conditions apply',
            'creatives_download_url' => '',
        ], $overrides);
    }

    /** @param array<string, string|false> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alphalink-cake-test-zip-');
        $this->assertNotFalse($path);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($files as $name => $contents) {
            $this->assertNotFalse($contents);
            $archive->addFromString($name, $contents);
        }

        $archive->close();
        $contents = file_get_contents($path);
        unlink($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
