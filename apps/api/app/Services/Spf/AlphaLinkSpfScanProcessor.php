<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
use Throwable;

class AlphaLinkSpfScanProcessor
{
    public function __construct(private readonly AlphaLinkSpfScanner $scanner) {}

    public function prepare(int $scanId, int $workers): bool
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return false;
        }
        $path = Storage::disk('local')->path($scan->file_path);
        if (! is_file($path) || ! is_readable($path)) {
            $scan->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'Scan file is missing or unreadable.',
                'ended_at' => now(),
            ]);

            return false;
        }

        $totalLines = 0;
        $validDomains = 0;
        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $line = trim(str_replace("\xEF\xBB\xBF", '', (string) $file->fgets()));
            $totalLines++;
            if ($line !== '' && $this->validDomain($line)) {
                $validDomains++;
            }
        }

        $maximumWorkers = min(16, max(1, (int) config('spf.max_workers', 8)));
        $scan->update([
            'total_lines' => $totalLines,
            'valid_domains' => $validDomains,
            'progress' => 0,
            'total_found' => 0,
            'workers' => max(1, min($maximumWorkers, $workers)),
            'status' => WorkspaceSpfScan::STATUS_SCANNING,
            'stage' => 'resolving',
            'started_at' => now(),
            'heartbeat_at' => now(),
            'progress_at' => now(),
            'current_domain' => null,
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
            $batch = [];
            $batchLines = 0;
            $batchSize = max(1, min(1000, (int) $scan->batch_size));
            $domainsSinceStatusCheck = 0;
            while (! $file->eof()) {
                $line = trim(str_replace("\xEF\xBB\xBF", '', (string) $file->fgets()));
                if ($index % $workerCount === $workerIndex) {
                    if ($line !== '' && $this->validDomain($line)) {
                        $batch[] = SpfParser::normalizeDomain(rtrim($line, '.'));
                        $batchLines++;
                        $domainsSinceStatusCheck++;
                        if (count($batch) >= $batchSize) {
                            $result = $this->processBatch($scan, $batch, $batchLines);
                            if ($result !== 0) {
                                return $result;
                            }
                            $batch = [];
                            $batchLines = 0;
                            $domainsSinceStatusCheck = 0;
                        } elseif ($domainsSinceStatusCheck >= 5) {
                            $scan->refresh();
                            if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
                                return $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 2 : 1;
                            }
                            $domainsSinceStatusCheck = 0;
                        }
                    }
                }
                $index++;
            }

            if ($batch !== []) {
                return $this->processBatch($scan, $batch, $batchLines);
            }

            return 0;
        } catch (Throwable $exception) {
            report($exception);
            WorkspaceSpfScan::query()->whereKey($scanId)->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'A DNS or storage error stopped this scan. Check application logs.',
                'ended_at' => now(),
            ]);

            return 1;
        }
    }

    /** @param list<string> $domains */
    private function processBatch(WorkspaceSpfScan $scan, array $domains, int $lineCount): int
    {
        $scan->refresh();
        if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
            return $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 2 : 1;
        }

        $uniqueDomains = array_values(array_unique($domains));
        $timestamp = now();
        $found = 0;

        foreach (array_chunk($uniqueDomains, 100) as $writeDomains) {
            $existing = $scan->skip_existing
                ? WorkspaceSpfRoot::query()->where('workspace_id', $scan->workspace_id)
                    ->whereIn('domain', $writeDomains)->pluck('domain')->all()
                : [];
            $existingSet = array_fill_keys($existing, true);
            $rows = [];

            $reportedCurrentDomain = false;
            foreach ($writeDomains as $domain) {
                if (isset($existingSet[$domain])) {
                    continue;
                }

                if (! $reportedCurrentDomain) {
                    WorkspaceSpfScan::query()->whereKey($scan->getKey())->update([
                        'heartbeat_at' => now(),
                        'current_domain' => $domain,
                        'stage' => 'resolving',
                    ]);
                    $reportedCurrentDomain = true;
                }
                $result = $this->scanner->scanDomain($domain);
                $rows[$domain] = [
                    'workspace_id' => $scan->workspace_id,
                    'domain' => $domain,
                    'status' => $result['status'],
                    'record' => $result['record'],
                    'cidrs' => json_encode($result['cidrs'], JSON_THROW_ON_ERROR),
                    'hosts' => json_encode($result['hosts'], JSON_THROW_ON_ERROR),
                    'scanned_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                if ($result['status'] === 'ok') {
                    $found++;
                }
            }

            if ($rows !== []) {
                WorkspaceSpfRoot::query()->upsert(
                    array_values($rows),
                    ['workspace_id', 'domain'],
                    ['status', 'record', 'cidrs', 'hosts', 'scanned_at', 'updated_at'],
                );
            }
        }

        WorkspaceSpfScan::query()->whereKey($scan->getKey())->increment('progress', $lineCount, [
            'heartbeat_at' => now(),
            'progress_at' => now(),
            'current_domain' => $uniqueDomains[array_key_last($uniqueDomains)] ?? null,
            'stage' => 'resolving',
        ]);
        if ($found > 0) {
            WorkspaceSpfScan::query()->whereKey($scan->getKey())->increment('total_found', $found);
        }

        return 0;
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
            'stage' => match ($status) {
                WorkspaceSpfScan::STATUS_KILLED => 'cancelled',
                WorkspaceSpfScan::STATUS_FAILED => 'failed',
                default => 'complete',
            },
            'current_domain' => null,
            'heartbeat_at' => now(),
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
