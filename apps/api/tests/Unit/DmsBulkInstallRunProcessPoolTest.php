<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRowProcessPayload;
use App\Services\Infrastructure\DmsBulkInstallRunProcessPoolCoordinator;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsBulkInstallRunProcessPoolTest extends TestCase
{
    private string $transcriptRoot;

    private DmsBulkInstallTranscriptStore $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transcriptRoot = sys_get_temp_dir().'/bulk-install-pool-'.Str::uuid();
        $this->transcripts = new DmsBulkInstallTranscriptStore($this->transcriptRoot);
        Carbon::setTestNow(Carbon::parse('2026-10-23 15:04:05'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->transcriptRoot);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_starts_one_isolated_process_for_each_server_and_preserves_dms_row_results(): void
    {
        $workspaceId = (string) Str::uuid();
        $runId = $this->transcripts->createRun($workspaceId, '1782155053000');
        $workspace = (new Workspace)->forceFill(['id' => $workspaceId]);
        $user = (new User)->forceFill(['id' => '42']);
        $payload = $this->payload([
            ['id' => 'server-one', 'domain' => 'domain-one'],
            ['id' => 'server-two', 'domain' => 'domain-two'],
        ]);
        $decodedRows = [];
        $rowPayload = new DmsBulkInstallRowProcessPayload;

        Process::fake(function (PendingProcess $process) use (&$decodedRows, $rowPayload) {
            $command = $process->command;
            $decodedRows[] = $rowPayload->decode((string) end($command));

            return Process::result(DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED);
        });

        $result = (new DmsBulkInstallRunProcessPoolCoordinator($rowPayload, $this->transcripts))
            ->execute($payload, $user, $workspace, $runId);

        $this->assertSame([
            ['server_id' => 'server-one', 'status' => 'succeeded'],
            ['server_id' => 'server-two', 'status' => 'succeeded'],
        ], $result['results']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertCount(2, $decodedRows);
        $this->assertSame(['server-one', 'server-two'], array_column($decodedRows, 'server_id'));
        $this->assertSame(['domain-one', 'domain-two'], array_column($decodedRows, 'domain_id'));
        $this->assertSame(45, $decodedRows[0]['pmta_version']);
        $this->assertFalse($decodedRows[0]['tracking']);
        $this->assertTrue($decodedRows[0]['dkim']);
        $this->assertSame([
            '[23/10/2026-15:04:05] Installation Started',
        ], $result['logs']);

        Process::assertRanTimes(function (PendingProcess $process): bool {
            return is_array($process->command)
                && in_array('infrastructure:install-bulk-row', $process->command, true);
        }, 2);
    }

    public function test_a_failed_child_row_does_not_fail_the_parent_process_run(): void
    {
        $workspaceId = (string) Str::uuid();
        $runId = $this->transcripts->createRun($workspaceId);
        $workspace = (new Workspace)->forceFill(['id' => $workspaceId]);
        $user = (new User)->forceFill(['id' => '42']);
        $payload = $this->payload([
            ['id' => 'server-good', 'domain' => 'domain-one'],
            ['id' => 'server-bad', 'domain' => 'domain-two'],
        ]);
        $rowPayload = new DmsBulkInstallRowProcessPayload;

        Process::fake(function (PendingProcess $process) use ($rowPayload) {
            $command = $process->command;
            $row = $rowPayload->decode((string) end($command));

            return Process::result(
                $row['server_id'] === 'server-good'
                    ? DmsBulkInstallRowProcessPayload::RESULT_SUCCEEDED
                    : DmsBulkInstallRowProcessPayload::RESULT_FAILED,
            );
        });

        $result = (new DmsBulkInstallRunProcessPoolCoordinator($rowPayload, $this->transcripts))
            ->execute($payload, $user, $workspace, $runId);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([
            ['server_id' => 'server-good', 'status' => 'succeeded'],
            ['server_id' => 'server-bad', 'status' => 'failed'],
        ], $result['results']);
        $this->assertSame(['[23/10/2026-15:04:05] Installation Started'], $result['logs']);
    }

    /** @param list<array{id: string, domain: string}> $rows */
    private function payload(array $rows): array
    {
        return [
            'data' => $rows,
            'key' => '1782155053000',
            'pmta_version' => 45,
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => true,
            'dkim' => true,
            'dmarc' => false,
            'https' => false,
        ];
    }
}
