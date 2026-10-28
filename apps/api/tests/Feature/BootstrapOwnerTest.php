<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootstrapOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_saves_pmta_relay_source_ips_encrypted_on_the_initial_workspace(): void
    {
        $this->artisan('alphalink:bootstrap-owner', [
            '--name' => 'Install Owner',
            '--email' => 'owner@example.test',
            '--workspace' => 'Install Workspace',
            '--pmta-relay-ips' => '198.51.100.44, 203.0.113.25,198.51.100.44',
        ])
            ->expectsQuestion('Owner password (at least 16 characters)', 'synthetic-owner-password')
            ->expectsQuestion('Confirm owner password', 'synthetic-owner-password')
            ->assertExitCode(0);
        $workspace = Workspace::query()->sole();
        $settings = $workspace->infrastructureSettings()->sole();

        $this->assertSame(['198.51.100.44', '203.0.113.25'], $settings->pmta_relay_ips);
        $this->assertNotSame(
            json_encode(['198.51.100.44', '203.0.113.25']),
            DB::table('workspace_infrastructure_settings')->where('workspace_id', $workspace->getKey())->value('pmta_relay_ips'),
        );
        $this->assertArrayNotHasKey('pmta_relay_ips', $settings->toArray());
    }

    public function test_bootstrap_rejects_non_ipv4_relay_sources_before_creating_an_owner(): void
    {
        $this->artisan('alphalink:bootstrap-owner', [
            '--name' => 'Install Owner',
            '--email' => 'owner@example.test',
            '--workspace' => 'Install Workspace',
            '--pmta-relay-ips' => '2001:db8::44',
        ])
            ->expectsOutput('PMTA relay source addresses must be comma-separated IPv4 addresses.')
            ->assertExitCode(2);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Workspace::query()->count());
    }
}
