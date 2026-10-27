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

class DmsAffiliateNetworkOfferImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'DMS HitPath import',
            'slug' => 'hitpath-import-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->useFakePublicDnsResolver();
    }

    public function test_hitpath_import_maps_selected_offer_and_email_assets_using_dms_rules(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor(['allowed_users' => [(int) $operator->getKey()]]);
        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::sequence()
                ->push($this->campaignsXml(), 200, ['Content-Type' => 'application/xml'])
                ->push($this->emailListXml(), 200, ['Content-Type' => 'application/xml'])
                ->push($this->emailRawXml(), 200, ['Content-Type' => 'application/xml']),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001', 'not-a-number'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 1 offers']);

        $offer = $this->workspace->offers()->with('assets')->sole();
        $this->assertSame('9001', $offer->offer_id);
        $this->assertSame('Paused Insurance CPA Monday-Friday', $offer->name);
        $this->assertSame('insurance', $offer->vertical);
        $this->assertSame(['US', 'GB'], $offer->geo_target);
        $this->assertSame(13.75, (float) $offer->payout);
        $this->assertSame('CPA', $offer->type);
        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $offer->allowed_days);
        $this->assertSame('https://tracking.example.test/click/9001', $offer->redirect);
        $this->assertSame('https://tracking.example.test/unsubscribe/9001', $offer->unsubscribe);
        $this->assertSame('Terms and conditions apply', $offer->note);
        $this->assertSame([(int) $operator->getKey()], $offer->allowed_users);
        $this->assertSame('paused', $offer->status);
        $this->assertFalse($offer->is_active);
        $this->assertSame(['from', 'from', 'subject'], $offer->assets->pluck('type')->all());
        $this->assertSame('Alpha Support', $offer->assets->firstWhere('type', 'from')->value);
        $this->assertSame('A clear subject', $offer->assets->firstWhere('type', 'subject')->value);

        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.hitpath.example.test/pubapi.php'
            && $request->method() === 'POST'
            && $request->data()['apifunc'] === 'getcampaigns'
            && $request->data()['apikey'] === 'synthetic-hitpath-key');
        Http::assertSent(fn (ClientRequest $request): bool => $request->data()['apifunc'] === 'getemaillist'
            && $request->data()['campaignid'] === '9001');
        Http::assertSent(fn (ClientRequest $request): bool => $request->data()['apifunc'] === 'getemailraw'
            && $request->data()['emailid'] === '77');
    }

    public function test_hitpath_import_ignores_soft_deleted_duplicates_without_restoring_them(): void
    {
        $sponsor = $this->createSponsor();
        $existing = $this->workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => '9001',
            'name' => 'Previously removed HitPath offer',
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
            'https://api.hitpath.example.test/pubapi.php' => Http::sequence()
                ->push($this->campaignsXml(), 200, ['Content-Type' => 'application/xml'])
                ->push($this->emailListXml(), 200, ['Content-Type' => 'application/xml'])
                ->push($this->emailRawXml(), 200, ['Content-Type' => 'application/xml']),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertOk()->assertExactJson(['data' => 'Imported 0 offers']);

        $this->assertSoftDeleted('workspace_offers', ['id' => $existing->getKey()]);
        $this->assertSame(1, $this->workspace->offers()->withTrashed()->count());
        Http::assertSentCount(3);
    }

    public function test_hitpath_response_failure_saves_nothing_and_does_not_echo_provider_body(): void
    {
        $sponsor = $this->createSponsor();
        Http::fake([
            'https://api.hitpath.example.test/pubapi.php' => Http::response('private provider response', 503),
        ]);

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertStatus(502)
            ->assertJsonMissing(['message' => 'private provider response']);

        $this->assertDatabaseCount('workspace_offers', 0);
    }

    public function test_provider_url_resolving_to_private_address_is_blocked_before_http_request(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['127.0.0.1']),
        );
        $sponsor = $this->createSponsor();
        Http::fake();

        $this->postJson(route('console.offers.import', $this->workspace->getKey()), [
            'sponsor' => $sponsor->getKey(),
            'ids' => ['9001'],
        ])->assertStatus(502);

        Http::assertNothingSent();
        $this->assertDatabaseCount('workspace_offers', 0);
    }

    public function test_unknown_networks_remain_unavailable_until_their_dms_adapters_are_implemented(): void
    {
        Http::fake();
        $sponsor = $this->createSponsor(['type' => 'unknown-network']);

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
            'name' => 'HitPath synthetic sponsor',
            'affiliate_id' => null,
            'url' => 'https://affiliate.hitpath.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-hitpath-key',
            'type' => 'hitpath',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function useFakePublicDnsResolver(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['1.1.1.1']),
        );
    }

    private function campaignsXml(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <response><data><campaign>
                <campaignid>9001</campaignid>
                <name>Paused Insurance CPA Monday-Friday</name>
                <category>Insurance, Home</category>
                <geotargeting>US, GB, XX</geotargeting>
                <payout>$13.75</payout>
                <description>Terms and conditions apply</description>
            </campaign></data></response>
            XML;
    }

    private function emailListXml(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <response><data><email><id>77</id></email></data></response>
            XML;
    }

    private function emailRawXml(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <response><data>
                <id>77</id>
                <fromlines>  Alpha Support  
                Beta Support</fromlines>
                <subjects>A clear subject</subjects>
                <body>&lt;a href="https://tracking.example.test/click/9001"&gt;Open&lt;/a&gt;&lt;a href="https://tracking.example.test/unsubscribe/9001"&gt;Unsubscribe&lt;/a&gt;</body>
            </data></response>
            XML;
    }
}
