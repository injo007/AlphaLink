<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsSponsorExtensionPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceSponsorManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Sponsor management test',
            'slug' => 'sponsor-test-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->bind(DmsAffiliateProviderHttp::class, fn (): DmsAffiliateProviderHttp => new DmsAffiliateProviderHttp(
            fn (string $host): array => ['93.184.216.34'],
        ));
    }

    public function test_sponsor_management_matches_dms_fields_and_keeps_credentials_encrypted_and_out_of_list_responses(): void
    {
        $createUrl = route('console.sponsors.store', $this->workspace->getKey());
        $this->postJson($createUrl, [
            'affiliate_id' => 'network-42',
            'name' => 'Example Network',
            'url' => 'https://network.example.test/login',
            'username' => 'local-user-42',
            'password' => 'local-secret-42',
            'api_key' => 'local-api-key-42',
            'type' => 'everflow',
        ])->assertOk()->assertExactJson(['data' => 'Sponsor created']);

        $sponsor = $this->workspace->sponsors()->sole();
        $raw = DB::table('workspace_sponsors')->where('id', $sponsor->getKey())->first();
        $this->assertNotSame('local-user-42', $raw->username);
        $this->assertNotSame('local-secret-42', $raw->password);
        $this->assertNotSame('local-api-key-42', $raw->api_key);
        $this->assertSame('local-secret-42', $sponsor->password);
        $this->assertSame('local-api-key-42', $sponsor->api_key);
        $this->assertSame([], $sponsor->allowed_users);
        $this->assertTrue($sponsor->is_active);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'sponsor.created',
            'subject_id' => (string) $sponsor->getKey(),
        ]);

        $this->getJson(route('console.sponsors.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Example Network')
            ->assertJsonPath('data.0.username', 'local-user-42')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.api_key');

        $this->get(route('clean.console.sponsors.edit', $sponsor->getKey()))
            ->assertOk()
            ->assertSee('EDIT SPONSOR')
            ->assertSee('TEST CONNECTION')
            ->assertSee('leave blank to keep the saved password')
            ->assertSee('local-user-42')
            ->assertDontSee('local-secret-42')
            ->assertDontSee('local-api-key-42');

        $this->postJson($createUrl, ['name' => 'X'])->assertUnprocessable()
            ->assertJsonValidationErrors(['url', 'username', 'password', 'api_key', 'type']);
        $this->assertStringNotContainsString('local-secret-42', json_encode(AuditEvent::query()->firstOrFail()->properties));
    }

    public function test_sponsor_connection_button_uses_read_only_api_checks_for_all_five_networks(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), 'api.hitpath.example.test/pubapi.php')) {
                return Http::response('<ApiResponse Status="OK"><CommandResponse/></ApiResponse>');
            }
            if (str_contains($request->url(), 'newhitpath.example.test/api/reports/campaigns')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), 'cake.example.test/affiliates/api/Reports/Conversions')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), 'network.api.hasoffers.com/Apiv3/json')) {
                return Http::response(['response' => ['status' => 1, 'data' => []]]);
            }
            if (str_contains($request->url(), 'api.eflow.team/v1/affiliates/reporting/conversions')) {
                return Http::response(['conversions' => []]);
            }

            return Http::response(['message' => 'unexpected synthetic request'], 500);
        });

        $payloads = [
            ['type' => 'hitpath', 'url' => 'https://hitpath.example.test/login', 'affiliate_id' => null],
            ['type' => 'newhitpath', 'url' => 'https://newhitpath.example.test', 'affiliate_id' => null],
            ['type' => 'cake', 'url' => 'https://cake.example.test', 'affiliate_id' => 'cake-affiliate'],
            ['type' => 'hasoffers', 'url' => 'https://network.example.test', 'affiliate_id' => 'network'],
            ['type' => 'everflow', 'url' => 'https://everflow.example.test', 'affiliate_id' => null],
        ];

        foreach ($payloads as $payload) {
            $this->postJson(route('console.sponsors.test-new-connection', $this->workspace->getKey()), [
                ...$payload,
                'api_key' => 'synthetic-sponsor-api-key',
            ])->assertOk()->assertJsonPath('success', true);
        }

        Http::assertSentCount(5);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/pubapi.php')
            && ($request->data()['apifunc'] ?? null) === 'getcampaigns');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/api/reports/campaigns?')
            && $request->hasHeader('Authorization', 'Bearer synthetic-sponsor-api-key'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/affiliates/api/Reports/Conversions?')
            && str_contains($request->url(), 'affiliate_id=cake-affiliate'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'network.api.hasoffers.com/Apiv3/json?')
            && str_contains($request->url(), 'limit=1'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'api.eflow.team/v1/affiliates/reporting/conversions?page=1')
            && $request->hasHeader('x-eflow-api-key', 'synthetic-sponsor-api-key')
            && ($request->data()['from'] ?? null) === now()->toDateString());
        $this->assertSame(0, WorkspaceSponsor::query()->count());
        $this->assertSame(0, WorkspaceOffer::query()->count());
    }

    public function test_sponsor_connection_check_uses_saved_key_for_edits_and_sanitizes_failures(): void
    {
        $sponsor = $this->createSponsor('Saved Everflow', [
            'type' => 'everflow',
            'url' => 'https://everflow.example.test',
            'affiliate_id' => null,
            'api_key' => 'saved-everflow-key',
        ]);
        Http::fake([
            'https://api.eflow.team/v1/affiliates/reporting/conversions*' => Http::response(['message' => 'synthetic-provider-body'], 403),
        ]);

        $this->postJson(route('console.sponsors.test-existing-connection', [
            $this->workspace->getKey(), $sponsor->getKey(),
        ]), [
            'type' => 'everflow', 'url' => $sponsor->url, 'api_key' => '',
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Everflow rejected the API request (HTTP 403). Check the credentials and account access.')
            ->assertJsonMissing(['message' => 'synthetic-provider-body']);

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('x-eflow-api-key', 'saved-everflow-key'));
        $this->assertSame('saved-everflow-key', $sponsor->fresh()->api_key);

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)->postJson(
            route('console.sponsors.test-existing-connection', [$this->workspace->getKey(), $sponsor->getKey()]),
            ['type' => 'everflow', 'url' => $sponsor->url],
        )->assertForbidden();
    }

    public function test_everflow_http_success_with_an_error_payload_is_not_reported_as_connected(): void
    {
        Http::fake([
            'https://api.eflow.team/v1/affiliates/reporting/conversions*' => Http::response([
                'message' => 'synthetic invalid key detail',
            ]),
        ]);

        $this->postJson(route('clean.console.sponsors.test-new-connection'), [
            'type' => 'everflow',
            'url' => 'https://everflow.example.test',
            'api_key' => 'synthetic-everflow-key',
        ])->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Everflow returned an unexpected API response. Check the API key.')
            ->assertJsonMissing(['message' => 'synthetic invalid key detail']);

        Http::assertSentCount(1);
        $this->assertSame(0, WorkspaceSponsor::query()->count());
    }

    public function test_edit_preserves_blank_secrets_and_status_is_separate_and_workspace_scoped(): void
    {
        $sponsor = $this->createSponsor('Visible Network', ['password' => 'keep-this-password', 'api_key' => 'keep-this-api-key']);
        $updateUrl = route('console.sponsors.update', [$this->workspace->getKey(), $sponsor->getKey()]);

        $this->putJson($updateUrl, [
            'affiliate_id' => 'edited-id',
            'name' => 'Renamed Network',
            'url' => 'https://renamed.example.test',
            'username' => 'new-user',
            'password' => '',
            'api_key' => '',
            'type' => 'cake',
        ])->assertOk()->assertExactJson(['data' => 'Sponsor updated']);

        $this->assertSame('keep-this-password', $sponsor->fresh()->password);
        $this->assertSame('keep-this-api-key', $sponsor->fresh()->api_key);
        $this->assertSame('Renamed Network', $sponsor->fresh()->name);
        $this->assertSame('cake', $sponsor->fresh()->type);

        $this->patchJson(route('console.sponsors.status', [$this->workspace->getKey(), $sponsor->getKey()]), ['status' => false])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertFalse($sponsor->fresh()->is_active);

        $otherWorkspace = $this->createOtherWorkspace();
        $this->get(route('clean.console.sponsors.edit', $sponsor->getKey()))->assertNotFound();
        $this->putJson(route('console.sponsors.update', [$otherWorkspace->getKey(), $sponsor->getKey()]), [
            'name' => 'Unauthorized',
        ])->assertNotFound();
    }

    public function test_network_list_shows_active_sponsors_and_limits_operators_to_assigned_sponsors(): void
    {
        $assignedOperator = User::factory()->create();
        $unassignedOperator = User::factory()->create();
        $this->workspace->users()->attach($assignedOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($unassignedOperator->getKey(), ['role' => 'operator']);
        $assigned = $this->createSponsor('Assigned Sponsor', ['allowed_users' => [$assignedOperator->getKey()]]);
        $this->createSponsor('Unassigned Sponsor');
        $this->createSponsor('Inactive Sponsor', ['is_active' => false, 'allowed_users' => [$assignedOperator->getKey()]]);
        $url = route('console.networks.sponsors.index', $this->workspace->getKey());

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Assigned Sponsor'])
            ->assertJsonFragment(['name' => 'Unassigned Sponsor'])
            ->assertJsonMissing(['name' => 'Inactive Sponsor']);

        $this->actingAs($assignedOperator)->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Assigned Sponsor')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.api_key');

        $this->actingAs($unassignedOperator)
            ->getJson($url)->assertOk()->assertExactJson(['data' => []]);
        $this->get(route('clean.console.sponsors.index'))->assertForbidden();

        $otherWorkspace = $this->createOtherWorkspace();
        $this->actingAs($assignedOperator)
            ->get(route('console.networks.sponsors.index', $otherWorkspace->getKey()))
            ->assertNotFound();
        $this->assertNotNull($assigned->fresh());
    }

    public function test_offer_count_and_sponsor_login_actions_are_exposed_with_the_reviewed_helper_notice(): void
    {
        $helperAvailable = app(DmsSponsorExtensionPackage::class)->downloadAvailable();
        $sponsorsPage = $this->get(route('clean.console.sponsors.index'))
            ->assertOk()
            ->assertSee('TOTAL OFFERS')
            ->assertSee('data-login-url=', false)
            ->assertSee('window.App.pingExtension', false)
            ->assertSee('window.App.commandExtension', false)
            ->assertSee("type: 'connect'", false)
            ->assertSee('LOGIN SPONSOR')
            ->assertSee('cookie access across HTTP and HTTPS')
            ->assertDontSee('Affiliate login is not implemented yet');

        $mySponsorsPage = $this->get(route('clean.console.networks.sponsors.index'))
            ->assertOk()
            ->assertSee('TOTAL OFFERS')
            ->assertSee('ACTIONS')
            ->assertSee('window.App.pingExtension', false)
            ->assertSee('window.App.commandExtension', false)
            ->assertSee("type: 'connect'", false)
            ->assertSee('LOGIN SPONSOR')
            ->assertSee('cookie access across HTTP and HTTPS')
            ->assertDontSee('Affiliate login is not implemented yet');

        foreach ([$sponsorsPage, $mySponsorsPage] as $page) {
            if ($helperAvailable) {
                $page->assertSee('Download sponsor helper');
            } else {
                $page->assertSee('The sponsor helper bundle is not installed on this app server')
                    ->assertDontSee('Download sponsor helper');
            }
        }

        $sponsor = $this->createSponsor('Counted Sponsor');
        $this->workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(), 'offer_id' => 'counted-1', 'name' => 'Counted offer',
            'type' => 'cpa', 'vertical' => 'insurance', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offers.example.test/1', 'allowed_days' => ['Mon'], 'status' => 'live',
            'allowed_users' => [], 'is_active' => false,
        ]);
        $this->getJson(route('console.sponsors.index', $this->workspace->getKey()))
            ->assertOk()->assertJsonPath('data.0.offers_count', 1)
            ->assertJsonPath('data.0.password', null)
            ->assertJsonMissingPath('data.0.api_key');
    }

    public function test_sponsor_pages_render_when_the_optional_helper_bundle_is_not_staged(): void
    {
        $package = $this->mock(DmsSponsorExtensionPackage::class);
        $package->shouldReceive('extensionIdIfAvailable')->andReturn(null);
        $package->shouldReceive('downloadAvailable')->andReturn(false);

        $this->get(route('clean.console.sponsors.index'))
            ->assertOk()
            ->assertSee('The sponsor helper bundle is not installed on this app server')
            ->assertDontSee('Download sponsor helper');

        $this->get(route('clean.console.networks.sponsors.index'))
            ->assertOk()
            ->assertSee('The sponsor helper bundle is not installed on this app server')
            ->assertDontSee('Download sponsor helper');
    }

    public function test_isp_user_and_sponsor_assignments_are_workspace_validated_and_saved(): void
    {
        $isp = $this->workspace->isps()->create(['name' => 'ExampleISP']);
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor('Permitted Sponsor');

        $this->get(route('clean.console.infrastructure.isps.users', $isp->getKey()))
            ->assertOk()->assertSee('Allowed Mailers')->assertDontSee('does not yet have the DMS one-ISP-per-list data model');
        $this->get(route('clean.console.infrastructure.isps.sponsors', $isp->getKey()))
            ->assertOk()->assertSee('Allowed Sponsors');

        $this->putJson(route('console.infrastructure.isps.users.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'users' => [$operator->getKey()],
        ])->assertOk()->assertExactJson(['data' => 'Updated successfully']);
        $this->putJson(route('console.infrastructure.isps.sponsors.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'sponsors' => [$sponsor->getKey()],
        ])->assertOk()->assertExactJson(['data' => 'Updated successfully']);

        $this->assertSame([(int) $operator->getKey()], $isp->fresh()->allowed_users);
        $this->assertSame([(int) $sponsor->getKey()], $isp->fresh()->allowed_sponsors);
        $this->assertSame(2, AuditEvent::query()->where('action', 'like', 'isp.allowed_%')->count());

        $otherOperator = User::factory()->create();
        $this->workspace->users()->attach($otherOperator->getKey(), ['role' => 'operator']);
        $this->putJson(route('console.infrastructure.isps.users.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'users' => [$otherOperator->getKey(), 999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('users.1');
        $this->assertSame([(int) $operator->getKey()], $isp->fresh()->allowed_users);

        $otherWorkspace = $this->createOtherWorkspace();
        $otherSponsor = $otherWorkspace->sponsors()->create([
            'name' => 'Other Workspace Sponsor',
            'affiliate_id' => null,
            'url' => 'https://other.example.test',
            'username' => 'other-user',
            'password' => 'other-pass',
            'api_key' => 'other-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $this->putJson(route('console.infrastructure.isps.sponsors.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'sponsors' => [$otherSponsor->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('sponsors.0');
    }

    public function test_sponsor_assignments_match_dms_mailer_identity_order_and_bulk_defaults(): void
    {
        $firstOperator = User::factory()->create([
            'name' => 'Zeta Mailer',
            'username' => 'zeta-mailer',
            'email' => 'zeta@example.test',
        ]);
        $secondOperator = User::factory()->create([
            'name' => 'Alpha Mailer',
            'username' => 'alpha-mailer',
            'email' => 'alpha@example.test',
        ]);
        $this->workspace->users()->attach([
            $firstOperator->getKey() => ['role' => 'operator'],
            $secondOperator->getKey() => ['role' => 'operator'],
        ]);
        $single = $this->createSponsor('Single Network', [
            'allowed_users' => [(int) $firstOperator->getKey()],
        ]);
        $bulkFirst = $this->createSponsor('Bulk One', [
            'allowed_users' => [(int) $firstOperator->getKey(), (int) $secondOperator->getKey()],
        ]);
        $bulkSecond = $this->createSponsor('Bulk Two', [
            'allowed_users' => [(int) $firstOperator->getKey(), (int) $secondOperator->getKey()],
        ]);

        $singleResponse = $this->get(route('clean.console.sponsors.users', $single->getKey()))
            ->assertOk()
            ->assertSee('zeta-mailer | Zeta Mailer')
            ->assertSee('alpha-mailer | Alpha Mailer')
            ->assertSee('id="allowed-user-'.$firstOperator->getKey().'" name="checked_users" checked', false)
            ->assertDontSee('zeta@example.test')
            ->assertDontSee('alpha@example.test');
        $this->assertLessThan(
            strpos($singleResponse->getContent(), 'alpha-mailer | Alpha Mailer'),
            strpos($singleResponse->getContent(), 'zeta-mailer | Zeta Mailer'),
        );

        $bulkResponse = $this->get(route('clean.console.sponsors.bulk-users').'?'.http_build_query([
            'ids' => [$bulkFirst->getKey(), $bulkSecond->getKey()],
        ]))->assertOk()
            ->assertSee('Allowed Mailers for 2 Sponsors')
            ->assertDontSee('id="allowed-user-'.$firstOperator->getKey().'" name="checked_users" checked', false)
            ->assertDontSee('id="allowed-user-'.$secondOperator->getKey().'" name="checked_users" checked', false)
            ->assertDontSee('zeta@example.test')
            ->assertDontSee('alpha@example.test');
    }

    public function test_empty_sponsor_assignment_pages_keep_the_dms_checkbox_grid_and_controls(): void
    {
        $first = $this->createSponsor('Empty Single');
        $second = $this->createSponsor('Empty Bulk');

        $this->get(route('clean.console.sponsors.users', $first->getKey()))
            ->assertOk()
            ->assertSee('Allowed Mailers:')
            ->assertSee('id="toggle-all"', false)
            ->assertSee('CHECK')
            ->assertSee('<div class="row">', false)
            ->assertSee('UPDATE ALLOWED USERS')
            ->assertDontSee('There are no operator members');

        $this->get(route('clean.console.sponsors.bulk-users').'?'.http_build_query([
            'ids' => [$first->getKey(), $second->getKey()],
        ]))
            ->assertOk()
            ->assertSee('Allowed Mailers for 2 Sponsors')
            ->assertSee('id="toggle-all"', false)
            ->assertSee('<div class="row">', false)
            ->assertSee('UPDATE ALLOWED USERS')
            ->assertDontSee('There are no operator members');
    }

    public function test_single_sponsor_allowed_users_cascade_to_all_related_offers(): void
    {
        $previousOperator = User::factory()->create();
        $newOperator = User::factory()->create();
        $this->workspace->users()->attach($previousOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($newOperator->getKey(), ['role' => 'operator']);
        $sponsor = $this->createSponsor('Single Assignment Network', [
            'allowed_users' => [$previousOperator->getKey()],
        ]);
        $liveOffer = $this->createOffer($sponsor, 'single-live', [
            'allowed_users' => [$previousOperator->getKey()],
            'is_active' => true,
        ]);
        $inactiveOffer = $this->createOffer($sponsor, 'single-inactive', [
            'allowed_users' => [$previousOperator->getKey()],
            'is_active' => false,
        ]);

        $this->putJson(route('console.sponsors.users.update', [$this->workspace->getKey(), $sponsor->getKey()]), [
            'users' => [$newOperator->getKey()],
        ])->assertOk()->assertExactJson(['data' => 'Updated successfully']);

        $this->assertSame([(int) $newOperator->getKey()], $sponsor->fresh()->allowed_users);
        $this->assertSame([(int) $newOperator->getKey()], $liveOffer->fresh()->allowed_users);
        $this->assertSame([(int) $newOperator->getKey()], $inactiveOffer->fresh()->allowed_users);
    }

    public function test_sponsor_bulk_allowed_user_action_updates_selected_records_only(): void
    {
        $previousOperator = User::factory()->create();
        $operator = User::factory()->create();
        $this->workspace->users()->attach($previousOperator->getKey(), ['role' => 'operator']);
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $previousAssignment = [(int) $previousOperator->getKey()];
        $first = $this->createSponsor('First Network', ['allowed_users' => $previousAssignment]);
        $second = $this->createSponsor('Second Network', ['allowed_users' => $previousAssignment]);
        $unselected = $this->createSponsor('Unselected Network', ['allowed_users' => $previousAssignment]);
        $firstOffer = $this->createOffer($first, 'first-offer', ['allowed_users' => $previousAssignment]);
        $secondOffer = $this->createOffer($second, 'second-offer', ['allowed_users' => $previousAssignment]);
        $unselectedOffer = $this->createOffer($unselected, 'unselected-offer', ['allowed_users' => $previousAssignment]);

        $bulkUrl = route('clean.console.sponsors.bulk-users');
        $this->get($bulkUrl.'?'.http_build_query(['ids' => [$first->getKey(), $second->getKey()]]))
            ->assertOk()->assertSee('Allowed Mailers for 2 Sponsors');
        $this->putJson(route('console.sponsors.bulk-users.update', $this->workspace->getKey()), [
            'sponsors' => [$first->getKey(), $second->getKey()],
            'users' => [$operator->getKey()],
        ])->assertOk()->assertExactJson(['data' => 'Updated successfully']);

        $this->assertSame([(int) $operator->getKey()], $first->fresh()->allowed_users);
        $this->assertSame([(int) $operator->getKey()], $second->fresh()->allowed_users);
        $this->assertSame($previousAssignment, $firstOffer->fresh()->allowed_users);
        $this->assertSame($previousAssignment, $secondOffer->fresh()->allowed_users);
        $this->assertSame($previousAssignment, $unselected->fresh()->allowed_users);
        $this->assertSame($previousAssignment, $unselectedOffer->fresh()->allowed_users);
    }

    private function createSponsor(string $name, array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => $name,
            'affiliate_id' => null,
            'url' => 'https://'.Str::slug($name).'.example.test',
            'username' => 'local-user-'.Str::lower(Str::random(6)),
            'password' => 'local-pass-'.Str::random(8),
            'api_key' => 'local-key-'.Str::random(16),
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function createOffer(WorkspaceSponsor $sponsor, string $offerId, array $overrides = []): WorkspaceOffer
    {
        return $sponsor->offers()->create(array_merge([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $offerId,
            'name' => 'Offer '.$offerId,
            'type' => 'CPA',
            'vertical' => 'testing',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offers.example.test/'.$offerId,
            'unsubscribe' => null,
            'note' => null,
            'allowed_days' => ['Mon'],
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function createOtherWorkspace(): Workspace
    {
        $other = Workspace::query()->create([
            'name' => 'Other sponsor workspace',
            'slug' => 'other-sponsor-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $other->users()->attach($this->owner->getKey(), ['role' => 'owner']);

        return $other;
    }
}
