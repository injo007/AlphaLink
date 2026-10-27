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

class DmsNewHitPathOfferImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'NewHitPath import fixture',
            'slug' => 'newhitpath-import-'.Str::lower(Str::random(8)),
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

    public function test_selected_newhitpath_offer_maps_dms_fields_and_email_assets(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor(['allowed_users' => [(int) $operator->getKey()]]);
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([
                'data' => $this->newHitPathOffer(),
            ]),
            'https://affiliate.newhitpath.example.test/api/campaigns/9001/emails' => Http::sequence()
                ->push(['data' => [[
                    'from_lines' => ['  Alpha   Support ', 'Alpha Support'],
                    'subjects' => ["A clear\t subject"],
                ]]])
                ->push(['data' => [[
                    'body' => '<html><body><a href="https://merchant.example/click?id=1">Shop</a><a href="https://merchant.example/unsubscribe">Unsubscribe</a></body></html>',
                    'images' => [],
                ]]]),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001', 'not-numeric'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('9001', $offer->offer_id);
        $this->assertSame('Auto - Medicare US', $offer->name);
        $this->assertSame('auto', $offer->vertical);
        $this->assertSame(['US', 'GB'], $offer->geo_target);
        $this->assertSame(19.95, (float) $offer->payout);
        $this->assertSame('CPL', $offer->type);
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $offer->allowed_days);
        $this->assertSame('https://tracking.example.test/path/', $offer->redirect);
        $this->assertSame('https://tracking.example.test/optout', $offer->unsubscribe);
        $this->assertSame('CPL promotion, no weekend delivery', $offer->note);
        $this->assertSame([(int) $operator->getKey()], $offer->allowed_users);
        $this->assertFalse($offer->is_active);
        $this->assertSame(['from', 'from', 'subject', 'html'], $offer->assets->pluck('type')->all());
        $this->assertSame('Alpha Support', $offer->assets->firstWhere('type', 'from')->value);
        $this->assertSame('A clear subject', $offer->assets->firstWhere('type', 'subject')->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]r[token]', $offer->assets->firstWhere('type', 'html')->value);
        $this->assertStringContainsString('http://[domain]/[rndUN_10]u[token]', $offer->assets->firstWhere('type', 'html')->value);
        $this->assertStringNotContainsString('merchant.example', $offer->assets->firstWhere('type', 'html')->value);

        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://affiliate.newhitpath.example.test/api/campaigns/9001'
            && $request->hasHeader('Authorization', 'Bearer synthetic-newhitpath-key'));
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/campaigns/9001/emails')
            && $request->hasHeader('Authorization', 'Bearer synthetic-newhitpath-key'));
    }

    public function test_newhitpath_creative_images_are_bounded_and_api_token_is_not_forwarded_to_public_cdn(): void
    {
        $sponsor = $this->createSponsor();
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/8WQAAAAASUVORK5CYII=');
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([
                'data' => $this->newHitPathOffer(['email_count' => 0]),
            ]),
            'https://affiliate.newhitpath.example.test/api/campaigns/9001/emails' => Http::response(['data' => [[
                'body' => '<html><body><img src="images/logo.png"></body></html>',
                'images' => [['name' => 'images/logo.png', 'url' => 'https://cdn.example.test/logo.png']],
            ]]]),
            'https://cdn.example.test/logo.png' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->sole();
        $imageAsset = $offer->assets()->where('type', 'creative')->sole();
        $htmlAsset = $offer->assets()->where('type', 'html')->sole();
        $publicImageUrl = route('public.offer-assets.file', [
            $this->workspace->getKey(),
            $offer->getKey(),
            $imageAsset->getKey(),
        ], true);
        Storage::disk('local')->assertExists($imageAsset->value);
        $this->assertStringContainsString($publicImageUrl, $htmlAsset->value);
        $this->assertStringNotContainsString('images/logo.png', $htmlAsset->value);
        Http::assertSentCount(3);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://cdn.example.test/logo.png'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_newhitpath_rate_limit_and_missing_redirect_skip_offer_without_writes(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response(['message' => 'private response'], 429),
            'https://affiliate.newhitpath.example.test/api/campaigns/9002' => Http::response([
                'data' => $this->newHitPathOffer(['redirect' => []]),
            ]),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001', '9002'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 0 offers'])
            ->assertJsonMissing(['message' => 'private response']);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('workspace_offers', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function createSponsor(array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => 'NewHitPath synthetic sponsor',
            'affiliate_id' => null,
            'url' => 'https://affiliate.newhitpath.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-newhitpath-key',
            'type' => 'newhitpath',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function newHitPathOffer(array $overrides = []): array
    {
        return array_merge([
            'id' => 9001,
            'name' => 'Auto - Medicare US',
            'description' => 'CPL promotion, no weekend delivery',
            'payout' => '19.95',
            'redirect' => [['url' => 'https://tracking.example.test/path']],
            'optout' => 'https://tracking.example.test/optout',
            'geo' => ['from' => 'GB'],
            'email_count' => 1,
        ], $overrides);
    }
}
