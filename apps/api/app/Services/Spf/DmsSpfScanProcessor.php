<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
use Throwable;

class DmsSpfScanProcessor
{
    public function __construct(private readonly DmsSpfScanner $scanner) {}

    public function prepare(int $scanId, int $workers): bool
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return false;
        }
        $path = Storage::disk('local')->path($scan->file_path);
        if (! is_file($path) || ! is_readable($path)) {
            $scan->update(['status' => WorkspaceSpfScan::STATUS_FAILED, 'failure' => 'Scan file not found.']);

            return false;
        }

        $total = 0;
        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $file->fgets();
            $total++;
        }

        $scan->update([
            'total_lines' => $total,
            'progress' => 0,
            'total_found' => 0,
            'workers' => max(1, min(8, $workers)),
            'status' => WorkspaceSpfScan::STATUS_SCANNING,
            'started_at' => now(),
            'failure' => null,
        ]);

        return true;
    }

    public function runShard(int $scanId, int $workerCount, int $workerIndex): int
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan || $scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
            return 1;
        }
        $path = Storage::disk('local')->path($scan->file_path);
        if (! is_file($path) || ! is_readable($path)) {
            return 1;
        }

        try {
            $file = new SplFileObject($path, 'r');
            $index = 0;
            while (! $file->eof()) {
                $line = trim((string) $file->fgets());
                if ($index % $workerCount === $workerIndex) {
                    $scan->refresh();
                    if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
                        return $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 2 : 1;
                    }
                    if ($line !== '' && $this->validDomain($line)) {
                        $domain = SpfParser::normalizeDomain(rtrim($line, '.'));
                        $exists = WorkspaceSpfRoot::query()
                            ->where('workspace_id', $scan->workspace_id)
                            ->where('domain', $domain)
                            ->exists();
                        if (! ($scan->skip_existing && $exists)) {
                            $result = $this->scanner->scanDomain($domain);
                            WorkspaceSpfRoot::query()->updateOrCreate(
                                ['workspace_id' => $scan->workspace_id, 'domain' => $domain],
                                [
                                    'status' => $result['status'],
                                    'record' => $result['record'],
                                    'cidrs' => $result['cidrs'],
                                    'hosts' => $result['hosts'],
                                    'scanned_at' => now(),
                                ],
                            );
                            if ($result['status'] === 'ok') {
                                WorkspaceSpfScan::query()->whereKey($scanId)->increment('total_found');
                            }
                        }

                        // DMS advances progress only for lines accepted as domains.
                        WorkspaceSpfScan::query()->whereKey($scanId)->increment('progress');
                    }
                }
                $index++;
            }

            return 0;
        } catch (Throwable $exception) {
            WorkspaceSpfScan::query()->whereKey($scanId)->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'failure' => substr($exception->getMessage(), 0, 1000),
            ]);

            return 1;
        }
    }

    public function finish(int $scanId, bool $success): void
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return;
        }

        $status = match (true) {
            $scan->status === WorkspaceSpfScan::STATUS_KILLED => WorkspaceSpfScan::STATUS_KILLED,
            $scan->status === WorkspaceSpfScan::STATUS_FAILED || ! $success => WorkspaceSpfScan::STATUS_FAILED,
            default => WorkspaceSpfScan::STATUS_FINISHED,
        };
        $scan->update([
            'status' => $status,
            'pid' => null,
            'worker_pids' => null,
            'ended_at' => now(),
            'failure' => $status === WorkspaceSpfScan::STATUS_FAILED ? ($scan->failure ?: 'One or more SPF workers failed.') : null,
        ]);
        Storage::disk('local')->delete($scan->file_path);
    }

    private function validDomain(string $domain): bool
    {
        return filter_var(rtrim($domain, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
