<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Campaigns\PmtaMonitorProxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PmtaMonitorProxyTest extends TestCase
{
    public function test_domain_monitor_retries_through_the_server_ip_with_the_domain_host_after_proxy_connection_failure(): void
    {
        Http::fake([
            'https://pmta.example.test:2447/' => static function (): never {
                throw new ConnectionException('Proxy CONNECT failed with HTTP 403.');
            },
            'http://203.0.113.10:2447/' => Http::response('<html><body><h1>PowerMTA 4.5r11</h1></body></html>'),
        ]);

        $response = app(PmtaMonitorProxy::class)->get(
            $this->server(https: true),
            2447,
            '',
            (string) Str::uuid(),
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'domain',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('PowerMTA 4.5r11', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://203.0.113.10:2447/'
            && $request->hasHeader('Host', 'pmta.example.test'));
    }

    public function test_domain_monitor_retries_by_ip_when_the_domain_returns_an_error_status(): void
    {
        Http::fake([
            'http://pmta.example.test:2447/' => Http::response('Domain target unavailable.', 503),
            'http://203.0.113.10:2447/' => Http::response('<html><body><h1>PowerMTA 4.5r11</h1></body></html>'),
        ]);

        $response = app(PmtaMonitorProxy::class)->get(
            $this->server(),
            2447,
            '',
            (string) Str::uuid(),
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'domain',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('PowerMTA 4.5r11', $response->getContent());
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://203.0.113.10:2447/'
            && $request->hasHeader('Host', 'pmta.example.test'));
    }

    public function test_queue_domain_link_remains_clickable_and_is_rewritten_to_the_authenticated_monitor_route(): void
    {
        Http::fake([
            'http://203.0.113.10:2447/' => Http::response('<html><body><a href="/listqueue?domain=smtp.queue">smtp.queue</a><a href="/command">COMMAND</a><form action="/command"><button>Delete</button></form><script>unsafe()</script></body></html>'),
        ]);
        $server = $this->server();
        $workspaceId = (string) Str::uuid();

        $response = app(PmtaMonitorProxy::class)->get(
            $server,
            2447,
            '',
            $workspaceId,
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'ip',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('listqueue?target=ip&amp;domain=smtp.queue', $response->getContent());
        $this->assertStringContainsString('href="#" aria-disabled="true"', $response->getContent());
        $this->assertStringNotContainsString('<form', $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertSame(0, preg_match('/<a[^>]*href="#"[^>]*>smtp\.queue<\/a>/', $response->getContent()));
    }

    public function test_pmta_domain_detail_queue_link_remains_clickable(): void
    {
        Http::fake([
            'http://203.0.113.10:2447/' => Http::response('<html><body><a href="domainDetail?domain=gmail.com">gmail.com</a></body></html>'),
        ]);

        $response = app(PmtaMonitorProxy::class)->get(
            $this->server(),
            2447,
            '',
            (string) Str::uuid(),
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'ip',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('domainDetail?target=ip&amp;domain=gmail.com', $response->getContent());
        $this->assertSame(0, preg_match('/<a[^>]*href="#"[^>]*>gmail\.com<\/a>/', $response->getContent()));
    }

    public function test_queue_detail_route_forwards_only_a_valid_domain_filter(): void
    {
        Http::fake([
            'http://203.0.113.10:2447/listqueue?domain=smtp.queue' => Http::response('<html><body><h1>Queue smtp.queue</h1></body></html>'),
        ]);

        $response = app(PmtaMonitorProxy::class)->get(
            $this->server(),
            2447,
            'listqueue',
            (string) Str::uuid(),
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'ip',
            ['domain' => 'smtp.queue', 'action' => 'delete'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Queue smtp.queue', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://203.0.113.10:2447/listqueue?domain=smtp.queue');
    }

    public function test_pmta_domain_detail_route_forwards_only_its_valid_domain_filter(): void
    {
        Http::fake([
            'http://203.0.113.10:2447/domainDetail?domain=gmail.com' => Http::response('<html><body><h1>Domain details</h1></body></html>'),
        ]);

        $response = app(PmtaMonitorProxy::class)->get(
            $this->server(),
            2447,
            'domainDetail',
            (string) Str::uuid(),
            '198.51.100.30',
            8977,
            'relay-user',
            'relay-password',
            'ip',
            ['domain' => 'gmail.com', 'action' => 'delete'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Domain details', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://203.0.113.10:2447/domainDetail?domain=gmail.com');
    }

    private function server(bool $https = false): ManagedServer
    {
        $server = new ManagedServer;
        $server->forceFill([
            'id' => (string) Str::uuid(),
            'ip_address' => '203.0.113.10',
            'install_parameters' => [
                'pmta_monitor_https' => $https,
                'data' => [['main' => true, 'domain' => 'pmta.example.test']],
            ],
        ]);

        return $server;
    }
}
