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

    public function test_godaddy_replaces_only_requested_rrsets_and_preserves_unrelated_txt(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = [$request->method(), $request->url(), $request->data()];

            if ($request->method() === 'GET') {
                return Http::response([
                    ['data' => 'v=spf1 include:old.example -all', 'ttl' => 600],
                    ['data' => 'google-site-verification=keep-me', 'ttl' => 3600],
                ], 200);
            }

            return Http::response(null, 204);
        });
        [$domain] = $this->ownedDomain('godaddy', ['api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret']);

        $result = app(DmsRegistrarChangeDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => '@', 'address' => 'v=spf1 ip4:203.0.113.12 -all', 'type' => 'TXT', 'ttl' => '300',
        ], [
            'host' => '@', 'address' => 'mx.example.com', 'type' => 'MX', 'ttl' => '60',
        ]]);

        self::assertTrue($result);
        self::assertCount(3, $requests);
        self::assertSame('GET', $requests[0][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/example.co.uk/records/TXT/%40', $requests[0][1]);
        self::assertSame('PUT', $requests[1][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/example.co.uk/records/TXT/%40', $requests[1][1]);
        self::assertSame([
            ['data' => 'v=spf1 ip4:203.0.113.12 -all', 'ttl' => 600],
            ['data' => 'google-site-verification=keep-me', 'ttl' => 3600],
        ], $requests[1][2]);
        self::assertSame('PUT', $requests[2][0]);
        self::assertSame('https://api.godaddy.com/v1/domains/example.co.uk/records/MX/%40', $requests[2][1]);
        self::assertSame([
            ['data' => 'mx.example.com', 'ttl' => 600, 'priority' => 10],
        ], $requests[2][2]);
    }

    public function test_cloudflare_install_dns_uses_zone_batch_and_keeps_unrelated_records(): void
    {
        $zoneId = str_repeat('a', 32);
        $dnsRecords = [[
            'id' => str_repeat('d', 32), 'type' => 'A', 'name' => 'unrelated.example.co.uk',
            'content' => '198.51.100.4', 'ttl' => 300, 'proxied' => false,
        ], [
            'id' => str_repeat('e', 32), 'type' => 'TXT', 'name' => 'spf.example.co.uk',
            'content' => '"v=spf1 include:old.example -all"', 'ttl' => 300,
        ], [
            'id' => str_repeat('f', 32), 'type' => 'TXT', 'name' => 'spf.example.co.uk',
            'content' => '"google-site-verification=keep-me"', 'ttl' => 3600,
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
                foreach ($request->data()['patches'] ?? [] as $record) {
                    foreach ($dnsRecords as $index => $current) {
                        if (($current['id'] ?? null) === ($record['id'] ?? null)) {
                            $dnsRecords[$index] = [...$current, ...$record];
                        }
                    }
                }
                foreach ($request->data()['deletes'] ?? [] as $record) {
                    $dnsRecords = array_values(array_filter($dnsRecords, static fn (array $current): bool => ($current['id'] ?? null) !== ($record['id'] ?? null)));
                }
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
                'patches' => [[
                    'id' => str_repeat('e', 32), 'type' => 'TXT', 'name' => 'spf.example.co.uk',
                    'content' => '"v=spf1 ip4:203.0.113.12 -all"', 'ttl' => 300,
                ]],
                'posts' => [
                    ['type' => 'A', 'name' => 'example.co.uk', 'content' => '203.0.113.12', 'ttl' => 300],
                    ['type' => 'AAAA', 'name' => 'example.co.uk', 'content' => '2001:41d0:601:2000::e65', 'ttl' => 300],
                ],
            ]);
        self::assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/dns_records')));
        self::assertSame($provider->getKey(), $domain->fresh()->domains_provider_id);
    }

    public function test_cloudflare_quotes_and_chunks_long_txt_values_at_rfc1035_boundaries(): void
    {
        $zoneId = str_repeat('a', 32);
        $dnsRecords = [];
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
                    $dnsRecords[] = [...$record, 'id' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT)];
                }

                return Http::response(['success' => true, 'result' => ['posts' => $request->data()['posts'] ?? []]]);
            }

            return Http::response(['success' => true, 'result' => $dnsRecords, 'result_info' => ['total_pages' => 1]]);
        });
        [$domain] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);
        $domain->forceFill(['provider_domain_id' => $zoneId])->save();
        $longDkim = 'v=DKIM1; p='.str_repeat('A', 270);

        self::assertTrue(app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => 'selector._domainkey', 'address' => $longDkim, 'type' => 'TXT', 'ttl' => '300',
        ]]));

        $expectedContent = '"'.substr($longDkim, 0, 255).'" "'.substr($longDkim, 255).'"';
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/dns_records/batch')
            && $request->data()['posts'][0]['content'] === $expectedContent);
    }

    public function test_cloudflare_splits_a_prequoted_long_txt_value_into_valid_character_strings(): void
    {
        $zoneId = str_repeat('a', 32);
        $dnsRecords = [];
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
                    $dnsRecords[] = [...$record, 'id' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT)];
                }

                return Http::response(['success' => true, 'result' => ['posts' => $request->data()['posts'] ?? []]]);
            }

            return Http::response(['success' => true, 'result' => $dnsRecords, 'result_info' => ['total_pages' => 1]]);
        });
        [$domain] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);
        $domain->forceFill(['provider_domain_id' => $zoneId])->save();
        $longDkim = 'v=DKIM1; p='.str_repeat('A', 270);

        self::assertTrue(app(DmsBulkInstallDomainDnsRecordWriter::class)->setHosts($domain, [[
            'host' => 'selector._domainkey', 'address' => '"'.$longDkim.'"', 'type' => 'TXT', 'ttl' => '300',
        ]]));

        $expectedContent = '"'.substr($longDkim, 0, 255).'" "'.substr($longDkim, 255).'"';
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/dns_records/batch')
            && ($request->data()['posts'][0]['content'] ?? null) === $expectedContent);
    }

    public function test_domain_availability_rejects_a_cloudflare_provider_without_an_exact_zone(): void
    {
        Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => str_repeat('b', 32), 'name' => 'different.example.co.uk']],
            'result_info' => ['total_pages' => 1],
        ])]);
        [$domain] = $this->ownedDomain('cloudflare', ['api_key' => 'synthetic-cloudflare-token']);

        self::assertFalse(app(DmsBulkInstallDomainDnsRecordWriter::class)->isDomainAvailable($domain));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.cloudflare.com/client/v4/zones?')
            && $request->hasHeader('Authorization', 'Bearer synthetic-cloudflare-token'));
    }

    public function test_domain_availability_checks_namecheap_domain_ownership(): void
    {
        Http::fake(['https://api.namecheap.com/xml.response' => Http::response(
            '<ApiResponse Status="OK"><CommandResponse><DomainGetInfoResult ID="123"><DomainName>example.co.uk</DomainName></DomainGetInfoResult></CommandResponse></ApiResponse>',
            200,
        )]);
        [$domain] = $this->ownedDomain('namecheap', ['username' => 'dms-user', 'api_key' => 'synthetic-key']);

        self::assertTrue(app(DmsBulkInstallDomainDnsRecordWriter::class)->isDomainAvailable($domain));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.namecheap.com/xml.response'
            && $request['Command'] === 'namecheap.domains.getInfo'
            && $request['SLD'] === 'example'
            && $request['TLD'] === 'co.uk');
    }

    public function test_domain_availability_checks_godaddy_registered_domain(): void
    {
        Http::fake(['https://api.godaddy.com/v1/domains/example.co.uk' => Http::response([], 200)]);
        [$domain] = $this->ownedDomain('godaddy', ['api_key' => 'synthetic-key', 'api_secret' => 'synthetic-secret']);

        self::assertTrue(app(DmsBulkInstallDomainDnsRecordWriter::class)->isDomainAvailable($domain));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.godaddy.com/v1/domains/example.co.uk'
            && $request->hasHeader('Authorization', 'sso-key synthetic-key:synthetic-secret'));
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
