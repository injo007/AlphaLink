<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsSponsorOfferRefreshTest extends TestCase
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
            'name' => 'Offer refresh workspace',
            'slug' => 'offer-refresh-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
    }

    public function test_setting_offer_live_refreshes_dms_fields_and_adds_only_new_html_creatives(): void
    {
        $sponsor = $this->createSponsor();
        $offer = $this->createOffer($sponsor, [
            'status' => 'paused',
            'name' => 'Old offer name',
            'payout' => 1.25,
            'redirect' => 'https://tracking.example.test/old',
            'unsubscribe' => 'https://tracking.example.test/old-unsubscribe',
            'note' => 'Retain local note',
        ]);
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([
                'data' => $this->providerOffer('Insurance paused CPA offer', 'https://tracking.example.test/new'),
            ]),
            'https://affiliate.newhitpath.example.test/api/campaigns/9001/emails' => Http::response([
                'data' => [['body' => '<html><body>Fresh creative</body></html>', 'images' => []]],
            ]),
        ]);

        $this->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), ['status' => 'live'])
            ->assertOk()
            ->assertExactJson(['data' => 'Changed successfully']);

        $offer->refresh();
        $this->assertSame('Insurance paused CPA offer', $offer->name);
        $this->assertSame('paused', $offer->status);
        $this->assertSame(14.5, (float) $offer->payout);
        $this->assertSame('https://tracking.example.test/new/', $offer->redirect);
        $this->assertSame('https://tracking.example.test/old-unsubscribe', $offer->unsubscribe);
        $this->assertSame('Retain local note', $offer->note);
        $this->assertSame(1, $offer->assets()->where('type', 'html')->count());

        $this->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), ['status' => 'live'])
            ->assertOk();
        $this->assertSame(1, $offer->assets()->where('type', 'html')->count(), 'The same provider HTML creative is not added again.');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'offer.provider_refresh_finished',
            'subject_id' => (string) $offer->getKey(),
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/campaigns/9001'));
    }

    public function test_provider_missing_offer_is_paused_like_dms(): void
    {
        $sponsor = $this->createSponsor();
        $offer = $this->createOffer($sponsor, ['status' => 'issue']);
        Http::fake(['https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([], 404)]);

        $this->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), ['status' => 'live'])
            ->assertOk();

        $this->assertSame('paused', $offer->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'offer.provider_refresh_finished',
            'subject_id' => (string) $offer->getKey(),
            'properties->outcome' => 'paused',
        ]);
    }

    public function test_provider_transport_failure_keeps_the_saved_offer_metadata(): void
    {
        $sponsor = $this->createSponsor();
        $offer = $this->createOffer($sponsor, [
            'status' => 'paused',
            'name' => 'Saved offer name',
            'payout' => 7.25,
            'redirect' => 'https://tracking.example.test/saved',
        ]);
        Http::fake(['*' => Http::failedConnection()]);

        $this->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), ['status' => 'live'])
            ->assertOk();

        $offer->refresh();
        $this->assertSame('live', $offer->status, 'DMS saves the requested status before attempting its provider refresh.');
        $this->assertSame('Saved offer name', $offer->name);
        $this->assertSame(7.25, (float) $offer->payout);
        $this->assertSame('https://tracking.example.test/saved', $offer->redirect);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'offer.provider_refresh_finished',
            'subject_id' => (string) $offer->getKey(),
            'properties->outcome' => 'failed',
        ]);
    }

    public function test_non_live_status_change_does_not_call_provider(): void
    {
        $offer = $this->createOffer($this->createSponsor());
        Http::fake();

        $this->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), ['status' => 'issue'])
            ->assertOk();

        $this->assertSame('issue', $offer->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_daily_command_refreshes_only_active_live_offers_from_active_sponsors(): void
    {
        $sponsor = $this->createSponsor();
        $eligible = $this->createOffer($sponsor, ['is_active' => true, 'status' => 'live']);
        $this->createOffer($sponsor, ['offer_id' => '9002', 'is_active' => true, 'status' => 'paused']);
        $inactiveSponsor = $this->createSponsor(['is_active' => false]);
        $this->createOffer($inactiveSponsor, ['offer_id' => '9003', 'is_active' => true, 'status' => 'live']);
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([
                'data' => $this->providerOffer('Refreshed name', 'https://tracking.example.test/refreshed'),
            ]),
            'https://affiliate.newhitpath.example.test/api/campaigns/9001/emails' => Http::response(['data' => []]),
        ]);

        $this->artisan('workspace:offers-refresh', ['--sleep' => 0])
            ->assertExitCode(0)
            ->expectsOutput('Offers refresh finished: 1 processed.');

        $this->assertSame('Refreshed name', $eligible->fresh()->name);
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/campaigns/9001'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/campaigns/9002')
            || str_ends_with($request->url(), '/api/campaigns/9003'));
    }

    public function test_targeted_command_refreshes_selected_offer_even_when_not_active_or_live(): void
    {
        $sponsor = $this->createSponsor();
        $offer = $this->createOffer($sponsor, [
            'is_active' => false,
            'status' => 'paused',
            'name' => 'Saved paused offer',
        ]);
        Http::fake([
            'https://affiliate.newhitpath.example.test/api/campaigns/9001' => Http::response([
                'data' => $this->providerOffer('Refreshed offer', 'https://tracking.example.test/refreshed'),
            ]),
            'https://affiliate.newhitpath.example.test/api/campaigns/9001/emails' => Http::response(['data' => []]),
        ]);

        $this->artisan('workspace:offers-refresh', ['--offer' => $offer->getKey(), '--sleep' => 0])
            ->assertExitCode(0)
            ->expectsOutput('Offers refresh finished: 1 processed.');

        $offer->refresh();
        $this->assertSame('Refreshed offer', $offer->name);
        $this->assertSame('paused', $offer->status, 'A non-pause provider name does not reactivate an already paused offer.');
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/campaigns/9001'));
    }

    /** @param array<string, mixed> $overrides */
    private function createSponsor(array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => 'NewHitPath test sponsor',
            'affiliate_id' => null,
            'url' => 'https://affiliate.newhitpath.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'type' => 'newhitpath',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createOffer(WorkspaceSponsor $sponsor, array $overrides = []): WorkspaceOffer
    {
        return $this->workspace->offers()->create(array_merge([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => '9001',
            'name' => 'Saved source offer',
            'type' => 'CPA',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1.25,
            'redirect' => 'https://tracking.example.test/saved',
            'unsubscribe' => null,
            'note' => 'Existing note',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => false,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function providerOffer(string $name, string $redirect): array
    {
        return [
            'id' => 9001,
            'name' => $name,
            'description' => 'Source provider offer',
            'payout' => 14.5,
            'redirect' => [['url' => $redirect]],
            'geo' => ['from' => 'US'],
            'email_count' => 0,
            'optout' => '',
        ];
    }
}
