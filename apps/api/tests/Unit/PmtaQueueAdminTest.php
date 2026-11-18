<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Campaigns\PmtaQueueAdmin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PmtaQueueAdminTest extends TestCase
{
    public function test_readiness_uses_the_proxy_and_only_runs_show_status_over_http(): void
    {
        Http::fake([
            'http://192.0.2.40:1213/command*' => Http::response('<response><succ>PMTA status</succ></response>', 200),
        ]);
        $server = (new ManagedServer)->forceFill([
            'id' => 'server-one',
            'ip_address' => '192.0.2.40',
        ]);

        (new PmtaQueueAdmin)->verifyReadOnlyStatusAccess($server, 1213, 'http://relay-user:relay-password@198.51.100.77:8977');

        Http::assertSent(function (Request $request): bool {
            $query = parse_url($request->url(), PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $parameters);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/command'
                && $parameters === ['format' => 'xml', 'command' => 'show status'];
        });
    }

    public function test_readiness_fails_when_pmta_denies_the_command_route(): void
    {
        Http::fake([
            'http://192.0.2.40:1213/command*' => Http::response('403', 403),
        ]);
        $server = (new ManagedServer)->forceFill([
            'id' => 'server-one',
            'ip_address' => '192.0.2.40',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PMTA denied the command with HTTP 403. Verify that the configured relay proxy IP is allowed for /command and that PMTA 5.0 has http-csrf-protection false.');

        (new PmtaQueueAdmin)->verifyReadOnlyStatusAccess($server, 1213, 'http://198.51.100.77:8977');
    }
}
