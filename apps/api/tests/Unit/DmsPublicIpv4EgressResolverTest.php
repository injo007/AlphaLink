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
            'https://api.ipify.org' => Http::response("1.1.1.1\n"),
            'https://checkip.amazonaws.com' => Http::response('1.1.1.1'),
            'https://ipv4.icanhazip.com' => Http::response('1.1.1.1'),
        ]);

        $this->assertSame(['1.1.1.1'], app(DmsPublicIpv4EgressResolver::class)->resolve());
    }

    public function test_it_rejects_disagreeing_or_non_public_egress_observations(): void
    {
        Http::fake([
            'https://api.ipify.org' => Http::response('1.1.1.1'),
            'https://checkip.amazonaws.com' => Http::response('9.9.9.9'),
            'https://ipv4.icanhazip.com' => Http::response('127.0.0.1'),
        ]);

        $this->assertSame([], app(DmsPublicIpv4EgressResolver::class)->resolve());
    }
}
