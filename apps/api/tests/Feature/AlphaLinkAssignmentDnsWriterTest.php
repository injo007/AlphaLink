<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Services\Infrastructure\AlphaLinkRegistrarChangeDomainDnsRecordWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlphaLinkAssignmentDnsWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_subdomain_assignment_records_are_written_in_its_parent_zone_without_deletes(): void
    {
        $zoneId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $provider = DomainProvider::query()->create([
            'name' => 'Cloudflare', 'type' => 'cloudflare', 'api_key' => 'test-api-token-12345678901234567890', 'is_active' => true,
        ]);
        $domain = new SendingDomain([
            'name' => 'mail.example.com', 'domains_provider_id' => $provider->getKey(), 'provider_domain_id' => $zoneId,
        ]);
        $current = [['type' => 'TXT', 'name' => 'example.com', 'content' => 'site-verification=preserve']];
        $writes = [];
        Http::fake(function ($request) use (&$current, &$writes, $zoneId) {
            if (str_contains($request->url(), '/zones?')) {
                return Http::response(['success' => true, 'result' => [['id' => $zoneId, 'name' => 'example.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $current, 'result_info' => ['total_pages' => 1]]);
            }
            $writes[] = $request->data();
            foreach ($request->data()['posts'] ?? [] as $record) {
                $current[] = $record;
            }

            return Http::response(['success' => true, 'result' => []]);
        });

        $writer = app(AlphaLinkRegistrarChangeDomainDnsRecordWriter::class);
        $this->assertTrue($writer->isAssignmentDomainAvailable($domain));
        $this->assertTrue($writer->setAssignmentHosts($domain, [
            ['host' => '@', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 's1._domainkey', 'address' => 'v=DKIM1; p=ONE', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '_dmarc', 'address' => 'v=DMARC1; p=none', 'type' => 'TXT', 'ttl' => '60'],
        ]));
        $this->assertCount(1, $writes);
        $this->assertArrayNotHasKey('deletes', $writes[0]);
        $this->assertSame(['mail.example.com', 's1._domainkey.mail.example.com', '_dmarc.mail.example.com'], array_column($writes[0]['posts'], 'name'));
        $this->assertSame('site-verification=preserve', $current[0]['content']);
    }
}
