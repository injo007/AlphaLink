<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRowPreparation;
use App\Services\Infrastructure\DmsBulkInstallRowProcessPayload;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use App\Services\Infrastructure\HostProbeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DmsBulkInstallRowCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private string $transcriptRoot;

    private DmsBulkInstallTranscriptStore $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Bulk worker test',
            'slug' => 'bulk-worker-test',
            'created_by' => $this->user->getKey(),
        ]);
        $this->workspace->users()->attach($this->user->getKey(), ['role' => 'owner']);
        $this->transcriptRoot = storage_path('framework/testing/bulk-row-worker-'.Str::uuid());
        $this->transcripts = new DmsBulkInstallTranscriptStore($this->transcriptRoot);
        $this->app->instance(DmsBulkInstallTranscriptStore::class, $this->transcripts);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->transcriptRoot);

        parent::tearDown();
    }

    public function test_it_runs_one_fake_row_and_persists_its_timestamped_logs(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => true]);

        $rowId = (string) Str::uuid();
        $domainId = (string) Str::uuid();
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $preparedRow = [
            'workspace' => $this->workspace,
            'server' => (object) ['id' => $rowId],
            'domain' => (object) ['id' => $domainId],
            'ips' => ['198.51.100.20'],
            'isps' => ['gmail'],
        ];
        $preparation = Mockery::mock(DmsBulkInstallRowPreparation::class);
        $preparation->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn (User $user): bool => $user->is($this->user)),
                Mockery::on(fn (Workspace $workspace): bool => $workspace->is($this->workspace)),
                $rowId,
                $domainId,
            )
            ->andReturn($preparedRow);
        $this->app->instance(DmsBulkInstallRowPreparation::class, $preparation);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public function install(array $row, array $options, callable $log): void
            {
                $this->assertPreparedRow($row, $options);
                $log('Setup Host');
            }

            private function assertPreparedRow(array $row, array $options): void
            {
                if (($row['prepared']['ips'] ?? null) !== ['198.51.100.20']
                    || ($options['pmta_version'] ?? null) !== 40) {
                    throw new \RuntimeException('The worker context was not forwarded.');
                }
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);
        $payload = $this->encode($rowId, $domainId, $runId);
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(15, 4, 5));

        $this->artisan('infrastructure:install-bulk-row', ['payload' => $payload])
            ->expectsOutput(DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED)
            ->assertSuccessful();

        $transcript = $this->transcripts->read((string) $this->workspace->getKey(), $runId);
        $this->assertStringContainsString('- ['.$rowId.':'.$rowId.'] - Prepare Installation', (string) $transcript);
        $this->assertStringContainsString('- ['.$rowId.':'.$rowId.'] - Setup Host', (string) $transcript);
    }

    public function test_it_catches_a_row_failure_and_returns_the_dms_parent_success_marker(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => true]);

        $rowId = (string) Str::uuid();
        $domainId = (string) Str::uuid();
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $preparation = Mockery::mock(DmsBulkInstallRowPreparation::class);
        $preparation->shouldReceive('prepare')->once()->andReturn([
            'workspace' => $this->workspace,
            'server' => (object) ['id' => $rowId],
            'domain' => (object) ['id' => $domainId],
            'ips' => ['198.51.100.20'],
            'isps' => [],
        ]);
        $this->app->instance(DmsBulkInstallRowPreparation::class, $preparation);
        $this->app->instance(DmsBulkInstallRowRunner::class, new class implements DmsBulkInstallRowRunner
        {
            public function install(array $row, array $options, callable $log): void
            {
                throw new \RuntimeException('Synthetic host stage failure.');
            }
        });

        $this->artisan('infrastructure:install-bulk-row', ['payload' => $this->encode($rowId, $domainId, $runId)])
            ->expectsOutput(DmsBulkInstallRowProcessPayload::RESULT_FAILED)
            ->assertSuccessful();

        $transcript = $this->transcripts->read((string) $this->workspace->getKey(), $runId);
        $this->assertStringContainsString('Error : Synthetic host stage failure.', (string) $transcript);
    }

    public function test_a_failed_saved_server_preflight_stops_before_the_row_runner_without_a_fingerprint_prompt(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => true]);

        $rowId = (string) Str::uuid();
        $domainId = (string) Str::uuid();
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $preparation = Mockery::mock(DmsBulkInstallRowPreparation::class);
        $preparation->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn (User $user): bool => $user->is($this->user)),
                Mockery::on(fn (Workspace $workspace): bool => $workspace->is($this->workspace)),
                $rowId,
                $domainId,
            )
            ->andThrow(new HostProbeException('Verify the server host before installation.'));
        $this->app->instance(DmsBulkInstallRowPreparation::class, $preparation);
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public bool $called = false;

            public function install(array $row, array $options, callable $log): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);

        $this->artisan('infrastructure:install-bulk-row', ['payload' => $this->encode($rowId, $domainId, $runId)])
            ->expectsOutput(DmsBulkInstallRowProcessPayload::RESULT_FAILED)
            ->assertSuccessful();

        $transcript = $this->transcripts->read((string) $this->workspace->getKey(), $runId);
        $this->assertStringContainsString('Verify the server host before installation.', (string) $transcript);
        $this->assertStringNotContainsString('fingerprint', strtolower((string) $transcript));
        $this->assertFalse($runner->called);
    }

    public function test_worker_refuses_to_start_when_execution_gate_is_closed(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => false]);

        $rowId = (string) Str::uuid();
        $domainId = (string) Str::uuid();
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $preparation = Mockery::mock(DmsBulkInstallRowPreparation::class);
        $preparation->shouldNotReceive('prepare');
        $this->app->instance(DmsBulkInstallRowPreparation::class, $preparation);
        $runner = Mockery::mock(DmsBulkInstallRowRunner::class);
        $runner->shouldNotReceive('install');
        $this->app->instance(DmsBulkInstallRowRunner::class, $runner);

        $this->artisan('infrastructure:install-bulk-row', ['payload' => $this->encode($rowId, $domainId, $runId)])
            ->expectsOutput(DmsBulkInstallRowProcessPayload::RESULT_FAILED)
            ->assertSuccessful();

        $transcript = $this->transcripts->read((string) $this->workspace->getKey(), $runId);
        $this->assertStringContainsString('Bulk-install execution is disabled.', (string) $transcript);
    }

    private function encode(string $serverId, string $domainId, string $runId): string
    {
        return app(DmsBulkInstallRowProcessPayload::class)->encode(
            (string) $this->workspace->getKey(),
            (string) $this->user->getKey(),
            $serverId,
            $domainId,
            $runId,
            [
                'pmta_version' => 40,
                'tracking' => false,
                'proxy' => false,
                'imap' => false,
                'spf' => false,
                'dkim' => false,
                'dmarc' => false,
                'https' => false,
            ],
        );
    }
}
