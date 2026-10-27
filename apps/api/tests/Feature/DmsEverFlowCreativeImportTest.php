<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DmsEverFlowCreativeImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'DMS EverFlow creative import',
            'slug' => 'everflow-creative-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
    }

    public function test_selected_everflow_offer_imports_dms_html_links_and_hosted_archive_images(): void
    {
        $sponsor = $this->createSponsor();
        $html = '<!DOCTYPE html><html><body><img src="images/logo.png"><a href="https://merchant.example/click?id=42">Shop</a><a href="https://merchant.example/unsubscribe">Unsubscribe</a></body></html>';
        $bundle = $this->zip([
            'mail/index.html' => $html,
            'mail/copy.html' => $html,
            'mail/images/logo.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8WQAAAAASUVORK5CYII='),
            'mail/readme.md' => 'ignored by DMS and AlphaLink',
        ]);

        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer('https://8.8.8.8/creative.zip'), 200),
            'https://8.8.8.8/creative.zip' => Http::response($bundle, 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->sole();
        $creative = $offer->assets()->where('type', 'creative')->sole();
        $htmlAsset = $offer->assets()->where('type', 'html')->sole();
        $expectedImageUrl = route('public.offer-assets.file', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $creative->getKey(),
        ], true);

        $this->assertSame('offers/'.$this->workspace->getKey().'/'.$offer->getKey().'/assets/creative/'.basename($creative->value), $creative->value);
        Storage::disk('local')->assertExists($creative->value);
        $this->assertStringContainsString($expectedImageUrl, $htmlAsset->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]r[token]', $htmlAsset->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]u[token]', $htmlAsset->value);
        $this->assertStringNotContainsString('images/logo.png', $htmlAsset->value);

        $this->get($expectedImageUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8WQAAAAASUVORK5CYII='),
            Storage::disk('local')->get($creative->value),
        );
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://8.8.8.8/creative.zip');
    }

    public function test_bundle_download_rejects_private_http_url_without_blocking_offer_metadata_import(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer('http://127.0.0.1/internal.zip'), 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->sole();
        $this->assertSame(0, $offer->assets()->where('type', 'html')->count());
        $this->assertSame(2, $offer->assets()->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_missing_bundle_image_is_fetched_only_from_safe_public_https_and_rewritten_to_asset_route(): void
    {
        $sponsor = $this->createSponsor();
        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8WQAAAAASUVORK5CYII=');
        $bundle = $this->zip([
            'mail/index.html' => '<html><body><img src="https://8.8.8.8/cdn/logo.png"></body></html>',
        ]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer('https://8.8.8.8/creative.zip'), 200),
            'https://8.8.8.8/creative.zip' => Http::response($bundle, 200),
            'https://8.8.8.8/cdn/logo.png' => Http::response($imageBytes, 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk();

        $offer = $this->workspace->offers()->sole();
        $creative = $offer->assets()->where('type', 'creative')->sole();
        $html = $offer->assets()->where('type', 'html')->sole();
        $this->assertSame('png', pathinfo($creative->value, PATHINFO_EXTENSION));
        $this->assertStringContainsString(route('public.offer-assets.file', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $creative->getKey(),
        ], true), $html->value);
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://8.8.8.8/cdn/logo.png');
    }

    public function test_archive_paths_and_non_html_files_are_never_extracted_or_imported(): void
    {
        $sponsor = $this->createSponsor();
        $bundle = $this->zip([
            '../outside.html' => '<html><body>should not import</body></html>',
            'manual.pdf' => 'not html',
        ]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer('https://8.8.8.8/creative.zip'), 200),
            'https://8.8.8.8/creative.zip' => Http::response($bundle, 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk();

        $offer = $this->workspace->offers()->sole();
        $this->assertSame(0, $offer->assets()->where('type', 'html')->count());
        $this->assertSame(0, $offer->assets()->where('type', 'creative')->count());
        $this->assertFileDoesNotExist(storage_path('app/private/outside.html'));
    }

    private function createSponsor(): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create([
            'name' => 'EverFlow creative test sponsor',
            'affiliate_id' => null,
            'url' => 'https://publisher.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-everflow-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function everFlowOffer(string $creativeBundleUrl): array
    {
        return [
            'network_offer_id' => 9001,
            'name' => 'Insurance offer',
            'tracking_url' => 'https://tracking.example.test/click/9001',
            'html_description' => '<p>Terms and conditions apply</p>',
            'relationship' => [
                'ruleset' => ['countries' => [['country_code' => 'US']]],
                'email' => ['from_lines' => 'Alpha Support', 'subject_lines' => 'A clear subject'],
                'payouts' => ['entries' => [['payout_amount' => '13.75', 'payout_type' => 'CPA']]],
                'category' => ['name' => 'Auto Insurance'],
                'creative_bundle' => ['url' => $creativeBundleUrl],
            ],
        ];
    }

    /** @param array<string, string|false> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alphalink-test-zip-');
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
