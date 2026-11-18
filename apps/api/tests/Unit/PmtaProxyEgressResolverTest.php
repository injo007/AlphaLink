<?php

namespace Tests\Unit;

use App\Services\Infrastructure\PmtaProxyEgressResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PmtaProxyEgressResolverTest extends TestCase
{
    public function test_it_returns_public_addresses_seen_through_the_configured_http_proxy(): void
    {
        Http::fake([
            'http://api4.ipify.org' => Http::response("1.1.1.1\n"),
            'http://checkip.amazonaws.com' => Http::response('1.1.1.1'),
            'http://ipv4.icanhazip.com' => Http::response('9.9.9.9'),
            'http://v4.ident.me' => Http::response('127.0.0.1'),
        ]);

        $proxy = [
            'host' => '37.27.184.206',
            'port' => 8977,
            'username' => 'relay-user',
            'password' => 'relay-password',
            'url' => 'http://relay-user:relay-password@37.27.184.206:8977',
        ];

        $this->assertSame(['1.1.1.1', '9.9.9.9'], app(PmtaProxyEgressResolver::class)->resolve($proxy));
        Http::assertSentCount(4);
    }

    public function test_it_requires_two_successful_public_address_observations(): void
    {
        Http::fake([
            'http://api4.ipify.org' => Http::response('1.1.1.1'),
            'http://checkip.amazonaws.com' => Http::response('192.168.1.1'),
            'http://ipv4.icanhazip.com' => Http::response('', 503),
            'http://v4.ident.me' => Http::response('invalid'),
        ]);

        $this->assertSame([], app(PmtaProxyEgressResolver::class)->resolve([
            'host' => '37.27.184.206',
            'port' => 8977,
            'username' => 'relay-user',
            'password' => 'relay-password',
            'url' => 'http://relay-user:relay-password@37.27.184.206:8977',
        ]));
    }
}
