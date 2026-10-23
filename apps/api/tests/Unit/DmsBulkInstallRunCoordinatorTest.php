<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallRunCoordinator;
use Exception;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DmsBulkInstallRunCoordinatorTest extends TestCase
{
    public function test_one_server_exception_is_logged_without_failing_the_bulk_command_or_skipping_other_rows(): void
    {
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public array $called = [];

            public function install(array $row, array $options, callable $log): void
            {
                $this->called[] = [$row['id'], $options['pmta_version']];
                $log('Prepare Installation');

                if ($row['id'] === 'server-a') {
                    throw new Exception('Found 0 Ips Please Check Server Configuration Aborting');
                }
            }
        };

        Carbon::setTestNow(Carbon::parse('2026-10-23 15:04:05'));
        try {
            $result = (new DmsBulkInstallRunCoordinator($runner))->execute([
                'data' => [
                    ['id' => 'server-a', 'domain' => 'domain-a'],
                    ['id' => 'server-b', 'domain' => 'domain-b'],
                ],
                'key' => '1782155053000',
                'pmta_version' => 45,
                'tracking' => false,
                'proxy' => true,
                'imap' => false,
                'spf' => false,
                'dkim' => false,
                'dmarc' => false,
                'https' => false,
            ], fn (string $id): string => strtoupper($id));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([
            ['server_id' => 'server-a', 'status' => 'failed'],
            ['server_id' => 'server-b', 'status' => 'succeeded'],
        ], $result['results']);
        $this->assertSame([['server-a', 45], ['server-b', 45]], $runner->called);
        $this->assertSame([
            '[23/10/2026-15:04:05] Installation Started',
            '[23/10/2026-15:04:05] - [SERVER-A:server-a] - Prepare Installation',
            '[23/10/2026-15:04:05] - [SERVER-A:server-a] - Error : Found 0 Ips Please Check Server Configuration Aborting',
            '[23/10/2026-15:04:05] - [SERVER-B:server-b] - Prepare Installation',
        ], $result['logs']);
    }
}
