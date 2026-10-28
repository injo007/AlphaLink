<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsPublicIpv4EgressResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DmsPublicIpv4EgressResolverTest extends TestCase
{
    public function test_it_returns_a_public_egress_only_when_independent_checks_agree(): void
    {
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('', 404),
            'https://api4.ipify.org' => Http::response("1.1.1.1\n"),
            'https://checkip.amazonaws.com' => Http::response('1.1.1.1'),
            'https://ipv4.icanhazip.com' => Http::response('1.1.1.1'),
            'https://v4.ident.me' => Http::response('9.9.9.9'),
        ]);

        $this->assertSame(['1.1.1.1'], app(DmsPublicIpv4EgressResolver::class)->resolve());
    }

    public function test_it_prefers_the_digitalocean_metadata_public_address(): void
    {
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response("8.8.8.8\n"),
        ]);

        $this->assertSame(['8.8.8.8'], app(DmsPublicIpv4EgressResolver::class)->resolve());
        Http::assertSentCount(1);
    }

    public function test_it_reads_linode_public_addresses_using_a_short_lived_metadata_token(): void
    {
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('synthetic-metadata-token'),
            'http://169.254.169.254/v1/network' => Http::response([
                'ipv4' => ['public' => ['8.8.8.8/32', '1.1.1.1/32', '10.0.0.1/32']],
            ]),
        ]);

        $this->assertSame(['1.1.1.1', '8.8.8.8'], app(DmsPublicIpv4EgressResolver::class)->resolve());
        Http::assertSent(fn ($request): bool => $request->url() === 'http://169.254.169.254/v1/network'
            && ($request->headers()['Metadata-Token'][0] ?? null) === 'synthetic-metadata-token');
    }

    public function test_it_rejects_disagreeing_or_non_public_egress_observations(): void
    {
        Http::fake([
            'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address' => Http::response('', 404),
            'http://169.254.169.254/v1/token' => Http::response('', 404),
            'https://api4.ipify.org' => Http::response('1.1.1.1'),
            'https://checkip.amazonaws.com' => Http::response('9.9.9.9'),
            'https://ipv4.icanhazip.com' => Http::response('127.0.0.1'),
            'https://v4.ident.me' => Http::response('', 503),
        ]);

        $this->assertSame([], app(DmsPublicIpv4EgressResolver::class)->resolve());
    }
}
