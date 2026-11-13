<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfScan;
use Illuminate\Support\Facades\Storage;
use SplFileObject;
use Throwable;

class AlphaLinkSpfScanProcessor
{
    private readonly AlphaLinkSpfScanActivity $activity;

    public function __construct(private readonly AlphaLinkSpfScanner $scanner, ?AlphaLinkSpfScanActivity $activity = null)
    {
        $this->activity = $activity ?? new AlphaLinkSpfScanActivity;
    }

    public function prepare(int $scanId, int $workers): bool
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan) {
            return false;
        }

        $disk = Storage::disk('local');
        $path = $disk->path($scan->file_path);
        if ($scan->status === WorkspaceSpfScan::STATUS_KILLED) {
            $disk->delete($scan->file_path);

            return false;
        }
        if ($scan->status !== WorkspaceSpfScan::STATUS_PENDING) {
            return false;
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->failPreparation($scan, 'Scan file is missing or unreadable.', 'scan_input_unavailable');

            return false;
        }

        $fileSize = max(0, (int) (filesize($path) ?: 0));
        $scan->update([
            'stage' => 'validating',
            'file_size_bytes' => $fileSize,
            'preparation_bytes' => 0,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'failure' => null,
        ]);
        $this->activity->recordEvent($scanId, 'info', 'scan_validation_started', 'Validating the uploaded domain file in the background.', [
            'file_size_bytes' => $fileSize,
            'domain_limit' => (int) config('spf.max_domains_per_scan', 10_000_000),
        ]);

        $totalLines = 0;
        $validDomains = 0;
        $lastProgressBytes = 0;
        $maximumDomains = max(1, (int) config('spf.max_domains_per_scan', 10_000_000));
        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $line = trim(str_replace("\xEF\xBB\xBF", '', (string) $file->fgets()));
            $totalLines++;
            if ($line !== '' && $this->validDomain($line)) {
                $validDomains++;
                if ($validDomains > $maximumDomains) {
                    $this->failPreparation($scan, 'This file exceeds the limit of '.number_format($maximumDomains).' valid domains per scan.', 'scan_domain_limit_exceeded');

                    return false;
                }
            }

            $readBytes = min($fileSize, max(0, (int) $file->ftell()));
            if ($readBytes - $lastProgressBytes >= 4_194_304) {
                if (! $this->updatePreparationProgress($scanId, 'validating', $readBytes)) {
                    $disk->delete($scan->file_path);

                    return false;
                }
                $lastProgressBytes = $readBytes;
            }
        }
        if (! $this->updatePreparationProgress($scanId, 'validating', $fileSize)) {
            $disk->delete($scan->file_path);

            return false;
        }

        if ($validDomains === 0) {
            $this->failPreparation($scan, 'The uploaded file does not contain any valid domain names.', 'scan_no_valid_domains');

            return false;
        }

        $requestedParts = in_array((int) $scan->partition_count, [4, 5], true) ? (int) $scan->partition_count : 1;
        $partitionCount = min($requestedParts, $validDomains);
        $maximumWorkers = min(16, max(1, (int) config('spf.max_workers', 8)));
        $workerCount = min($maximumWorkers, max(1, $workers), $validDomains);
        $scan->update([
            'total_lines' => $totalLines,
            'valid_domains' => $validDomains,
            'progress' => 0,
            'total_found' => 0,
            'workers' => $workerCount,
            'partition_count' => $partitionCount,
            'stage' => $partitionCount > 1 ? 'partitioning' : 'preparing_ranges',
            'preparation_bytes' => 0,
            'heartbeat_at' => now(),
        ]);

        try {
            if ($partitionCount > 1) {
                [$partitionPaths, $partitionOffsets] = $this->splitIntoPartitions($scan, $path, $partitionCount, $workerCount, $validDomains, $fileSize);
            } else {
                $partitionPaths = [];
                $partitionOffsets = [$this->buildWorkerOffsets($scanId, $path, $validDomains, $workerCount, $fileSize)];
            }
        } catch (Throwable $exception) {
            $disk->deleteDirectory('spf-scans/'.$scanId.'-parts');
            $scan->refresh();
            if ($scan->status === WorkspaceSpfScan::STATUS_KILLED) {
                $disk->delete($scan->file_path);

                return false;
            }
            throw $exception;
        }

        $scan->refresh();
        if ($scan->status === WorkspaceSpfScan::STATUS_KILLED) {
            $disk->delete(array_values(array_filter(array_merge([$scan->file_path], $partitionPaths))));
            $disk->deleteDirectory('spf-scans/'.$scanId.'-parts');

            return false;
        }

        $prepared = WorkspaceSpfScan::query()->whereKey($scanId)->where('status', WorkspaceSpfScan::STATUS_PENDING)->update([
            'partition_paths' => $partitionPaths ?: null,
            'partition_offsets' => $partitionOffsets,
            'file_size_bytes' => $fileSize,
            'preparation_bytes' => $fileSize,
            'status' => WorkspaceSpfScan::STATUS_SCANNING,
            'stage' => 'resolving',
            'started_at' => now(),
            'heartbeat_at' => now(),
            'progress_at' => now(),
            'current_domain' => null,
            'failure' => null,
        ]);
        if ($prepared === 0) {
            $disk->delete(array_values(array_filter(array_merge([$scan->file_path], $partitionPaths))));
            $disk->deleteDirectory('spf-scans/'.$scanId.'-parts');

            return false;
        }
        $this->activity->recordEvent($scanId, 'info', 'scan_prepared', 'Input file validated and balanced work ranges are ready.', [
            'valid_domains' => $validDomains,
            'total_lines' => $totalLines,
            'workers' => $workerCount,
            'partitions' => $partitionCount,
            'batch_size' => (int) $scan->batch_size,
        ]);

        return true;
    }

    public function runShard(int $scanId, int $workerCount, int $workerIndex): int
    {
        $scan = WorkspaceSpfScan::query()->find($scanId);
        if (! $scan || $scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
            return 1;
        }

        $workerCount = max(1, min((int) $scan->workers, $workerCount));
        $workerIndex = max(0, min($workerCount - 1, $workerIndex));
        $paths = $scan->partition_paths ?: [$scan->file_path];
        $offsets = $scan->partition_offsets ?: [];
        $assignments = $this->assignmentsForWorker(count($paths), $workerCount, $workerIndex);
        $this->activity->startWorker($scan, $workerIndex);

        try {
            foreach ($assignments as [$partitionIndex, $localWorkerIndex]) {
                $relativePath = $paths[$partitionIndex] ?? null;
                if (! is_string($relativePath)) {
                    throw new \RuntimeException('A scan partition path is missing.');
                }
                $path = Storage::disk('local')->path($relativePath);
                if (! is_file($path) || ! is_readable($path)) {
                    throw new \RuntimeException('A scan partition is missing or unreadable.');
                }

                $partOffsets = $offsets[$partitionIndex] ?? [0, (int) (filesize($path) ?: 0)];
                $startOffset = max(0, (int) ($partOffsets[$localWorkerIndex] ?? 0));
                $fallbackEnd = (int) (filesize($path) ?: 0);
                $endOffset = max($startOffset, (int) ($partOffsets[$localWorkerIndex + 1] ?? $fallbackEnd));
                $file = new SplFileObject($path, 'r');
                $file->fseek($startOffset);
                $batch = [];
                $batchLines = 0;
                $batchSize = max(1, min(1000, (int) $scan->batch_size));
                $domainsSinceStatusCheck = 0;

                while (! $file->eof()) {
                    if ((int) $file->ftell() >= $endOffset) {
                        break;
                    }
                    $line = trim(str_replace("\xEF\xBB\xBF", '', (string) $file->fgets()));
                    if ($line !== '' && $this->validDomain($line)) {
                        $batch[] = SpfParser::normalizeDomain(rtrim($line, '.'));
                        $batchLines++;
                        $domainsSinceStatusCheck++;
                        if (count($batch) >= $batchSize) {
                            $result = $this->processBatch($scan, $batch, $batchLines, $workerIndex);
                            if ($result !== 0) {
                                $this->activity->finishWorker($scanId, $workerIndex, $result === 2 ? 'cancelled' : 'failed');

                                return $result;
                            }
                            $batch = [];
                            $batchLines = 0;
                            $domainsSinceStatusCheck = 0;
                        } elseif ($domainsSinceStatusCheck >= 5) {
                            $scan->refresh();
                            if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
                                $this->activity->finishWorker($scanId, $workerIndex, $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 'cancelled' : 'failed');

                                return $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 2 : 1;
                            }
                            $domainsSinceStatusCheck = 0;
                        }
                    }
                }

                if ($batch !== []) {
                    $result = $this->processBatch($scan, $batch, $batchLines, $workerIndex);
                    if ($result !== 0) {
                        $this->activity->finishWorker($scanId, $workerIndex, $result === 2 ? 'cancelled' : 'failed');

                        return $result;
                    }
                }
            }

            $this->activity->finishWorker($scanId, $workerIndex, 'complete');

            return 0;
        } catch (Throwable $exception) {
            report($exception);
            $this->activity->finishWorker($scanId, $workerIndex, 'failed');
            WorkspaceSpfScan::query()->whereKey($scanId)->where('status', WorkspaceSpfScan::STATUS_SCANNING)->update([
                'status' => WorkspaceSpfScan::STATUS_FAILED,
                'stage' => 'failed',
                'failure' => 'A DNS or storage error stopped this scan. Check application logs.',
                'ended_at' => now(),
            ]);

            return 1;
        }
    }

    /** @param list<string> $domains */
    private function processBatch(WorkspaceSpfScan $scan, array $domains, int $lineCount, int $workerIndex): int
    {
        $scan->refresh();
        if ($scan->status !== WorkspaceSpfScan::STATUS_SCANNING) {
            return $scan->status === WorkspaceSpfScan::STATUS_KILLED ? 2 : 1;
        }

        $uniqueDomains = array_values(array_unique($domains));
        $timestamp = now();
        $found = 0;
        $processed = 0;
        $dnsErrors = 0;
        $batchStartedAt = microtime(true);

        foreach (array_chunk($uniqueDomains, 100) as $writeDomains) {
            $existing = $scan->skip_existing
                ? WorkspaceSpfRoot::query()->where('workspace_id', $scan->workspace_id)
                    ->whereIn('domain', $writeDomains)->pluck('domain')->all()
                : [];
            $existingSet = array_fill_keys($existing, true);
            $rows = [];

            foreach ($writeDomains as $domain) {
                if (isset($existingSet[$domain])) {
                    continue;
                }

                $result = $this->scanner->scanDomain($domain, function (
                    string $stage,
                    string $activeDomain,
                    string $event,
                    ?int $durationMs = null,
                    bool $failed = false,
                ) use ($scan, $workerIndex): void {
                    if ($event === 'lookup_started' || $event === 'include_started') {
                        $this->activity->touchWorkerIfDue((int) $scan->getKey(), $workerIndex, $stage, $activeDomain);
                    }
                    $slowLookupMs = max(1000, (int) config('spf.slow_lookup_threshold_ms', 5000));
                    if ($event === 'lookup_finished' && $durationMs !== null && $durationMs >= $slowLookupMs) {
                        $this->activity->slowLookup((int) $scan->getKey(), $workerIndex, $stage, $activeDomain, $durationMs);
                    }
                });
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
                $processed++;
                $dnsErrors += (int) ($result['dns_errors'] ?? 0);
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
        $this->activity->completeBatch(
            (int) $scan->getKey(),
            $workerIndex,
            $processed,
            $found,
            $dnsErrors,
            (int) round((microtime(true) - $batchStartedAt) * 1000),
            $uniqueDomains[array_key_last($uniqueDomains)] ?? null,
        );

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
        $this->activity->finishScan($scan, $status);
        Storage::disk('local')->delete(array_values(array_unique(array_merge([$scan->file_path], $scan->partition_paths ?? []))));
        Storage::disk('local')->deleteDirectory('spf-scans/'.$scanId.'-parts');
    }

    /** @return array{0: list<string>, 1: list<list<int>>} */
    private function splitIntoPartitions(WorkspaceSpfScan $scan, string $sourcePath, int $partitionCount, int $workerCount, int $validDomains, int $fileSize): array
    {
        $disk = Storage::disk('local');
        $directory = 'spf-scans/'.$scan->getKey().'-parts';
        $disk->makeDirectory($directory);

        $partitionTargets = [];
        for ($index = 0; $index < $partitionCount; $index++) {
            $partitionTargets[$index] = intdiv($validDomains, $partitionCount) + ($index < ($validDomains % $partitionCount) ? 1 : 0);
        }
        $workerCounts = $this->workersPerPartition($partitionCount, $workerCount);
        $paths = [];
        $offsets = [];
        $handles = [];
        $currentPart = 0;
        $validInPart = 0;
        $lastProgressBytes = 0;

        $openPart = function (int $part) use ($disk, $directory, &$paths, &$offsets, &$handles, $workerCounts): void {
            $relativePath = $directory.'/part-'.str_pad((string) ($part + 1), 2, '0', STR_PAD_LEFT).'.txt';
            $handle = fopen($disk->path($relativePath), 'wb');
            if ($handle === false) {
                throw new \RuntimeException('A scan partition file could not be created.');
            }
            $paths[$part] = $relativePath;
            $offsets[$part] = array_fill(0, $workerCounts[$part] + 1, null);
            $offsets[$part][0] = 0;
            $handles[$part] = $handle;
        };
        $finishPart = function (int $part) use (&$handles, &$offsets): void {
            $handle = $handles[$part] ?? null;
            if (! is_resource($handle)) {
                return;
            }
            $end = (int) ftell($handle);
            $lastIndex = count($offsets[$part]) - 1;
            $offsets[$part][$lastIndex] = $end;
            foreach ($offsets[$part] as $index => $offset) {
                if ($offset === null) {
                    $offsets[$part][$index] = $end;
                }
            }
            fclose($handle);
            unset($handles[$part]);
        };

        $openPart(0);
        try {
            $input = new SplFileObject($sourcePath, 'r');
            while (! $input->eof()) {
                $rawLine = (string) $input->fgets();
                $line = trim(str_replace("\xEF\xBB\xBF", '', $rawLine));
                $isValid = $line !== '' && $this->validDomain($line);

                if ($isValid && $currentPart < $partitionCount - 1 && $validInPart >= $partitionTargets[$currentPart]) {
                    $finishPart($currentPart);
                    $currentPart++;
                    $validInPart = 0;
                    $openPart($currentPart);
                }

                if ($isValid) {
                    $localWorkers = $workerCounts[$currentPart];
                    for ($worker = 1; $worker < $localWorkers; $worker++) {
                        $boundary = intdiv($partitionTargets[$currentPart] * $worker, $localWorkers);
                        if ($validInPart === $boundary && $offsets[$currentPart][$worker] === null) {
                            $offsets[$currentPart][$worker] = (int) ftell($handles[$currentPart]);
                        }
                    }
                    $validInPart++;
                }

                if (fwrite($handles[$currentPart], $rawLine) === false) {
                    throw new \RuntimeException('A scan partition could not be written.');
                }

                $readBytes = min($fileSize, max(0, (int) $input->ftell()));
                if ($readBytes - $lastProgressBytes >= 4_194_304) {
                    if (! $this->updatePreparationProgress((int) $scan->getKey(), 'partitioning', $readBytes)) {
                        throw new \RuntimeException('The scan was cancelled during partitioning.');
                    }
                    $lastProgressBytes = $readBytes;
                }
            }
            $finishPart($currentPart);
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if (! $this->updatePreparationProgress((int) $scan->getKey(), 'partitioning', $fileSize)) {
            throw new \RuntimeException('The scan was cancelled during partitioning.');
        }
        ksort($paths);
        ksort($offsets);

        return [array_values($paths), array_values($offsets)];
    }

    /** @return list<int> */
    private function buildWorkerOffsets(int $scanId, string $path, int $validDomains, int $workers, int $fileSize): array
    {
        $offsets = array_fill(0, $workers + 1, null);
        $offsets[0] = 0;
        $boundaries = [];
        for ($worker = 1; $worker < $workers; $worker++) {
            $boundaries[$worker] = intdiv($validDomains * $worker, $workers);
        }

        $file = new SplFileObject($path, 'r');
        $validSeen = 0;
        $lastProgressBytes = 0;
        while (! $file->eof()) {
            $lineStart = (int) $file->ftell();
            $line = trim(str_replace("\xEF\xBB\xBF", '', (string) $file->fgets()));
            if ($line !== '' && $this->validDomain($line)) {
                foreach ($boundaries as $worker => $target) {
                    if ($validSeen === $target && $offsets[$worker] === null) {
                        $offsets[$worker] = $lineStart;
                    }
                }
                $validSeen++;
            }

            $readBytes = min($fileSize, max(0, (int) $file->ftell()));
            if ($readBytes - $lastProgressBytes >= 4_194_304) {
                if (! $this->updatePreparationProgress($scanId, 'preparing_ranges', $readBytes)) {
                    throw new \RuntimeException('The scan was cancelled while preparing worker ranges.');
                }
                $lastProgressBytes = $readBytes;
            }
        }
        $offsets[$workers] = $fileSize;
        foreach ($offsets as $index => $offset) {
            if ($offset === null) {
                $offsets[$index] = $fileSize;
            }
        }
        if (! $this->updatePreparationProgress($scanId, 'preparing_ranges', $fileSize)) {
            throw new \RuntimeException('The scan was cancelled while preparing worker ranges.');
        }

        return array_map('intval', $offsets);
    }

    /** @return list<int> */
    private function workersPerPartition(int $partitions, int $workers): array
    {
        if ($workers < $partitions) {
            return array_fill(0, $partitions, 1);
        }

        $base = intdiv($workers, $partitions);
        $remainder = $workers % $partitions;
        $counts = [];
        for ($part = 0; $part < $partitions; $part++) {
            $counts[] = $base + ($part < $remainder ? 1 : 0);
        }

        return $counts;
    }

    /** @return list<array{0: int, 1: int}> */
    private function assignmentsForWorker(int $partitions, int $workers, int $workerIndex): array
    {
        if ($workers < $partitions) {
            $assignments = [];
            for ($part = $workerIndex; $part < $partitions; $part += $workers) {
                $assignments[] = [$part, 0];
            }

            return $assignments;
        }

        $counts = $this->workersPerPartition($partitions, $workers);
        $firstWorker = 0;
        foreach ($counts as $part => $count) {
            if ($workerIndex < $firstWorker + $count) {
                return [[$part, $workerIndex - $firstWorker]];
            }
            $firstWorker += $count;
        }

        return [];
    }

    private function updatePreparationProgress(int $scanId, string $stage, int $bytes): bool
    {
        return WorkspaceSpfScan::query()->whereKey($scanId)->where('status', WorkspaceSpfScan::STATUS_PENDING)->update([
            'stage' => $stage,
            'preparation_bytes' => max(0, $bytes),
            'heartbeat_at' => now(),
        ]) > 0;
    }

    private function failPreparation(WorkspaceSpfScan $scan, string $message, string $event): void
    {
        $updated = WorkspaceSpfScan::query()->whereKey($scan->getKey())->where('status', WorkspaceSpfScan::STATUS_PENDING)->update([
            'status' => WorkspaceSpfScan::STATUS_FAILED,
            'stage' => 'failed',
            'failure' => $message,
            'ended_at' => now(),
            'heartbeat_at' => now(),
        ]);
        if ($updated === 0) {
            if ($scan->fresh()?->status === WorkspaceSpfScan::STATUS_KILLED) {
                Storage::disk('local')->delete($scan->file_path);
            }

            return;
        }
        $this->activity->recordEvent((int) $scan->getKey(), 'error', $event, $message);
        Storage::disk('local')->delete($scan->file_path);
    }

    private function validDomain(string $domain): bool
    {
        return filter_var(rtrim($domain, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
