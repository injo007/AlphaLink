<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsRecordManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const RECORD_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_cloudflare_records_page_and_create_update_delete_use_zone_scoped_api_calls(): void
    {
        [$owner, $workspace, $domain] = $this->cloudflareDomain();
        Http::fake([
            'api.cloudflare.com/client/v4/zones/'.self::ZONE_ID.'/dns_records*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => self::RECORD_ID, 'type' => 'A', 'name' => 'www.records.example',
                    'content' => '192.0.2.10', 'ttl' => 300, 'proxied' => true,
                ]],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))
            ->assertOk()
            ->assertSee('Cloudflare DNS Records')
            ->assertSee('Add record')
            ->assertSee('Type-specific data')
            ->assertSee('DNS Write');

        $this->postJson($this->storeUrl($workspace, $domain), [
            'type' => 'A', 'name' => 'mail', 'content' => '192.0.2.25', 'ttl' => 300,
            'proxied' => true, 'comment' => 'mail endpoint',
        ])->assertOk()->assertJsonPath('data', 'DNS record created');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.self::ZONE_ID.'/dns_records'
            && $request->hasHeader('Authorization', 'Bearer synthetic-cloudflare-token')
            && $request->data() === [
                'type' => 'A', 'name' => 'mail.records.example', 'ttl' => 300,
                'content' => '192.0.2.25', 'comment' => 'mail endpoint', 'proxied' => true,
            ]);

        $this->patchJson($this->recordUrl($workspace, $domain), [
            'type' => 'MX', 'name' => '@', 'content' => 'mx.records.example', 'ttl' => 1,
            'priority' => 10,
        ])->assertOk()->assertJsonPath('data', 'DNS record updated');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/zones/'.self::ZONE_ID.'/dns_records/'.self::RECORD_ID)
            && $request->data() === [
                'type' => 'MX', 'name' => 'records.example', 'ttl' => 1,
                'content' => 'mx.records.example', 'priority' => 10,
            ]);

        $this->deleteJson($this->recordUrl($workspace, $domain))
            ->assertOk()->assertExactJson(['data' => 'DNS record deleted']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/zones/'.self::ZONE_ID.'/dns_records/'.self::RECORD_ID));

        $this->assertSame(3, AuditEvent::query()->where('subject_id', (string) $domain->getKey())->count());
    }

    public function test_cloudflare_record_validation_blocks_out_of_zone_names_and_invalid_ttl_before_api(): void
    {
        [$owner, $workspace, $domain] = $this->cloudflareDomain();
        Http::fake();
        $url = $this->storeUrl($workspace, $domain);

        $this->actingAs($owner)->postJson($url, [
            'type' => 'A', 'name' => 'outside.invalid', 'content' => '192.0.2.10', 'ttl' => 300,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->postJson($url, [
            'type' => 'TXT', 'name' => '@', 'content' => 'verification', 'ttl' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors(['ttl']);
        $this->postJson($url, [
            'type' => 'MX', 'name' => '@', 'content' => 'mx.records.example', 'ttl' => 300,
        ])->assertUnprocessable()->assertJsonValidationErrors(['priority']);
        $this->postJson($url, [
            'type' => 'CAA', 'name' => '@', 'content' => '', 'data_json' => '[]', 'ttl' => 300,
        ])->assertUnprocessable()->assertJsonValidationErrors(['data_json']);

        Http::assertNothingSent();
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_cloudflare_record_routes_are_workspace_scoped_and_owned_domain_only(): void
    {
        [$owner, $workspace, $domain] = $this->cloudflareDomain(isOur: false);
        $otherOwner = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherOwner, 'cloudflare-other');
        Http::fake();

        $this->actingAs($owner)->get($this->recordsUrl($workspace, $domain))->assertStatus(400);
        $this->postJson($this->storeUrl($workspace, $domain), [
            'type' => 'A', 'name' => '@', 'content' => '192.0.2.11', 'ttl' => 300,
        ])->assertStatus(400);
        $this->actingAs($otherOwner)->deleteJson($this->recordUrl($otherWorkspace, $domain))->assertNotFound();

        Http::assertNothingSent();
    }

    /** @return array{User, Workspace, SendingDomain} */
    private function cloudflareDomain(bool $isOur = true): array
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner, 'cloudflare-workspace');
        $provider = DomainProvider::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic Cloudflare',
            'type' => 'cloudflare',
            'api_key' => 'synthetic-cloudflare-token',
            'is_active' => true,
        ]);
        $domain = $workspace->sendingDomains()->create([
            'name' => 'records.example',
            'state' => 'verified',
            'verification_token' => 'synthetic-verification-token',
            'created_by' => $owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'provider_domain_id' => self::ZONE_ID,
            'status' => 'new',
            'is_our' => $isOur,
            'is_active' => true,
        ]);

        return [$owner, $workspace, $domain];
    }

    private function workspaceFor(User $owner, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug.'-'.bin2hex(random_bytes(4)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function recordsUrl(Workspace $workspace, SendingDomain $domain): string
    {
        return route('console.infrastructure.domains.records', [$workspace->getKey(), $domain->getKey()]);
    }

    private function storeUrl(Workspace $workspace, SendingDomain $domain): string
    {
        return route('console.infrastructure.domains.records.cloudflare.store', [$workspace->getKey(), $domain->getKey()]);
    }

    private function recordUrl(Workspace $workspace, SendingDomain $domain): string
    {
        return route('console.infrastructure.domains.records.cloudflare.update', [$workspace->getKey(), $domain->getKey(), self::RECORD_ID]);
    }
}
