<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\CloudflareDnsApi;
use App\Services\Infrastructure\DmsBulkInstallRowProcessPayload;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallRunCoordinator;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\InfrastructureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InfrastructureBulkInstallLogsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private string $transcriptRoot;

    private DmsBulkInstallTranscriptStore $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Transcript demo',
            'slug' => 'transcript-demo',
            'created_by' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner);

        $this->transcriptRoot = storage_path('framework/testing/bulk-install-transcripts-'.Str::uuid());
        $this->transcripts = new DmsBulkInstallTranscriptStore($this->transcriptRoot);
        $this->app->instance(DmsBulkInstallTranscriptStore::class, $this->transcripts);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->transcriptRoot);

        parent::tearDown();
    }

    public function test_fake_install_run_persists_and_returns_the_exact_timestamped_dms_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public function install(array $row, array $options, callable $log): void
            {
                $log('Prepare Installation');
            }
        };

        Carbon::setTestNow(Carbon::parse('2026-10-23 15:04:05'));
        try {
            $result = (new DmsBulkInstallRunCoordinator($runner))->execute([
                'data' => [['id' => 'server-1', 'domain' => 'domain-1']],
                'key' => '1782155053000',
                'pmta_version' => 45,
                'tracking' => false,
                'proxy' => false,
                'imap' => false,
                'spf' => false,
                'dkim' => false,
                'dmarc' => false,
                'https' => false,
            ], fn (string $id): string => 'RELAY', function (string $line) use ($runId): void {
                $this->transcripts->append((string) $this->workspace->getKey(), $runId, $line);
            });
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame([
            '[23/10/2026-15:04:05] Installation Started',
            '[23/10/2026-15:04:05] - [RELAY:server-1] - Prepare Installation',
        ], $result['logs']);
        $this->assertSame([['server_id' => 'server-1', 'status' => 'succeeded']], $result['results']);
        $expected = implode(PHP_EOL, $result['logs']).PHP_EOL;

        $response = $this->getJson($this->logsUrl($runId))
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertSame($expected, $response->json('data'));
        $this->assertSame('queued', $response->json('status'));
        $this->assertSame(json_encode([
            'data' => $expected,
            'status' => 'queued',
            'results' => [],
            'error' => null,
        ]), $response->getContent());
    }

    public function test_a_transcript_cannot_be_read_through_another_workspace_path(): void
    {
        $otherWorkspace = $this->workspace('Other workspace', 'other-workspace', $this->owner);
        $runId = $this->transcripts->createRun((string) $otherWorkspace->getKey(), '1782155053004');
        $this->transcripts->append((string) $otherWorkspace->getKey(), $runId, '[23/10/2026-15:04:05] Installation Started');

        $this->getJson($this->logsUrl($runId))->assertNotFound();
        $this->getJson($this->logsUrl('1782155053004'))->assertNotFound();
    }

    public function test_nonmember_cannot_read_a_workspace_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson($this->logsUrl($runId))
            ->assertNotFound();
    }

    public function test_workspace_viewer_can_read_the_workspace_install_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $viewer = User::factory()->create();
        $this->workspace->users()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->getJson($this->logsUrl($runId))
            ->assertOk();
    }

    public function test_unauthenticated_user_cannot_read_a_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        Auth::logout();

        $this->getJson($this->logsUrl($runId))->assertUnauthorized();
    }

    public function test_expired_session_cannot_start_an_installation(): void
    {
        Auth::logout();
        $key = '1782155053016';

        $this->postJson($this->runsUrl(), $this->payload('server-id', 'domain-id', $key))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson($this->logsUrl($key))->assertUnauthorized();
    }

    public function test_missing_and_malformed_transcript_ids_return_not_found(): void
    {
        $this->getJson($this->logsUrl((string) Str::uuid()))->assertNotFound();
        $this->getJson($this->logsUrl('not-a-uuid'))->assertNotFound();
    }

    public function test_bulk_install_start_is_closed_by_default_and_creates_no_run_log(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => false]);
        [$server, $domain] = $this->eligibleInstallSelection();
        $this->allowSyntheticPmtaReadiness();
        $key = '1782155053000';

        $this->postJson($this->runsUrl(), $this->payload((string) $server->getKey(), (string) $domain->getKey(), $key))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'bulk_install_runner_unavailable')
            ->assertJsonPath('message', 'Bulk installation execution is disabled by ALPHALINK_INSTALL_EXECUTION_ENABLED.');

        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_reports_asset_blockers_even_while_execution_is_disabled(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        $server->forceFill(['host_facts' => [
            'architecture' => 'x86_64',
            'os_pretty_name' => 'AlmaLinux 9.8',
            'pmta_binary_present' => false,
        ]])->save();
        config([
            'infrastructure.bulk_install_execution_enabled' => false,
            'infrastructure.install_assets_directory' => null,
            'pmta.bundle_directory' => null,
        ]);

        $key = '1782155053015';
        $this->postJson($this->runsUrl(), $this->payload((string) $server->getKey(), (string) $domain->getKey(), $key))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'pmta_deployment_not_ready')
            ->assertJsonPath('servers.0.blockers.0', 'The AlphaLink installation asset directory is not configured as a private directory.');

        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_start_fails_closed_when_pmta_prerequisites_are_unverified(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        $server->forceFill(['host_facts' => [
            'architecture' => 'x86_64',
            'os_pretty_name' => 'AlmaLinux 9.8',
        ]])->save();
        config([
            'infrastructure.bulk_install_execution_enabled' => true,
            'infrastructure.install_assets_directory' => null,
            'pmta.bundle_directory' => null,
        ]);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public bool $called = false;

            public function install(array $row, array $options, callable $log): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);
        $key = '1782155053005';

        $this->postJson($this->runsUrl(), $this->payload(
            (string) $server->getKey(),
            (string) $domain->getKey(),
            $key,
        ))->assertServiceUnavailable()
            ->assertJsonPath('code', 'pmta_deployment_not_ready')
            ->assertJsonPath('servers.0.server_id', (string) $server->getKey());

        $this->assertFalse($runner->called);
        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_preflights_missing_host_facts_before_pmta_platform_checks(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        $server->forceFill([
            'state' => 'pending_preflight',
            'expected_host_fingerprint' => null,
            'observed_host_fingerprint' => null,
            'host_facts' => null,
        ])->save();
        config(['infrastructure.bulk_install_execution_enabled' => false]);

        $manager = Mockery::mock(InfrastructureManager::class);
        $manager->shouldReceive('verifyServerHost')->once()->withArgs(
            fn (Workspace $workspace, User $actor, ManagedServer $candidate, ?string $requestId = null): bool => $workspace->is($this->workspace)
                && $actor->is($this->owner)
                && $candidate->is($server),
        )->andReturnUsing(function (Workspace $workspace, User $actor, ManagedServer $candidate): ManagedServer {
            $candidate->forceFill([
                'state' => 'verified',
                'expected_host_fingerprint' => 'SHA256:synthetic-pin',
                'observed_host_fingerprint' => 'SHA256:synthetic-pin',
                'host_facts' => [
                    'os_id' => 'almalinux',
                    'os_version' => '9.8',
                    'os_pretty_name' => 'AlmaLinux 9.8 (Test)',
                    'architecture' => 'x86_64',
                ],
            ])->save();

            return $candidate->fresh();
        });
        $this->app->instance(InfrastructureManager::class, $manager);

        $seenFacts = null;
        $gate = Mockery::mock(DmsPmtaDeploymentGate::class);
        $gate->shouldReceive('evaluate')->once()->andReturnUsing(function (array $facts) use (&$seenFacts): array {
            $seenFacts = $facts;

            return [
                'installation_authorized' => true,
                'checks' => [],
                'blockers' => [],
                'asset_inventory' => null,
            ];
        });
        $this->app->instance(DmsPmtaDeploymentGate::class, $gate);
        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')->once()->andReturn(['settings' => [], 'assets' => []]);
        $this->app->instance(DmsBulkInstallRuntimeInputs::class, $runtimeInputs);

        $key = '1782155053017';
        $this->postJson($this->runsUrl(), $this->payload((string) $server->getKey(), (string) $domain->getKey(), $key))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'bulk_install_runner_unavailable')
            ->assertJsonPath('message', 'Bulk installation execution is disabled by ALPHALINK_INSTALL_EXECUTION_ENABLED.');

        $this->assertSame('AlmaLinux 9.8 (Test)', $seenFacts['os_pretty_name'] ?? null);
        $this->assertSame('x86_64', $seenFacts['architecture'] ?? null);
        $this->assertSame($seenFacts, $server->fresh()->host_facts);
        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_stops_with_clear_preflight_error_when_host_cannot_be_verified(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        $server->forceFill([
            'state' => 'pending_preflight',
            'expected_host_fingerprint' => null,
            'observed_host_fingerprint' => null,
            'host_facts' => null,
        ])->save();

        $manager = Mockery::mock(InfrastructureManager::class);
        $manager->shouldReceive('verifyServerHost')->once()->andThrow(new HostProbeException('SSH authentication failed. Check the stored credential.'));
        $this->app->instance(InfrastructureManager::class, $manager);
        $gate = Mockery::mock(DmsPmtaDeploymentGate::class);
        $gate->shouldNotReceive('evaluate');
        $this->app->instance(DmsPmtaDeploymentGate::class, $gate);

        $key = '1782155053018';
        $this->postJson($this->runsUrl(), $this->payload((string) $server->getKey(), (string) $domain->getKey(), $key))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'server_preflight_not_verified')
            ->assertJsonPath('servers.0.server_id', (string) $server->getKey())
            ->assertJsonPath('servers.0.blockers.0', 'SSH authentication failed. Check the stored credential.');

        $this->assertNull($server->fresh()->host_facts);
        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_start_rejects_missing_runtime_inputs_before_child_or_transcript_creation(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        config(['infrastructure.bulk_install_execution_enabled' => true]);
        $this->allowSyntheticPmtaReadiness();

        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')
            ->once()
            ->andThrow(new \RuntimeException('Required AlphaLink installer port and firewall settings are not configured.'));
        $this->app->instance(DmsBulkInstallRuntimeInputs::class, $runtimeInputs);

        $runner = new class implements DmsBulkInstallRowRunner
        {
            public bool $called = false;

            public function install(array $row, array $options, callable $log): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);

        $processStarted = false;
        Process::fake(function () use (&$processStarted) {
            $processStarted = true;

            return Process::result('');
        });
        $key = '1782155053006';

        $this->postJson($this->runsUrl(), $this->payload(
            (string) $server->getKey(),
            (string) $domain->getKey(),
            $key,
        ))->assertServiceUnavailable()
            ->assertJsonPath('code', 'bulk_install_inputs_not_ready')
            ->assertJsonPath('blockers.0', 'Required AlphaLink installer port and firewall settings are not configured.');

        $this->assertFalse($processStarted);
        $this->assertFalse($runner->called);
        $this->getJson($this->logsUrl($key))->assertNotFound();
    }

    public function test_bulk_install_is_queued_then_streams_logs_and_terminal_status_can_be_polled(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        $domain->update(['is_our' => true]);
        $domain->forceFill(['provider_domain_id' => null])->save();
        DomainProvider::query()->whereKey($domain->domains_provider_id)->update([
            'type' => 'cloudflare',
        ]);
        $cloudflare = Mockery::mock(CloudflareDnsApi::class);
        $cloudflare->shouldNotReceive('zoneIdForExactDomain');
        $this->app->instance(CloudflareDnsApi::class, $cloudflare);
        config(['infrastructure.bulk_install_execution_enabled' => true]);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public array $receivedRows = [];

            public function install(array $row, array $options, callable $log): void
            {
                $this->receivedRows[] = $row;
                $log('Prepare Installation');
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);
        $this->allowSyntheticPmtaReadiness();
        $rowPayload = new DmsBulkInstallRowProcessPayload;
        $workerPayloads = [];
        Process::fake(function (PendingProcess $process) use ($rowPayload, &$workerPayloads, $server) {
            $command = $process->command;
            if (is_array($command) && ($command[0] ?? null) === '/bin/sh') {
                return Process::result("4242\n");
            }

            $workerPayload = $rowPayload->decode((string) end($command));
            $workerPayloads[] = $workerPayload;
            $this->transcripts->append(
                $workerPayload['workspace_id'],
                $workerPayload['run_id'],
                now()->format('[d/m/Y-H:i:s]').sprintf(' - [%s:%s] - Prepare Installation', $server->name, $server->getKey()),
            );

            return Process::result(DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED);
        });
        Carbon::setTestNow(Carbon::parse('2026-10-23 15:04:05'));

        try {
            $response = $this->postJson($this->runsUrl(), $this->payload(
                (string) $server->getKey(),
                (string) $domain->getKey(),
                '1782155053000',
            ));
            $response->assertStatus(202)
                ->assertJsonPath('data', 'Installation queued.')
                ->assertJsonPath('status', 'queued');
            $runId = (string) $response->json('run_id');
            $queuedLogs = $this->getJson($this->logsUrl($runId))->assertOk();
            $this->assertSame('queued', $queuedLogs->json('status'));
            $this->assertSame('', $queuedLogs->json('data'));

            $this->artisan('infrastructure:execute-run', [
                'workspace' => (string) $this->workspace->getKey(),
                'run' => $runId,
            ])->assertExitCode(0);

            $expected = implode(PHP_EOL, [
                '[23/10/2026-15:04:05] Installation Started',
                sprintf('[23/10/2026-15:04:05] - [%s:%s] - Prepare Installation', $server->name, $server->getKey()),
            ]);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $runId);
            $this->assertSame('succeeded', $this->getJson($this->logsUrl($runId))->json('status'));
            $this->assertSame([
                ['server_id' => (string) $server->getKey(), 'status' => 'succeeded'],
            ], $this->getJson($this->logsUrl($runId))->json('results'));
            $this->assertStringContainsString($expected, $this->getJson($this->logsUrl($runId))->json('data'));
            $this->assertCount(1, $workerPayloads);
            $this->assertSame((string) $this->workspace->getKey(), $workerPayloads[0]['workspace_id']);
            $this->assertSame((string) $this->owner->getKey(), $workerPayloads[0]['user_id']);
            $this->assertSame((string) $server->getKey(), $workerPayloads[0]['server_id']);
            $this->assertSame((string) $domain->getKey(), $workerPayloads[0]['domain_id']);
            Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
                && in_array('infrastructure:install-bulk-row', $process->command, true), 1);

            $byDmsKey = $this->getJson($this->logsUrl('1782155053000'))->assertOk();
            $byRunId = $this->getJson($this->logsUrl($runId))->assertOk();
            $this->assertSame('succeeded', $byDmsKey->json('status'));
            $this->assertSame('succeeded', $byRunId->json('status'));
            $this->assertSame($byRunId->json('data'), $byDmsKey->json('data'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_bulk_install_start_rejects_a_domain_that_is_no_longer_eligible(): void
    {
        [$server] = $this->eligibleInstallSelection();
        $ownedDomain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'owned.example.org',
            'state' => 'verified',
            'verification_token' => 'synthetic-token',
            'created_by' => $this->owner->getKey(),
            'domains_provider_id' => DomainProvider::query()->value('id'),
            'status' => 'used',
            'is_our' => true,
            'is_active' => true,
        ]);
        config(['infrastructure.bulk_install_execution_enabled' => true]);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public function install(array $row, array $options, callable $log): void
            {
                throw new \LogicException('The runner must not receive an ineligible domain.');
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);

        $this->postJson($this->runsUrl(), $this->payload(
            (string) $server->getKey(),
            (string) $ownedDomain->getKey(),
            '1782155053001',
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('data.0.domain');
    }

    public function test_preflight_failure_is_logged_for_the_row_without_requesting_a_fingerprint_or_calling_the_runner(): void
    {
        [$server, $domain] = $this->eligibleInstallSelection();
        config(['infrastructure.bulk_install_execution_enabled' => true]);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public bool $called = false;

            public function install(array $row, array $options, callable $log): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);
        $this->allowSyntheticPmtaReadiness();
        Process::fake(function (PendingProcess $process) use ($server) {
            $command = $process->command;
            if (is_array($command) && ($command[0] ?? null) === '/bin/sh') {
                return Process::result("4242\n");
            }

            $workerPayload = app(DmsBulkInstallRowProcessPayload::class)->decode((string) end($command));
            foreach (['Prepare Installation', 'Error : Verify the server host before installation.'] as $message) {
                $this->transcripts->append(
                    $workerPayload['workspace_id'],
                    $workerPayload['run_id'],
                    now()->format('[d/m/Y-H:i:s]').sprintf(' - [%s:%s] - %s', $server->name, $server->getKey(), $message),
                );
            }

            return Process::result(DmsBulkInstallRowProcessPayload::RESULT_FAILED);
        });

        $response = $this->postJson($this->runsUrl(), $this->payload(
            (string) $server->getKey(),
            (string) $domain->getKey(),
            '1782155053003',
        ));
        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $this->artisan('infrastructure:execute-run', [
            'workspace' => (string) $this->workspace->getKey(),
            'run' => (string) $response->json('run_id'),
        ])->assertExitCode(1);

        $final = $this->getJson($this->logsUrl((string) $response->json('run_id')))->assertOk();
        $this->assertSame('failed', $final->json('status'));

        $this->assertFalse($runner->called);
        $this->assertStringContainsString('Verify the server host before installation.', $final->json('data'));
        $this->assertStringNotContainsString('fingerprint', strtolower($final->json('data')));
    }

    public function test_workspace_viewer_can_open_bulk_install_and_reaches_its_safety_gate(): void
    {
        $viewer = User::factory()->create();
        $this->workspace->users()->attach($viewer->id, ['role' => 'viewer']);
        config(['infrastructure.bulk_install_execution_enabled' => false]);
        [$server, $domain] = $this->eligibleInstallSelection();
        $this->allowSyntheticPmtaReadiness();

        $this->actingAs($viewer)
            ->get('/infrastructure/bulk-install')
            ->assertOk()
            ->assertSee((string) $server->getKey());

        $response = $this->actingAs($viewer)
            ->postJson($this->runsUrl(), $this->payload(
                (string) $server->getKey(),
                (string) $domain->getKey(),
                '1782155053002',
            ));

        $response->assertServiceUnavailable()
            ->assertJsonPath('code', 'bulk_install_runner_unavailable');
        $this->assertNull($response->json('run_id'));
    }

    private function logsUrl(string $runId): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/bulk-install/runs/'.$runId.'/logs';
    }

    private function runsUrl(): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/bulk-install/runs';
    }

    /** @return array<string, mixed> */
    private function payload(string $serverId, string $domainId, string $key): array
    {
        return [
            'data' => [['id' => $serverId, 'domain' => $domainId]],
            'key' => $key,
            'pmta_version' => 45,
            'tracking' => 0,
            'proxy' => 0,
            'imap' => 0,
            'spf' => 0,
            'dkim' => 0,
            'dmarc' => 0,
            'https' => 0,
        ];
    }

    /** @return array{ManagedServer, SendingDomain} */
    private function eligibleInstallSelection(): array
    {
        $this->owner->forceFill(['username' => 'mailer'])->save();
        $provider = DomainProvider::query()->create(['name' => 'Install provider', 'is_active' => true]);
        $server = ManagedServer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'ALPHA_MAILER_RELAY',
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.20',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'expected_host_fingerprint' => 'SHA256:synthetic-pin',
            'observed_host_fingerprint' => 'SHA256:synthetic-pin',
            'host_facts' => [
                'os_id' => 'almalinux',
                'os_version' => '9.8',
                'os_pretty_name' => 'AlmaLinux 9.8 (Synthetic)',
                'architecture' => 'x86_64',
                'pmta_binary_present' => false,
            ],
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => false,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $this->owner->getKey(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'install.example.org',
            'state' => 'verified',
            'verification_token' => 'synthetic-token',
            'created_by' => $this->owner->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);

        return [$server, $domain];
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => $slug,
            'created_by' => $owner->id,
        ]);
        $workspace->users()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function allowSyntheticPmtaReadiness(): void
    {
        $gate = Mockery::mock(DmsPmtaDeploymentGate::class);
        $gate->shouldReceive('evaluate')->andReturn([
            'installation_authorized' => true,
            'checks' => [],
            'blockers' => [],
            'asset_inventory' => null,
        ]);
        $this->app->instance(DmsPmtaDeploymentGate::class, $gate);

        $runtimeInputs = Mockery::mock(DmsBulkInstallRuntimeInputs::class);
        $runtimeInputs->shouldReceive('resolve')->andReturn(['settings' => [], 'assets' => []]);
        $this->app->instance(DmsBulkInstallRuntimeInputs::class, $runtimeInputs);
    }
}
