<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsEverFlowOfferImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'DMS EverFlow import',
            'slug' => 'everflow-import-'.Str::lower(Str::random(8)),
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

    public function test_everflow_import_maps_dms_offer_fields_and_sender_assets(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor(['allowed_users' => [(int) $operator->getKey()]]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer(), 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('9001', $offer->offer_id);
        $this->assertSame('Paused Insurance Monday-Friday', $offer->name);
        $this->assertSame('auto insurance', $offer->vertical);
        $this->assertSame(['US', 'GB'], $offer->geo_target);
        $this->assertSame(13.75, (float) $offer->payout);
        $this->assertSame('CPA', $offer->type);
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

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.eflow.team/v1/affiliates/offers/9001'
            && $request->hasHeader('x-eflow-api-key', 'synthetic-everflow-key'));
    }

    public function test_import_skips_non_numeric_ids_and_does_not_restore_soft_deleted_duplicates(): void
    {
        $sponsor = $this->createSponsor();
        $existing = $this->workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => '9001',
            'name' => 'Previously removed offer',
            'type' => 'CPA',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://tracking.example.test/old',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'allowed_users' => [],
            'is_active' => false,
        ]);
        $existing->delete();
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response($this->everFlowOffer(), 200),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['bad-id', '9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 0 offers']);

        $this->assertSoftDeleted('workspace_offers', ['id' => $existing->getKey()]);
        $this->assertSame(1, $this->workspace->offers()->withTrashed()->count());
        Http::assertSentCount(1);
    }

    public function test_provider_failure_skips_offer_and_returns_dms_import_count(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake([
            'https://api.eflow.team/v1/affiliates/offers/9001' => Http::response(['error' => 'not available'], 404),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 0 offers']);

        $this->assertDatabaseCount('workspace_offers', 0);
    }

    public function test_other_dms_networks_are_not_misrepresented_as_importable(): void
    {
        $sponsor = $this->createSponsor(['type' => 'hasoffers']);
        Http::fake();

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertUnprocessable()->assertJsonValidationErrors('sponsor');

        Http::assertNothingSent();
        $this->assertDatabaseCount('workspace_offers', 0);
    }

    public function test_provider_import_requires_active_workspace_sponsor_and_ids(): void
    {
        Http::fake();
        $inactiveSponsor = $this->createSponsor(['is_active' => false]);
        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $inactiveSponsor->getKey(),
            'ids' => ['9001'],
        ])->assertNotFound();

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => 'invalid',
            'ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sponsor', 'ids']);

        $missingUrlSponsor = $this->createSponsor(['url' => '']);
        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $missingUrlSponsor->getKey(),
            'ids' => ['9001'],
        ])->assertUnprocessable()->assertJsonValidationErrors('sponsor');

        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function createSponsor(array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => 'EverFlow synthetic sponsor',
            'affiliate_id' => null,
            'url' => 'https://publisher.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-everflow-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function everFlowOffer(): array
    {
        return [
            'network_offer_id' => 9001,
            'name' => 'Paused Insurance Monday-Friday',
            'tracking_url' => 'https://tracking.example.test/click/9001',
            'html_description' => '<p>Terms <strong>and</strong> conditions apply</p>',
            'relationship' => [
                'ruleset' => ['countries' => [['country_code' => 'US'], ['country_code' => 'uk']]],
                'email' => [
                    'from_lines' => "  Alpha   Support  \nAlpha Support",
                    'subject_lines' => "A clear\t subject",
                ],
                'payouts' => ['entries' => [['payout_amount' => '13.75', 'payout_type' => 'CPA']]],
                'category' => ['name' => ' Auto Insurance '],
                'email_optout' => ['unsub_link' => 'https://unsubscribe.example.test/optout'],
            ],
        ];
    }
}
