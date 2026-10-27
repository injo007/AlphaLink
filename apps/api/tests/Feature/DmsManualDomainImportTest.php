<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsManualDomainImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private DomainProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'mailer']);
        $this->workspace = Workspace::query()->create([
            'name' => 'Domain import test',
            'slug' => 'domain-import-test',
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->provider = DomainProvider::query()->create(['name' => 'Synthetic provider', 'is_active' => true]);
    }

    public function test_manual_import_creates_only_new_provider_backed_dms_install_domains(): void
    {
        $existing = $this->workspace->sendingDomains()->create([
            'name' => 'existing.example',
            'state' => 'pending',
            'verification_token' => 'synthetic-token',
            'created_by' => $this->owner->getKey(),
        ]);

        $this->actingAs($this->owner)->postJson($this->route(), [
            'provider' => $this->provider->getKey(),
            'domains' => [' new.example ', 'new.example', 'existing.example', 'second.example'],
        ])->assertOk()->assertExactJson(['data' => '2 Domain Created']);

        $this->assertSame(3, $this->workspace->sendingDomains()->count());
        $this->assertNull($existing->fresh()->domains_provider_id);

        $created = $this->workspace->sendingDomains()->where('name', 'new.example')->firstOrFail();
        $this->assertSame($this->provider->getKey(), $created->domains_provider_id);
        $this->assertSame('new', $created->status);
        $this->assertFalse($created->is_our);
        $this->assertTrue($created->is_active);
        $this->assertSame('pending', $created->state);
        $this->assertSame(['new.example', 'second.example'], $this->eligibleDomainNames());
        ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_MAILER_RELAY',
            'provider' => 'Synthetic server provider',
            'ip_address' => '198.51.100.20',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->get(route('console.infrastructure.bulk-install', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('new.example | STATUS: new | IS OUR: NO | LISTED: SPAMHAUS = NO - HOSTKARMA = NO');
    }

    public function test_inactive_provider_is_accepted_by_dms_store_but_its_domain_is_not_installable(): void
    {
        $this->provider->update(['is_active' => false]);

        $this->actingAs($this->owner)->postJson($this->route(), [
            'provider' => $this->provider->getKey(),
            'domains' => ['inactive.example'],
        ])->assertOk()->assertExactJson(['data' => '1 Domain Created']);

        $this->assertSame([], $this->eligibleDomainNames());
    }

    public function test_manual_import_rejects_an_invalid_install_domain_before_saving_any_rows(): void
    {
        $this->actingAs($this->owner)->postJson($this->route(), [
            'provider' => $this->provider->getKey(),
            'domains' => ['valid.example', '   '],
        ])->assertUnprocessable()->assertJsonValidationErrors('domains.1');

        $this->assertSame(0, SendingDomain::query()->count());
    }

    public function test_manual_import_requires_owner_or_admin_and_an_existing_provider(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $payload = ['provider' => $this->provider->getKey(), 'domains' => ['example.net']];

        $this->actingAs($operator)->postJson($this->route(), $payload)->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => 99999, 'domains' => ['example.net']])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => '1.5', 'domains' => ['example.net']])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->route(), ['provider' => 'bad', 'domains' => 'example.net'])->assertUnprocessable();
        $this->assertSame(0, SendingDomain::query()->count());
    }

    private function route(): string
    {
        return route('console.infrastructure.domains.manual', $this->workspace->getKey());
    }

    /** @return list<string> */
    private function eligibleDomainNames(): array
    {
        $domains = app(DmsBulkInstallEligibility::class)->for($this->workspace)['domains'];

        return $domains->pluck('label')->map(fn (string $label): string => explode(' | ', $label)[0])->sort()->values()->all();
    }
}
