<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsInfrastructureRunLauncher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DmsInfrastructureRunLauncherTest extends TestCase
{
    public function test_launches_a_detached_worker_with_only_workspace_and_run_identifiers(): void
    {
        $workspaceId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result("4242\n");
        });

        $pid = (new DmsInfrastructureRunLauncher)->launch($workspaceId, $runId);

        $this->assertSame(4242, $pid);
        $this->assertCount(1, $commands);
        $this->assertSame(['/bin/sh', '-c'], array_slice($commands[0], 0, 2));
        $this->assertStringContainsString(
            escapeshellarg(PHP_BINDIR.DIRECTORY_SEPARATOR.'php'),
            $commands[0][2],
        );
        $this->assertStringContainsString('nohup', $commands[0][2]);
        $this->assertStringContainsString('infrastructure:execute-run', $commands[0][2]);
        $this->assertStringContainsString($workspaceId, $commands[0][2]);
        $this->assertStringContainsString($runId, $commands[0][2]);
        $this->assertStringNotContainsString('password', strtolower($commands[0][2]));
    }

    public function test_rejects_a_malformed_run_identifier_before_starting_a_worker(): void
    {
        Process::fake();

        try {
            (new DmsInfrastructureRunLauncher)->launch((string) Str::uuid(), 'invalid');
            $this->fail('Malformed identifiers must not launch a worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The installation run identifier is invalid.', $exception->getMessage());
        }

        Process::assertNothingRan();
    }
}
