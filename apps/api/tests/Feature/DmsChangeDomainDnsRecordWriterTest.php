<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\CloudflareDnsApiException;
use App\Services\Infrastructure\DmsBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsRegistrarChangeDomainDnsRecordWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsChangeDomainDnsRecordWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_dns_boundary_resolves_to_the_same_source_mapped_registrar_adapter(): void
    {
        self::assertInstanceOf(
            DmsRegistrarChangeDomainDnsRecordWriter::class,
            app(DmsBulkInstallDomainDnsRecordWriter::class),
        );
    }

    public function test_namecheap_replaces_hosts_using_dms_sld_tld_record_parameters(): void
    {
        Http::fake(['https://api.namecheap.com/xml.response' => Http::response(
            '<ApiResponse Status="OK"><CommandResponse><DomainDNSSetHostsResult IsSuccess="true" /></CommandResponse></ApiResponse>',
            200,
        )]);
        [$domain] = $this->ownedDomain('namecheap', ['username' => 'dms-user', 'api_key' => 'synthetic-key']);

        $result = app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => 'relay', 'address' => '203.0.113.12', 'type' => 'A', 'ttl' => '60',
        ], [
            'host' => '@', 'address' => 'v=spf1 ip4:203.0.113.12 -all', 'type' => 'TXT', 'ttl' => '60',
        ]]);

        self::assertTrue($result);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.namecheap.com/xml.response'
            && $request['Command'] === 'namecheap.domains.dns.setHosts'
            && $request['SLD'] === 'example'
            && $request['TLD'] === 'co.uk'
            && $request['HostName0'] === 'relay'
            && $request['RecordType0'] === 'A'
            && $request['TTL0'] === '60'
            && $request['HostName1'] === '@');
    }

    public function test_godaddy_preserves_nameservers_and_applies_dms_minimum_ttl_and_mx_priority(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = [$request->method(), $request->url(), $request->data()];

            return $request->method() === 'GET'
                ? Http::response(['nameServers' => ['ns1.example.net', 'ns2.example.net']], 200)
                : Http::response([], 200);
        });
        [$domain] = $this->ownedDomain('godaddy', ['api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret']);

        $result = app(DmsRegistrarChangeDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => '@', 'address' => 'mx.example.com', 'type' => 'MX', 'ttl' => '60',
        ]]);

        self::assertTrue($result);
        self::assertCount(2, $requests);
        self::assertSame('GET', $requests[0][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/example.co.uk', $requests[0][1]);
        self::assertSame('PUT', $requests[1][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/example.co.uk/records', $requests[1][1]);
        self::assertSame([
            ['name' => '@', 'data' => 'mx.example.com', 'type' => 'MX', 'ttl' => 600, 'priority' => 10],
            ['name' => '@', 'data' => 'ns1.example.net', 'type' => 'NS'],
            ['name' => '@', 'data' => 'ns2.example.net', 'type' => 'NS'],
        ], $requests[1][2]);
    }

    public function test_cloudflare_install_dns_uses_zone_batch_and_keeps_unrelated_records(): void
    {
        $zoneId = str_repeat('a', 32);
        $dnsRecords = [[
            'id' => str_repeat('d', 32), 'type' => 'A', 'name' => 'unrelated.example.co.uk',
            'content' => '198.51.100.4', 'ttl' => 300, 'proxied' => false,
        ]];
        Http::fake(function ($request) use ($zoneId, &$dnsRecords) {
            if (str_contains($request->url(), '/zones?')) {
                return Http::response([
                    'success' => true,
                    'result' => [['id' => $zoneId, 'name' => 'example.co.uk']],
                    'result_info' => ['total_pages' => 1],
                ]);
            }
            if (str_ends_with($request->url(), '/dns_records/batch')) {
                foreach ($request->data()['posts'] ?? [] as $index => $record) {
                    $dnsRecords[] = [
                        ...$record,
                        'id' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                    ];
                }

                return Http::response([
                    'success' => true,
                    'result' => ['posts' => $request->data()['posts'] ?? [], 'patches' => [], 'puts' => [], 'deletes' => []],
                ]);
            }

            return Http::response([
                'success' => true,
                'result' => $dnsRecords,
                'result_info' => ['total_pages' => 1],
            ]);
        });
        [$domain, $provider] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);
        $domain->forceFill(['provider_domain_id' => $zoneId])->save();

        $result = app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => '@', 'address' => '203.0.113.12', 'type' => 'A', 'ttl' => '300',
        ], [
            'host' => '@', 'address' => '2001:41d0:601:2000::e65', 'type' => 'AAAA', 'ttl' => '300',
        ], [
            'host' => 'spf', 'address' => 'v=spf1 ip4:203.0.113.12 -all', 'type' => 'TXT', 'ttl' => '300',
        ]]);

        self::assertTrue($result);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records?page=1&per_page=100'
            && $request->hasHeader('Authorization', 'Bearer synthetic-cloudflare-token'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.$zoneId.'/dns_records/batch'
            && $request->data() === [
                'posts' => [
                    ['type' => 'A', 'name' => 'example.co.uk', 'content' => '203.0.113.12', 'ttl' => 300],
                    ['type' => 'AAAA', 'name' => 'example.co.uk', 'content' => '2001:41d0:601:2000::e65', 'ttl' => 300],
                    ['type' => 'TXT', 'name' => 'spf.example.co.uk', 'content' => 'v=spf1 ip4:203.0.113.12 -all', 'ttl' => 300],
                ],
            ]);
        self::assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/dns_records')));
        self::assertSame($provider->getKey(), $domain->fresh()->domains_provider_id);
    }

    public function test_cloudflare_successful_batch_must_be_visible_in_the_selected_zone(): void
    {
        $zoneId = str_repeat('a', 32);
        Http::fake(function ($request) use ($zoneId) {
            if (str_contains($request->url(), '/zones?')) {
                return Http::response([
                    'success' => true,
                    'result' => [['id' => $zoneId, 'name' => 'example.co.uk']],
                    'result_info' => ['total_pages' => 1],
                ]);
            }
            if (str_ends_with($request->url(), '/dns_records/batch')) {
                return Http::response(['success' => true, 'result' => ['posts' => []]]);
            }

            return Http::response([
                'success' => true,
                'result' => [],
                'result_info' => ['total_pages' => 1],
            ]);
        });
        [$domain] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);
        $domain->forceFill(['provider_domain_id' => $zoneId])->save();

        self::assertFalse(app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => '@', 'address' => '203.0.113.12', 'type' => 'A', 'ttl' => '60',
        ]]));
    }

    public function test_cloudflare_install_rejects_a_saved_zone_that_belongs_to_another_domain(): void
    {
        $zoneId = str_repeat('a', 32);
        Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => $zoneId, 'name' => 'other.example.test']],
            'result_info' => ['total_pages' => 1],
        ])]);
        [$domain] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);
        $domain->forceFill(['provider_domain_id' => $zoneId])->save();

        $this->expectException(CloudflareDnsApiException::class);
        $this->expectExceptionMessage('The selected domain does not have exactly one matching Cloudflare zone.');

        app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => '@', 'address' => '203.0.113.12', 'type' => 'A', 'ttl' => '60',
        ]]);
    }

    public function test_external_domains_use_their_provider_and_missing_provider_fails_closed(): void
    {
        Http::fake(['https://api.namecheap.com/xml.response' => Http::response(
            '<ApiResponse Status="OK"><CommandResponse><DomainDNSSetHostsResult IsSuccess="true" /></CommandResponse></ApiResponse>',
            200,
        )]);
        [$domain] = $this->ownedDomain('namecheap', ['username' => 'dms-user', 'api_key' => 'synthetic-key']);
        $domain->forceFill(['is_our' => false])->save();
        $writer = app(DmsRegistrarChangeDomainDnsRecordWriter::class);

        self::assertTrue($writer->setHosts($domain, []));
        Http::assertSentCount(1);

        $domain->forceFill(['is_our' => true, 'domains_provider_id' => null])->save();
        self::assertFalse($writer->setHosts($domain, []));
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $credentials @return array{SendingDomain, DomainProvider} */
    private function ownedDomain(string $type, array $credentials): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Registrar workspace',
            'slug' => 'registrar-workspace-'.bin2hex(random_bytes(4)),
            'created_by' => $owner->getKey(),
        ]);
        $provider = DomainProvider::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic '.$type,
            'type' => $type,
            'is_active' => true,
            ...$credentials,
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'example.co.uk',
            'state' => 'verified',
            'verification_token' => bin2hex(random_bytes(24)),
            'created_by' => $owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'new',
            'is_our' => true,
            'is_active' => true,
        ]);

        return [$domain, $provider];
    }
}
