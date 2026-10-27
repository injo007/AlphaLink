<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\SendingDomainHistory;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DomainTxtResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DmsDomainManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = $this->workspace('domain-management-test', $this->owner);
    }

    public function test_domains_register_uses_the_dms_table_filter_and_workspace_scope(): void
    {
        $provider = $this->provider();
        $owned = $this->domain($this->workspace, $provider, 'owned.example', true);
        $manual = $this->domain($this->workspace, $provider, 'manual.example', false);
        SendingDomainHistory::factory()->forDomain($owned)->create([
            'is_listed_spamhaus' => true,
            'is_listed_hostkarma' => false,
        ]);

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace('other-domain-workspace', $otherOwner);
        $this->domain($otherWorkspace, $provider, 'foreign.example', false);

        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.domains.store', $this->workspace->getKey()), [
                'name' => 'sender-proof-only.example',
            ])
            ->assertRedirect($this->newTestMessageUrl());

        $this->actingAs($this->owner)
            ->getJson($this->domainsUrl())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['dns' => 'manual.example'])
            ->assertJsonMissing(['dns' => 'sender-proof-only.example'])
            ->assertJsonMissing(['dns' => 'foreign.example']);

        $this->actingAs($this->owner)
            ->getJson($this->domainsUrl().'?only_our=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->getKey())
            ->assertJsonPath('data.0.provider.name', 'Synthetic Registrar')
            ->assertJsonPath('data.0.provider.type', 'godaddy')
            ->assertJsonPath('data.0.history.is_listed_spamhaus', true)
            ->assertJsonPath('data.0.histories_count', 1)
            ->assertJsonMissing(['dns' => 'foreign.example']);

        $this->actingAs($this->owner)
            ->getJson($this->domainsUrl().'?only_our=0')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $manual->getKey()]);

        $this->assertDatabaseHas('sending_domains', ['id' => $manual->getKey(), 'is_our' => false]);
    }

    public function test_domains_parent_matches_dms_table_shell_and_hosts_add_domains_in_an_iframe_modal(): void
    {
        $response = $this->actingAs($this->owner)->get($this->domainsUrl())->assertOk();

        $response->assertSee('<title>Domains - DMS</title>', false)
            ->assertSee('id="domains"', false)
            ->assertSee('id="only_our" checked', false)
            ->assertDontSee('AlphaLink Sender Domain Verification')
            ->assertSee('ONLY OUR')
            ->assertSee('displayLength: 50', false)
            ->assertSee("$('#only_our').on('click', function () { datatable.ajax.reload(); });", false)
            ->assertSee("data.only_our = $('#only_our').is(':checked');", false)
            ->assertSee('ADD NEW DOMAINS')
            ->assertSee('<iframe src="${link}"', false)
            ->assertSee('size: \'lg\'', false)
            ->assertSee('window.closeModal', false)
            ->assertSee('datatable.ajax.reload(null, false)', false);
    }

    public function test_sender_domain_verification_is_separate_from_the_dms_domain_register(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'sender-import.example', false);

        $this->actingAs($this->owner)
            ->get($this->domainsUrl())
            ->assertOk()
            ->assertDontSee('sender-import.example')
            ->assertDontSee($domain->verification_token)
            ->assertSee('id="only_our" checked', false);

        $this->actingAs($this->owner)
            ->get($this->newTestMessageUrl())
            ->assertOk()
            ->assertSee('Transactional sender-domain readiness')
            ->assertSee('sender-import.example')
            ->assertSee('_alphalink-verify.sender-import.example')
            ->assertSee($domain->verification_token)
            ->assertSee('Check DNS record')
            ->assertSee('DMS Campaign Test uses the selected PMTA server and does not require sender-domain DNS verification.');
    }

    public function test_sender_domain_can_be_registered_from_the_transactional_test_page(): void
    {
        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.domains.store', $this->workspace->getKey()), [
                'name' => ' New.Sender.Example. ',
            ])
            ->assertRedirect($this->newTestMessageUrl())
            ->assertSessionHas('status', 'Sender domain saved.');

        $domain = $this->workspace->sendingDomains()->where('name', 'new.sender.example')->firstOrFail();
        $this->assertSame('pending', $domain->state);
        $this->assertNotEmpty($domain->verification_token);
        $this->assertNull($domain->is_our);
    }

    public function test_sender_domain_check_verifies_only_when_the_exact_txt_value_is_present(): void
    {
        $domain = $this->domain($this->workspace, null, 'sender-check.example', false);
        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')
            ->once()
            ->with('_alphalink-verify.sender-check.example')
            ->andReturn([$domain->verification_token]);
        $this->app->instance(DomainTxtResolver::class, $resolver);

        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.domains.verify', [$this->workspace->getKey(), $domain->getKey()]))
            ->assertRedirect($this->newTestMessageUrl())
            ->assertSessionHas('status', 'Domain ownership verified.');

        $domain->refresh();
        $this->assertSame('verified', $domain->state);
        $this->assertNotNull($domain->verified_at);
        $this->assertFalse($domain->is_our);
    }

    public function test_sender_domain_check_with_a_missing_txt_value_stays_pending(): void
    {
        $domain = $this->domain($this->workspace, null, 'sender-pending.example', false);
        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldReceive('records')
            ->once()
            ->with('_alphalink-verify.sender-pending.example')
            ->andReturn(['a-different-value']);
        $this->app->instance(DomainTxtResolver::class, $resolver);

        $this->actingAs($this->owner)
            ->post(route('console.infrastructure.domains.verify', [$this->workspace->getKey(), $domain->getKey()]))
            ->assertRedirect($this->newTestMessageUrl())
            ->assertSessionHas('warning', 'TXT record not found yet. Check its name and value, then try again.');

        $this->assertSame('pending', $domain->fresh()->state);
        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_sender_domain_check_cannot_access_a_domain_from_another_workspace(): void
    {
        $domain = $this->domain($this->workspace, null, 'private-domain.example', false);
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace('other-sender-domain-workspace', $otherOwner);
        $resolver = Mockery::mock(DomainTxtResolver::class);
        $resolver->shouldNotReceive('records');
        $this->app->instance(DomainTxtResolver::class, $resolver);

        $this->actingAs($otherOwner)
            ->post(route('console.infrastructure.domains.verify', [$otherWorkspace->getKey(), $domain->getKey()]))
            ->assertNotFound();

        $this->assertSame('pending', $domain->fresh()->state);
        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_manual_domains_map_to_dms_install_defaults_and_can_be_readded_after_soft_delete(): void
    {
        $provider = $this->provider();
        $route = route('console.infrastructure.domains.manual', $this->workspace->getKey());

        $this->actingAs($this->owner)->postJson($route, [
            'provider' => $provider->getKey(),
            'domains' => ['  server.example  '],
        ])->assertOk()->assertExactJson(['data' => '1 Domain Created']);

        $domain = $this->workspace->sendingDomains()->where('name', 'server.example')->firstOrFail();
        $this->assertSame($provider->getKey(), $domain->domains_provider_id);
        $this->assertSame('new', $domain->status);
        $this->assertFalse($domain->is_our);
        $this->assertTrue($domain->is_active);
        $this->assertFalse($domain->have_who_is);
        $this->assertFalse($domain->have_renew);
        $this->assertSame(now()->addYear()->toDateString(), $domain->expire_at->toDateString());

        $this->actingAs($this->owner)->deleteJson($this->deleteUrl(), ['ids' => [$domain->getKey()]])->assertOk();

        $this->actingAs($this->owner)->postJson($route, [
            'provider' => $provider->getKey(),
            'domains' => ['server.example'],
        ])->assertOk()->assertExactJson(['data' => '1 Domain Created']);

        $this->assertSame(1, $this->workspace->sendingDomains()->where('name', 'server.example')->count());
        $this->assertSame(2, $this->workspace->sendingDomains()->withTrashed()->where('name', 'server.example')->count());
    }

    public function test_status_active_and_soft_delete_actions_are_scoped_and_audited(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'transition.example', false);
        $id = $domain->getKey();

        $this->actingAs($this->owner)
            ->patchJson(route('console.infrastructure.domains.active', [$this->workspace->getKey(), $id]), ['status' => false])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertFalse($domain->fresh()->is_active);

        $this->actingAs($this->owner)
            ->patchJson(route('console.infrastructure.domains.status', [$this->workspace->getKey(), $id]), ['status' => 'free'])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertSame('free', $domain->fresh()->status);

        $this->actingAs($this->owner)
            ->deleteJson($this->deleteUrl(), ['ids' => [$id]])
            ->assertOk()->assertExactJson(['data' => 'Removed Successfully']);
        $this->assertSoftDeleted('sending_domains', ['id' => $id]);
        $this->assertSame(3, AuditEvent::query()->where('subject_id', $id)->count());
    }

    public function test_domain_status_rejects_values_outside_the_dms_enum(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'invalid-state.example', false);

        $this->actingAs($this->owner)
            ->patchJson(route('console.infrastructure.domains.status', [$this->workspace->getKey(), $domain->getKey()]), ['status' => 'pending'])
            ->assertUnprocessable();

        $this->assertSame('new', $domain->fresh()->status);
    }

    public function test_history_table_returns_server_side_datatables_data_only_for_the_selected_workspace_domain(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'history.example', false);
        SendingDomainHistory::factory()->forDomain($domain)->create([
            'a_record' => '192.0.2.41',
            'spf' => 'v=spf1 include:example.net -all',
            'is_listed_spamhaus' => false,
            'is_listed_hostkarma' => true,
        ]);
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = $this->workspace('foreign-history-workspace', $foreignOwner);
        $foreignDomain = $this->domain($foreignWorkspace, null, 'foreign-history.example', false);
        SendingDomainHistory::factory()->forDomain($foreignDomain)->create(['a_record' => '198.51.100.4']);

        $this->actingAs($this->owner)
            ->getJson(route('console.infrastructure.domains.history', [$this->workspace->getKey(), $domain->getKey()]).'?draw=4&start=0&length=10')
            ->assertOk()
            ->assertJsonPath('draw', 4)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.a_record', '192.0.2.41')
            ->assertJsonPath('data.0.is_listed_hostkarma', true)
            ->assertJsonMissing(['a_record' => '198.51.100.4']);

        $this->actingAs($foreignOwner)
            ->get(route('console.infrastructure.domains.history', [$this->workspace->getKey(), $domain->getKey()]))
            ->assertNotFound();
    }

    public function test_history_page_renders_the_dms_snapshot_columns_and_refresh_control(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'history-view.example', false);

        $this->actingAs($this->owner)
            ->get(route('clean.console.infrastructure.domains.history', $domain->getKey()))
            ->assertOk()
            ->assertSee('<title>Domain History - DMS</title>', false)
            ->assertSee($domain->name)
            ->assertSee('id="histories"', false)
            ->assertSee('serverSide: true', false)
            ->assertSee("title: 'is listed spamhaus'", false)
            ->assertSee("title: 'is listed hostkarma'", false)
            ->assertSee("data: 'created_at'", false)
            ->assertSee("titleAttr: 'REFRESH'", false);
    }

    public function test_owned_domain_refresh_uses_the_provider_api_and_maps_the_same_dms_fields(): void
    {
        $provider = $this->provider();
        $domain = $this->domain($this->workspace, $provider, 'owned-refresh.example', true);
        Http::fake([
            'api.godaddy.com/v1/domains/owned-refresh.example' => Http::response([
                'exposeWhois' => false,
                'renewAuto' => true,
                'expires' => '2027-08-09T00:00:00Z',
            ]),
        ]);

        $this->actingAs($this->owner)
            ->putJson(route('console.infrastructure.domains.update', [$this->workspace->getKey(), $domain->getKey()]))
            ->assertOk()->assertExactJson(['data' => 'Domain updated']);

        $domain->refresh();
        $this->assertTrue($domain->have_who_is);
        $this->assertTrue($domain->have_renew);
        $this->assertSame('2027-08-09', $domain->expire_at->toDateString());
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.godaddy.com/v1/domains/owned-refresh.example'
            && $request->hasHeader('Authorization', 'sso-key synthetic-key:synthetic-secret'));
    }

    public function test_nonowned_domain_refresh_extends_expiration_without_calling_a_registrar(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'manual-refresh.example', false);
        $before = now()->addYear()->toDateString();
        Http::fake();

        $this->actingAs($this->owner)
            ->putJson(route('console.infrastructure.domains.update', [$this->workspace->getKey(), $domain->getKey()]))
            ->assertOk()->assertExactJson(['data' => 'Domain updated']);

        $this->assertSame($before, $domain->fresh()->expire_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_domain_actions_require_workspace_owner_or_admin_and_hide_foreign_ids(): void
    {
        $domain = $this->domain($this->workspace, $this->provider(), 'protected.example', false);
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        Http::fake();

        $this->actingAs($operator)
            ->patchJson(route('console.infrastructure.domains.active', [$this->workspace->getKey(), $domain->getKey()]), ['status' => false])
            ->assertForbidden();

        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspace('domain-foreign-action', $otherOwner);
        $this->actingAs($otherOwner)
            ->patchJson(route('console.infrastructure.domains.active', [$otherWorkspace->getKey(), $domain->getKey()]), ['status' => false])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertTrue($domain->fresh()->is_active);
    }

    private function domainsUrl(): string
    {
        return route('clean.console.infrastructure.domains.index');
    }

    private function newTestMessageUrl(): string
    {
        return route('clean.console.campaigns.test-draft.create');
    }

    private function deleteUrl(): string
    {
        return route('console.infrastructure.domains.destroy', $this->workspace->getKey());
    }

    private function provider(): DomainProvider
    {
        return DomainProvider::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Synthetic Registrar',
            'type' => 'godaddy',
            'username' => 'synthetic-user',
            'api_key' => 'synthetic-key',
            'api_secret' => 'synthetic-secret',
            'is_active' => true,
        ]);
    }

    private function domain(Workspace $workspace, ?DomainProvider $provider, string $name, bool $isOur): SendingDomain
    {
        return $workspace->sendingDomains()->create([
            'name' => $name,
            'state' => 'pending',
            'verification_token' => 'synthetic-token-'.str_replace('.', '-', $name),
            'created_by' => $workspace->created_by,
            'domains_provider_id' => $provider?->getKey(),
            'provider_domain_id' => $isOur ? 'provider-'.str_replace('.', '-', $name) : null,
            'status' => 'new',
            'is_our' => $isOur,
            'is_active' => true,
            'have_who_is' => $isOur,
            'have_renew' => $isOur,
            'expire_at' => '2027-12-31',
        ]);
    }

    private function workspace(string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
