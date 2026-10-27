<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallIspCatalog;
use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class IspInstallationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'ISP install test',
            'slug' => 'isp-install-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_add_isp_form_reproduces_the_dms_fields_and_reference_country_catalog(): void
    {
        $this->get(route('console.infrastructure.isps.create', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('ADD ISP')
            ->assertSee('Name')
            ->assertSee('Tracked Countries:')
            ->assertSee('Quality Emails:')
            ->assertSee('Enter Isp Name..')
            ->assertSee('Enter Quality Emails ...')
            ->assertSee('TRACKED COUNTRIES')
            ->assertSee('id="tracked_countries" class="form-control" multiple', false)
            ->assertSee('vendor/select2/4.0.13/select2.min.js', false)
            ->assertSee('closeOnSelect: false', false)
            ->assertSee('United States')
            ->assertSee('SAVE');
    }

    public function test_isp_creation_matches_dms_validation_and_persists_the_install_inputs(): void
    {
        $url = route('console.infrastructure.isps.store', $this->workspace->getKey());
        $this->postJson($url, [
            'name' => 'Mail-ISP_01',
            'tracked_countries' => ['US', 'BF'],
            'quality_emails' => ['seed@example.test', 'seed@example.test'],
        ])->assertOk()->assertExactJson(['data' => 'Isp created']);

        $isp = $this->workspace->isps()->sole();
        $this->assertSame('Mail-ISP_01', $isp->name);
        $this->assertSame(['US', 'BF'], $isp->tracked_countries);
        $this->assertSame(['seed@example.test', 'seed@example.test'], $isp->quality_emails);
        $this->assertTrue($isp->is_active);
        $this->assertSame([], $isp->allowed_users);
        $this->assertSame([], $isp->allowed_sponsors);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'isp.created',
            'subject_id' => (string) $isp->getKey(),
        ]);

        $this->postJson($url, ['name' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($url, ['name' => 'valid_name', 'tracked_countries' => [], 'quality_emails' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['tracked_countries', 'quality_emails']);
    }

    public function test_vmta_catalog_includes_inactive_isps_lowercases_names_and_deduplicates_them(): void
    {
        $this->workspace->isps()->create(['name' => 'Gmail', 'is_active' => true]);
        $this->workspace->isps()->create(['name' => 'GMAIL', 'is_active' => false]);
        $this->workspace->isps()->create(['name' => 'Yahoo', 'is_active' => false]);

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Another workspace',
            'slug' => 'another-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $otherWorkspace->isps()->create(['name' => 'Outlook', 'is_active' => true]);

        $names = app(DmsBulkInstallIspCatalog::class)->vmtaNames($this->workspace);
        $this->assertSame(['gmail', 'yahoo'], $names);

        $files = app(DmsBulkInstallVmtaPlanner::class)->files('centos9', false, [[
            'ip' => '192.0.2.44',
            'sub' => '',
            'domain' => 'example.test',
        ]], $names, 'selector-one', false, false);
        $this->assertStringContainsString('<virtual-mta mta-192.0.2.44-yahoo>', $files['vmtas']);
        $this->assertSame(1, substr_count($files['vmtas'], '<virtual-mta mta-192.0.2.44-gmail>'));
        $this->assertStringNotContainsString('outlook', $files['vmtas']);
    }

    public function test_isp_updates_and_status_changes_are_workspace_scoped_and_preserve_dms_vmta_behavior(): void
    {
        $isp = $this->workspace->isps()->create([
            'name' => 'OldISP',
            'is_active' => false,
            'tracked_countries' => ['CA'],
            'quality_emails' => ['old@example.test'],
        ]);
        $editUrl = route('console.infrastructure.isps.update', [$this->workspace->getKey(), $isp->getKey()]);
        $this->get(route('console.infrastructure.isps.edit', [$this->workspace->getKey(), $isp->getKey()]))
            ->assertOk()
            ->assertSee('<option value="CA" selected>Canada</option>', false);

        $this->putJson($editUrl, [
            'name' => 'RenamedISP',
            'tracked_countries' => null,
            'quality_emails' => ['new@example.test'],
        ])->assertOk()->assertExactJson(['data' => 'Isp updated']);
        $this->assertFalse($isp->fresh()->is_active);
        $this->assertSame([], $isp->fresh()->tracked_countries);
        $this->assertSame(['renamedisp'], app(DmsBulkInstallIspCatalog::class)->vmtaNames($this->workspace));

        $this->patchJson(route('console.infrastructure.isps.status', [$this->workspace->getKey(), $isp->getKey()]), ['status' => true])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertTrue($isp->fresh()->is_active);

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Unrelated workspace',
            'slug' => 'unrelated-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->get(route('console.infrastructure.isps.edit', [$otherWorkspace->getKey(), $isp->getKey()]))->assertNotFound();
        $this->putJson(route('console.infrastructure.isps.update', [$otherWorkspace->getKey(), $isp->getKey()]), ['name' => 'Changed'])
            ->assertNotFound();

        $this->assertSame(2, AuditEvent::query()->where('action', 'like', 'isp.%')->count());
    }

    public function test_isp_catalog_is_owner_admin_only_and_index_data_is_limited_to_the_workspace(): void
    {
        $this->workspace->isps()->create(['name' => 'WorkspaceISP']);
        $this->get(route('console.infrastructure.isps.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('id="isps"', false)
            ->assertSee('TOTAL LISTS')
            ->assertSee('ALLOWED USERS')
            ->assertSee('REFRESH')
            ->assertSee('ADD NEW ISP');
        $this->getJson(route('console.infrastructure.isps.index', $this->workspace->getKey()))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'WorkspaceISP');

        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->actingAs($operator)
            ->get(route('console.infrastructure.isps.index', $this->workspace->getKey()))
            ->assertForbidden();
    }

    public function test_empty_isp_assignment_pages_keep_the_dms_empty_checkbox_grid(): void
    {
        $isp = $this->workspace->isps()->create(['name' => 'EmptyISP']);

        $this->get(route('console.infrastructure.isps.users', [$this->workspace->getKey(), $isp->getKey()]))
            ->assertOk()
            ->assertSee('Allowed Mailers:')
            ->assertSee('id="toggle-all"', false)
            ->assertSee('CHECK')
            ->assertSee('id="update-assignment"', false)
            ->assertSee('UPDATE ALLOWED MAILERS')
            ->assertSee('<div class="row">', false)
            ->assertSee('type="module"', false)
            ->assertSee('import { applyDmsCheckAll } from ', false)
            ->assertSee('applyDmsCheckAll', false)
            ->assertSee('isp-assignment.js', false)
            ->assertDontSee('There are no operator members');

        $this->get(route('console.infrastructure.isps.sponsors', [$this->workspace->getKey(), $isp->getKey()]))
            ->assertOk()
            ->assertSee('Allowed Sponsors:')
            ->assertSee('id="toggle-all"', false)
            ->assertSee('CHECK')
            ->assertSee('id="update-assignment"', false)
            ->assertSee('UPDATE ALLOWED SPONSORS')
            ->assertSee('<div class="row">', false)
            ->assertDontSee('There are no active AlphaLink sponsors');
    }

    public function test_isp_allowed_mailers_show_dms_identity_fields_without_exposing_contact_email(): void
    {
        $isp = $this->workspace->isps()->create(['name' => 'MailerISP']);
        $firstOperator = User::factory()->create([
            'name' => 'Zeta Operator',
            'username' => 'zeta-operator',
            'email' => 'zeta@example.test',
        ]);
        $secondOperator = User::factory()->create([
            'name' => 'Alpha Operator',
            'username' => 'alpha-operator',
            'email' => 'operator@example.test',
        ]);
        $this->workspace->users()->attach([
            $firstOperator->getKey() => ['role' => 'operator'],
            $secondOperator->getKey() => ['role' => 'operator'],
        ]);

        $response = $this->get(route('console.infrastructure.isps.users', [$this->workspace->getKey(), $isp->getKey()]))
            ->assertOk()
            ->assertSee('zeta-operator | Zeta Operator')
            ->assertSee('alpha-operator | Alpha Operator')
            ->assertDontSee('zeta@example.test')
            ->assertDontSee('operator@example.test');

        $this->assertLessThan(
            strpos($response->getContent(), 'alpha-operator | Alpha Operator'),
            strpos($response->getContent(), 'zeta-operator | Zeta Operator'),
        );
    }

    public function test_isp_allowed_access_changes_cascade_to_its_workspace_lists(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $isp = $this->workspace->isps()->create([
            'name' => 'LinkedISP',
            'tracked_countries' => ['US'],
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);
        $sponsor = $this->workspace->sponsors()->create([
            'name' => 'Linked Sponsor',
            'url' => 'https://sponsor.example.test',
            'username' => 'test-user',
            'password' => 'test-password',
            'api_key' => 'test-api-key',
            'type' => 'cake',
            'is_active' => true,
        ]);
        $list = $this->workspace->audienceLists()->create([
            'created_by' => $this->owner->getKey(),
            'name' => 'ISP target list',
            'slug' => 'isp-target-list',
            'description' => null,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'ISP target list'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);

        $this->getJson(route('console.infrastructure.isps.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertJsonPath('data.0.lists_count', 1);

        $this->putJson(route('console.infrastructure.isps.users.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'users' => [$operator->getKey()],
        ])->assertOk();
        $this->assertSame([(int) $operator->getKey()], $isp->fresh()->allowed_users);
        $this->assertSame([(int) $operator->getKey()], $list->fresh()->allowed_users);

        $this->putJson(route('console.infrastructure.isps.sponsors.update', [$this->workspace->getKey(), $isp->getKey()]), [
            'sponsors' => [$sponsor->getKey()],
        ])->assertOk();
        $this->assertSame([(int) $sponsor->getKey()], $isp->fresh()->allowed_sponsors);
        $this->assertSame([(int) $sponsor->getKey()], $list->fresh()->allowed_sponsors);

        $properties = AuditEvent::query()->where('action', 'isp.allowed_users_updated')->sole()->properties;
        $this->assertSame(1, $properties['lists_updated']);
    }
}
