<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsDomainProviderImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Registrar import test',
            'slug' => 'registrar-import-test',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
    }

    public function test_godaddy_import_maps_active_domain_metadata_and_skips_existing_ids_and_names(): void
    {
        $provider = $this->provider('godaddy');
        $existing = $this->workspace->sendingDomains()->create([
            'name' => 'already.example',
            'state' => 'pending',
            'verification_token' => 'existing-token',
            'created_by' => $this->owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'provider_domain_id' => 'domain-2',
            'status' => 'new',
            'is_our' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'api.godaddy.com/v1/domains?*' => Http::response([
                ['domainId' => 'domain-1', 'domain' => 'Fresh.Example.', 'exposeWhois' => false, 'renewAuto' => true, 'expires' => '2027-02-03T00:00:00Z'],
                ['domainId' => 'domain-2', 'domain' => 'already.example', 'exposeWhois' => true, 'renewAuto' => false, 'expires' => '2027-02-03T00:00:00Z'],
                ['domainId' => 'domain-3', 'domain' => 'ALREADY.example', 'exposeWhois' => true, 'renewAuto' => false, 'expires' => '2027-02-03T00:00:00Z'],
            ]),
        ]);

        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertOk()
            ->assertExactJson(['data' => 'Added : 1 new domains']);

        $domain = $this->workspace->sendingDomains()->where('name', 'fresh.example')->firstOrFail();
        $this->assertSame('domain-1', $domain->provider_domain_id);
        $this->assertSame($provider->getKey(), $domain->domains_provider_id);
        $this->assertTrue($domain->have_who_is);
        $this->assertTrue($domain->have_renew);
        $this->assertSame('2027-02-03', $domain->expire_at->toDateString());
        $this->assertTrue($domain->is_our);
        $this->assertSame('new', $domain->status);
        $this->assertSame('domain-2', $existing->fresh()->provider_domain_id);
        $this->assertSame(2, $this->workspace->sendingDomains()->count());
        $this->assertSame(['count' => 1, 'type' => 'godaddy'], AuditEvent::query()->where('action', 'domain.dms_auto_imported')->firstOrFail()->properties);
        $installableDomainLabels = app(DmsBulkInstallEligibility::class)
            ->for($this->workspace)['domains']
            ->pluck('label')
            ->all();
        $this->assertEqualsCanonicalizing([
            'already.example | STATUS: new | IS OUR: YES | LISTED: SPAMHAUS = NO - HOSTKARMA = NO',
            'fresh.example | STATUS: new | IS OUR: YES | LISTED: SPAMHAUS = NO - HOSTKARMA = NO',
        ], $installableDomainLabels);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'sso-key synthetic-key:synthetic-secret')
            && $request->url() === 'https://api.godaddy.com/v1/domains?statuses=ACTIVE');
    }

    public function test_namecheap_import_walks_pages_and_omits_locked_or_expired_domains(): void
    {
        $provider = $this->provider('namecheap');
        $pageOne = <<<'XML'
            <?xml version="1.0"?>
            <ApiResponse Status="OK"><CommandResponse><DomainGetListResult>
              <Domain ID="nc-1" Name="one.example" IsLocked="false" IsExpired="false" WhoisGuard="ENABLED" AutoRenew="true" Expires="2027-04-05" />
              <Domain ID="nc-2" Name="locked.example" IsLocked="true" IsExpired="false" WhoisGuard="ENABLED" AutoRenew="true" Expires="2027-04-05" />
              <Domain ID="nc-3" Name="expired.example" IsLocked="false" IsExpired="true" WhoisGuard="ENABLED" AutoRenew="true" Expires="2027-04-05" />
            </DomainGetListResult><Paging><TotalItems>100</TotalItems></Paging></CommandResponse></ApiResponse>
            XML;
        $pageTwo = <<<'XML'
            <?xml version="1.0"?>
            <ApiResponse Status="OK"><CommandResponse><DomainGetListResult>
              <Domain ID="nc-4" Name="two.example" IsLocked="false" IsExpired="false" WhoisGuard="DISABLED" AutoRenew="false" Expires="2027-08-09" />
            </DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging></CommandResponse></ApiResponse>
            XML;

        Http::fakeSequence('https://api.namecheap.com/xml.response')
            ->push($pageOne, 200)
            ->push($pageTwo, 200);

        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertOk()
            ->assertExactJson(['data' => 'Added : 2 new domains']);

        $domains = $this->workspace->sendingDomains()->orderBy('name')->get()->keyBy('name');
        $this->assertSame(['one.example', 'two.example'], $domains->keys()->all());
        $this->assertTrue($domains['one.example']->have_who_is);
        $this->assertTrue($domains['one.example']->have_renew);
        $this->assertFalse($domains['two.example']->have_who_is);
        $this->assertFalse($domains['two.example']->have_renew);
        $this->assertSame('nc-1', $domains['one.example']->provider_domain_id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.namecheap.com/xml.response'
            && $request->data()['Command'] === 'namecheap.domains.getList'
            && (string) $request->data()['Page'] === '1'
            && (string) $request->data()['PageSize'] === '100'
            && $request->data()['ApiUser'] === 'synthetic-user'
            && $request->data()['ClientIP'] === '127.0.0.1');
        Http::assertSent(fn ($request) => (string) $request->data()['Page'] === '2');
    }

    public function test_cloudflare_import_paginates_active_zones_and_makes_them_installable(): void
    {
        $provider = $this->provider('cloudflare');
        Http::fakeSequence('https://api.cloudflare.com/client/v4/zones*')
            ->push([
                'success' => true,
                'result' => [
                    ['id' => str_repeat('a', 32), 'name' => 'Active.Example.', 'status' => 'active'],
                    ['id' => str_repeat('b', 32), 'name' => 'pending.example', 'status' => 'pending'],
                ],
                'result_info' => ['total_pages' => 2],
            ])
            ->push([
                'success' => true,
                'result' => [
                    ['id' => str_repeat('c', 32), 'name' => 'second.example', 'status' => 'active'],
                ],
                'result_info' => ['total_pages' => 2],
            ]);

        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertOk()
            ->assertExactJson(['data' => 'Added : 2 new domains']);

        $domains = $this->workspace->sendingDomains()->orderBy('name')->get()->keyBy('name');
        $this->assertSame(['active.example', 'second.example'], $domains->keys()->all());
        $this->assertSame(str_repeat('a', 32), $domains['active.example']->provider_domain_id);
        $this->assertTrue($domains['active.example']->is_our);
        $this->assertTrue($domains['active.example']->is_active);
        $this->assertSame('new', $domains['active.example']->status);
        $this->assertNull($domains['active.example']->expire_at);
        $this->assertEqualsCanonicalizing(
            [
                'active.example | STATUS: new | IS OUR: YES | LISTED: SPAMHAUS = NO - HOSTKARMA = NO',
                'second.example | STATUS: new | IS OUR: YES | LISTED: SPAMHAUS = NO - HOSTKARMA = NO',
            ],
            app(DmsBulkInstallEligibility::class)->for($this->workspace)['domains']->pluck('label')->all(),
        );

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer synthetic-key')
            && str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'per_page=50'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
    }

    public function test_a_new_namecheap_provider_can_import_domains_through_the_api(): void
    {
        $this->actingAs($this->owner)->postJson(route('console.infrastructure.domain-providers.store', $this->workspace->getKey()), [
            'name' => 'Namecheap account',
            'type' => 'namecheap',
            'username' => 'synthetic-user',
            'password' => null,
            'api_key' => 'synthetic-api-key',
            'api_secret' => null,
        ])->assertOk();
        $provider = DomainProvider::query()->sole();

        Http::fake(['api.namecheap.com/xml.response' => Http::response(
            '<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse><DomainGetListResult><Domain ID="nc-1" Name="provider.example" IsLocked="false" IsExpired="false" WhoisGuard="ENABLED" AutoRenew="true" Expires="2027-04-05" /></DomainGetListResult><Paging><TotalItems>1</TotalItems></Paging></CommandResponse></ApiResponse>',
        )]);

        $this->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertOk()->assertExactJson(['data' => 'Added : 1 new domains']);
        $this->assertDatabaseHas('sending_domains', [
            'workspace_id' => $this->workspace->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'name' => 'provider.example',
            'is_our' => true,
        ]);
        Http::assertSentCount(1);
    }

    public function test_import_never_calls_registrar_when_credentials_are_incomplete(): void
    {
        $provider = DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Incomplete Registrar',
            'type' => 'godaddy',
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-key',
            'api_secret' => null,
            'is_active' => true,
        ]);
        Http::fake();

        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.provider.0', 'The saved GoDaddy API key or API secret is incomplete.');

        Http::assertNothingSent();
        $this->assertSame(0, $this->workspace->sendingDomains()->count());
    }

    public function test_provider_failure_is_safe_and_creates_no_partial_domains(): void
    {
        $provider = $this->provider('godaddy');
        Http::fake(['api.godaddy.com/v1/domains?*' => Http::response(['message' => 'secret echoed from provider'], 403)]);

        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => $provider->getKey()])
            ->assertUnprocessable()
            ->assertJsonMissing(['secret echoed from provider']);

        $this->assertSame(0, $this->workspace->sendingDomains()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'domain.dms_auto_imported')->count());
    }

    public function test_provider_import_is_workspace_scoped_and_requires_a_manager(): void
    {
        $provider = $this->provider('godaddy');
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other import workspace',
            'slug' => 'other-import-workspace',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        Http::fake();

        $this->actingAs($otherOwner)->postJson(route('console.infrastructure.domains.import', $otherWorkspace->getKey()), ['provider' => $provider->getKey()])->assertNotFound();
        $this->actingAs($operator)->postJson($this->route(), ['provider' => $provider->getKey()])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_domain_import_page_enables_the_dms_auto_action(): void
    {
        $olderProvider = $this->provider('namecheap');
        $olderProvider->created_at = now()->subMinute();
        $olderProvider->save();
        $this->provider('godaddy');

        $this->actingAs($this->owner)->get('/infrastructure/domains/create')
            ->assertOk()
            ->assertSeeInOrder(['Synthetic Godaddy', 'Synthetic Namecheap'])
            ->assertSee('AUTO')
            ->assertSee('IMPORT')
            ->assertSee('domain-import-result')
            ->assertSee('window.parent.closeModal()')
            ->assertSee('swalPromise')
            ->assertDontSee('ADD PROVIDER')
            ->assertDontSee('domain-result')
            ->assertDontSee('Automatic registrar import is not connected yet.');
    }

    private function provider(string $type): DomainProvider
    {
        return DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Synthetic '.ucfirst($type),
            'type' => $type,
            'username' => 'synthetic-user',
            'password' => null,
            'api_key' => 'synthetic-key',
            'api_secret' => 'synthetic-secret',
            'is_active' => true,
        ]);
    }

    private function route(): string
    {
        return route('console.infrastructure.domains.import', $this->workspace->getKey());
    }
}
