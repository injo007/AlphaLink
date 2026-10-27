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
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsHasOffersOfferImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'HasOffers import',
            'slug' => 'hasoffers-import-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
    }

    public function test_selected_offer_import_maps_dms_hasoffers_metadata_and_skips_nonnumeric_ids(): void
    {
        $sponsor = $this->createSponsor();
        $seen = [];
        Http::fake(function (ClientRequest $request) use (&$seen) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seen[] = ['url' => $request->url(), 'query' => $query];

            if (str_starts_with($request->url(), 'https://777.api.hasoffers.com/')) {
                return match ($query['Method'] ?? '') {
                    'findById' => Http::response(['response' => ['status' => 1, 'data' => ['Offer' => [
                        'id' => 9001,
                        'name' => 'Insurance CPA',
                        'description' => 'Monday-Friday only. Do not send on weekends.',
                        'default_payout' => '13.75',
                        'email_instructions_from' => "  Alpha   Support  \n\nAlpha Support\nBeta Support",
                        'email_instructions_subject' => " A clear subject \nA second subject",
                        'dne_unsubscribe_url' => 'https://tracking.example.test/opt-out/9001',
                    ]]]], 200),
                    'getCategories' => Http::response(['response' => ['status' => 1, 'data' => [['categories' => [['name' => 'Insurance']]]]]], 200),
                    'getGeoTargeting' => Http::response(['response' => ['status' => 1, 'data' => ['Countries' => [['code' => 'US'], ['code' => 'UK']]]]], 200),
                    'generateTrackingLink' => Http::response(['response' => ['status' => 1, 'data' => ['click_url' => 'https://tracking.example.test/click/9001']]], 200),
                    default => Http::response([], 404),
                };
            }

            return Http::response([], 404);
        });

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001', 'not-a-number'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('9001', $offer->offer_id);
        $this->assertSame('Insurance CPA', $offer->name);
        $this->assertSame('insurance', $offer->vertical);
        $this->assertSame(['US', 'GB'], $offer->geo_target);
        $this->assertSame(13.75, (float) $offer->payout);
        $this->assertSame('CPA', $offer->type);
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $offer->allowed_days);
        $this->assertSame('https://tracking.example.test/click/9001', $offer->redirect);
        $this->assertSame('https://tracking.example.test/opt-out/9001', $offer->unsubscribe);
        $this->assertSame('Monday-Friday only. Do not send on weekends.', $offer->note);
        $this->assertFalse($offer->is_active);
        $this->assertSame(['from', 'from', 'from', 'subject', 'subject'], $offer->assets->pluck('type')->all());
        $this->assertSame('Alpha Support', $offer->assets->firstWhere('type', 'from')->value);
        $this->assertSame('A clear subject', $offer->assets->firstWhere('type', 'subject')->value);

        $apiRequests = array_values(array_filter($seen, fn (array $request): bool => str_starts_with($request['url'], 'https://777.api.hasoffers.com/')));
        $this->assertCount(4, $apiRequests);
        foreach ($apiRequests as $request) {
            $this->assertSame('synthetic-hasoffers-key', $request['query']['api_key']);
            $this->assertSame('Affiliate_Offer', $request['query']['Target']);
        }
        $this->assertSame('9001', $apiRequests[0]['query']['id']);
        $this->assertSame(['9001'], $apiRequests[1]['query']['ids']);
        $this->assertSame('9001', $apiRequests[2]['query']['id']);
        $this->assertSame('9001', $apiRequests[3]['query']['offer_id']);
    }

    public function test_geo_defaults_to_all_and_crm_supplies_missing_unsubscribe_link(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_starts_with($url, 'https://777.api.hasoffers.com/')) {
                return match ($query['Method'] ?? '') {
                    'findById' => Http::response(['response' => ['status' => 1, 'data' => ['Offer' => [
                        'id' => 9002,
                        'name' => 'Simple Offer',
                        'description' => 'No restrictions supplied',
                        'default_payout' => 4,
                        'email_instructions_from' => '',
                        'email_instructions_subject' => '',
                        'dne_unsubscribe_url' => '',
                    ]]]], 200),
                    'getCategories' => Http::response(['response' => ['status' => 0, 'data' => []]], 200),
                    'getGeoTargeting' => Http::response(['response' => ['status' => 0, 'data' => []]], 200),
                    'generateTrackingLink' => Http::response(['response' => ['status' => 1, 'data' => ['click_url' => 'https://tracking.example.test/click/9002']]], 200),
                    default => Http::response([], 404),
                };
            }

            if (str_starts_with($url, 'https://crm3-java.example.test/api/v1/proxy/offers/9002')) {
                return Http::response(['optOutLink' => 'https://crm.example.test/unsubscribe/9002'], 200);
            }

            return Http::response([], 404);
        });

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9002'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->sole();
        $this->assertSame(['ALL'], $offer->geo_target);
        $this->assertSame('na', $offer->vertical);
        $this->assertSame('https://crm.example.test/unsubscribe/9002', $offer->unsubscribe);
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://crm3-java.example.test/api/v1/proxy/offers/9002')
            && str_contains($request->url(), 'apiKey=synthetic-hasoffers-key'));
    }

    public function test_hasoffers_affiliate_id_is_required_before_any_provider_request(): void
    {
        $sponsor = $this->createSponsor(['affiliate_id' => '']);
        Http::fake();

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertUnprocessable()->assertJsonValidationErrors('sponsor');

        Http::assertNothingSent();
        $this->assertDatabaseCount('workspace_offers', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function createSponsor(array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => 'HasOffers synthetic sponsor',
            'affiliate_id' => '777',
            'url' => 'https://affiliate.hasoffers.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-hasoffers-key',
            'type' => 'hasoffers',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }
}
