<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InfrastructureServerActionRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_actions_use_server_scoped_limits_and_bulk_install_has_no_shared_throttle(): void
    {
        $owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $installRoute = Route::getRoutes()->getByName('console.infrastructure.install.runs.store');
        $reinstallRoute = Route::getRoutes()->getByName('console.infrastructure.reinstall.runs.store');
        $bulkRoute = Route::getRoutes()->getByName('console.infrastructure.bulk-install.runs.store');

        $this->assertNotNull($installRoute);
        $this->assertNotNull($reinstallRoute);
        $this->assertNotNull($bulkRoute);
        $this->assertContains('throttle:infrastructure-server-action', $installRoute->gatherMiddleware());
        $this->assertContains('throttle:infrastructure-server-action', $reinstallRoute->gatherMiddleware());
        $this->assertSame([], array_values(array_filter(
            $bulkRoute->gatherMiddleware(),
            static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:'),
        )));

        $limiter = RateLimiter::limiter('infrastructure-server-action');
        $this->assertNotNull($limiter);

        $workspaceId = '9b65ef45-9c9d-4baa-b2b7-6db13392fb18';
        $serverA = '5c24e0a4-1159-41dc-b449-86a8c0ecc66e';
        $serverB = 'ad14601c-8712-4d8e-bb9a-a1297ea6ba99';

        $installA = $limiter($this->requestFor($installRoute, $owner, $workspaceId, ['server_id' => $serverA]));
        $installB = $limiter($this->requestFor($installRoute, $owner, $workspaceId, ['server_id' => $serverB]));
        $installAFromPayload = $limiter($this->requestFor(
            $installRoute,
            $owner,
            $workspaceId,
            ['data' => [['id' => $serverA]]],
        ));
        $reinstallA = $limiter($this->requestFor($reinstallRoute, $owner, $workspaceId));

        $this->assertSame(30, $installA->maxAttempts);
        $this->assertSame(60, $installA->decaySeconds);
        $this->assertNotSame($installA->key, $installB->key, 'A different server must have an independent install limit.');
        $this->assertSame($installA->key, $installAFromPayload->key, 'Single-install payload server IDs must resolve to the same target key.');
        $this->assertNotSame($installA->key, $reinstallA->key, 'Install and reinstall actions must not share a counter.');
    }

    /** @param array<string, mixed> $input */
    private function requestFor(object $route, User $owner, string $workspaceId, array $input = []): Request
    {
        $serverId = $input['server_id'] ?? $input['data'][0]['id'] ?? '5c24e0a4-1159-41dc-b449-86a8c0ecc66e';
        $uri = str_replace(
            ['{workspace}', '{server}'],
            [$workspaceId, $serverId],
            $route->uri(),
        );
        $request = Request::create('/'.$uri, 'POST', $input);
        $route->bind($request);
        $request->setUserResolver(static fn (): User => $owner);
        $request->setRouteResolver(static fn (): object => $route);

        return $request;
    }
}
