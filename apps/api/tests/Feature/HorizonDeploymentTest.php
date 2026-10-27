<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;
use Tests\TestCase;

class HorizonDeploymentTest extends TestCase
{
    public function test_horizon_supervises_only_the_redis_default_queue_and_limits_production_workers(): void
    {
        $this->assertSame('redis', config('horizon.defaults.supervisor-1.connection'));
        $this->assertSame(['default'], config('horizon.defaults.supervisor-1.queue'));
        $this->assertSame(2, config('horizon.environments.production.supervisor-1.maxProcesses'));
        $this->assertSame(60, config('horizon.defaults.supervisor-1.timeout'));
        $this->assertSame(90, config('queue.connections.redis.retry_after'));

        $this->assertSame('database', config('queue.connections.drop_suppression.driver'));
        $this->assertSame(86400, config('queue.connections.drop_suppression.retry_after'));
        $this->assertTrue(class_exists(HorizonServiceProvider::class));
        $this->assertTrue(Route::has('horizon.index'));
    }

    public function test_horizon_dashboard_gate_denies_guests_and_non_authorized_users(): void
    {
        config(['horizon.authorized_emails' => ['ops@example.test']]);

        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->make([
            'email' => 'other@example.test',
        ]))->allows('viewHorizon'));
    }

    public function test_horizon_dashboard_gate_allows_configured_email_case_insensitively(): void
    {
        config(['horizon.authorized_emails' => ['ops@example.test']]);

        $operator = User::factory()->make(['email' => 'OPS@example.test']);

        $this->assertTrue(Gate::forUser($operator)->allows('viewHorizon'));
    }

    public function test_horizon_dashboard_route_allows_the_configured_owner(): void
    {
        config(['horizon.authorized_emails' => ['owner@example.test']]);
        $this->actingAs(User::factory()->make(['email' => 'owner@example.test']));

        $this->get(route('horizon.index'))->assertOk();
    }

    public function test_horizon_dashboard_route_rejects_another_authenticated_user(): void
    {
        config(['horizon.authorized_emails' => ['owner@example.test']]);
        $this->actingAs(User::factory()->make(['email' => 'other@example.test']));

        $this->get(route('horizon.index'))->assertForbidden();
    }
}
