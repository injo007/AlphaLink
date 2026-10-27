<?php

namespace App\Services;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppHealthReport
{
    private const CACHE_KEY = 'alphalink.app_health.latest';

    public function latest(): array
    {
        try {
            $report = Cache::store('file')->get(self::CACHE_KEY);
        } catch (Throwable) {
            $report = null;
        }

        return is_array($report) ? $report : $this->run();
    }

    public function run(): array
    {
        $checks = [
            $this->uptime(),
            $this->memory(),
            $this->database(),
            $this->storage(),
            $this->queue(),
        ];

        $report = [
            'finished_at' => now()->toIso8601String(),
            'stored_check_results' => $checks,
            'status' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'failed') ? 'failed' : 'ok',
        ];

        try {
            Cache::store('file')->put(self::CACHE_KEY, $report, now()->addDay());
        } catch (Throwable) {
            // The current request can still show the health results if cache storage is unavailable.
        }

        return $report;
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function uptime(): array
    {
        $raw = @file_get_contents('/proc/uptime');
        $seconds = is_string($raw) ? (float) strtok($raw, ' ') : 0.0;

        if ($seconds <= 0) {
            return $this->result('Uptime', 'unknown', 'Uptime unavailable', 'The operating system uptime could not be read.');
        }

        $uptime = CarbonInterval::seconds((int) $seconds)->cascade()->forHumans();

        return $this->result('Uptime', 'ok', 'Uptime available', 'Uptime since '.$uptime);
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function memory(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if (! is_string($raw)) {
            return $this->result('RAM', 'unknown', 'RAM usage unavailable', 'The operating system memory report could not be read.');
        }

        $memory = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', $line, $matches) === 1) {
                $memory[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $memory['MemTotal'] ?? 0;
        $available = $memory['MemAvailable'] ?? null;
        if ($total <= 0 || $available === null) {
            return $this->result('RAM', 'unknown', 'RAM usage unavailable', 'The operating system memory report is incomplete.');
        }

        $usage = $this->evaluateMemory($total, $available);
        if ($usage === null) {
            return $this->result('RAM', 'unknown', 'RAM usage unavailable', 'The operating system memory report is incomplete.');
        }

        $used = $usage['used'];
        $percentUsed = $usage['percent_used'];
        $message = sprintf(
            'Used: %s - Free: %s - Total: %s - Percent used: %s %%',
            $this->formatBytes($used),
            $this->formatBytes($available),
            $this->formatBytes($total),
            number_format($percentUsed, 2),
        );

        return $this->result('RAM', $usage['status'], $usage['short_summary'], $message);
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function database(): array
    {
        try {
            DB::select('SELECT 1');

            return $this->result('Database', 'ok', 'Database connection available', 'The application database answered a read-only probe.');
        } catch (Throwable) {
            return $this->result('Database', 'failed', 'Database connection failed', 'The application database did not answer a read-only probe.');
        }
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function storage(): array
    {
        $path = storage_path('app');
        $available = is_dir($path) && is_readable($path) && is_writable($path);

        return $this->result(
            'Application storage',
            $available ? 'ok' : 'failed',
            $available ? 'Application storage is writable' : 'Application storage is unavailable',
            $available ? 'The application storage directory is readable and writable.' : 'The application storage directory is not available for application use.',
        );
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function queue(): array
    {
        try {
            $connection = (string) config('queue.default');
            if ($connection === 'database' && ! Schema::hasTable('jobs')) {
                return $this->result('Queue', 'failed', 'Database queue table is missing', 'The configured database queue has no jobs table.');
            }

            return $this->result('Queue', 'ok', 'Queue connection configured', 'Configured queue connection: '.$connection.'. Worker activity is not verified by this check.');
        } catch (Throwable) {
            return $this->result('Queue', 'failed', 'Queue connection unavailable', 'The configured queue could not be checked.');
        }
    }

    /** @return array{used:int,percent_used:float,status:string,short_summary:string}|null */
    public function evaluateMemory(int $total, int $available): ?array
    {
        if ($total <= 0 || $available < 0 || $available > $total) {
            return null;
        }

        $used = $total - $available;

        $percentUsed = round($used / $total * 100, 2);

        return [
            'used' => $used,
            'percent_used' => $percentUsed,
            'status' => $percentUsed > 90 ? 'failed' : 'ok',
            'short_summary' => $percentUsed > 90 ? 'RAM usage is over 90%' : 'RAM usage is under 90%',
        ];
    }

    /** @return array{label:string,status:string,shortSummary:string,notificationMessage:string} */
    private function result(string $label, string $status, string $summary, string $message): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'shortSummary' => $summary,
            'notificationMessage' => $message,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 2).' '.$units[$unit];
    }
}
