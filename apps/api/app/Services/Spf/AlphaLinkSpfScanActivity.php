<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfScan;
use App\Models\WorkspaceSpfScanEvent;
use App\Models\WorkspaceSpfScanWorker;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Best-effort operational telemetry; a telemetry failure must never fail DNS work. */
class AlphaLinkSpfScanActivity
{
    private bool $telemetryUnavailable = false;

    /** @var array<string, float> */
    private array $lastWorkerWriteAt = [];

    /** @var array<string, float> */
    private array $lastEventAt = [];

    /** @var array<string, int> */
    private array $lastCheckpointCount = [];

    public function recordEvent(
        int $scanId,
        string $level,
        string $eventType,
        string $message,
        array $metadata = [],
    ): void {
        if ($this->telemetryUnavailable) {
            return;
        }
        try {
            WorkspaceSpfScanEvent::query()->create([
                'scan_id' => $scanId,
                'level' => $level,
                'event_type' => $eventType,
                'message' => $message,
                'metadata' => $metadata === [] ? null : $metadata,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, null, $exception);
        }
    }

    public function startWorker(WorkspaceSpfScan $scan, int $workerIndex, bool $resume = false): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        $key = $this->key((int) $scan->getKey(), $workerIndex);
        try {
            $worker = WorkspaceSpfScanWorker::query()->firstOrNew([
                'scan_id' => $scan->getKey(),
                'worker_index' => $workerIndex,
            ]);
            $worker->forceFill([
                'status' => 'resolving',
                'stage' => 'starting',
                'current_domain' => null,
                'domains_processed' => $resume ? (int) $worker->domains_processed : 0,
                'spf_records_found' => $resume ? (int) $worker->spf_records_found : 0,
                'dns_errors' => $resume ? (int) $worker->dns_errors : 0,
                'last_lookup_duration_ms' => $resume ? $worker->last_lookup_duration_ms : null,
                'last_batch_duration_ms' => $resume ? $worker->last_batch_duration_ms : null,
                'heartbeat_at' => now(),
                'started_at' => $resume ? ($worker->started_at ?? now()) : now(),
                'ended_at' => null,
            ])->save();
            $this->lastWorkerWriteAt[$key] = microtime(true);
            $this->lastEventAt[$key] = microtime(true);
            $this->lastCheckpointCount[$key] = 0;
            $this->recordEvent((int) $scan->getKey(), 'info', 'worker_started', 'DNS worker started.', [
                'worker' => $workerIndex + 1,
                'workers' => (int) $scan->workers,
            ]);
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure((int) $scan->getKey(), $workerIndex, $exception);
        }
    }

    public function touchWorkerIfDue(int $scanId, int $workerIndex, string $stage, string $domain): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        $key = $this->key($scanId, $workerIndex);
        $now = microtime(true);
        $heartbeatInterval = max(1, (int) config('spf.activity_heartbeat_seconds', 2));
        if (isset($this->lastWorkerWriteAt[$key])
            && $now - $this->lastWorkerWriteAt[$key] < $heartbeatInterval) {
            return;
        }

        try {
            WorkspaceSpfScanWorker::query()->updateOrCreate(
                ['scan_id' => $scanId, 'worker_index' => $workerIndex],
                [
                    'status' => 'resolving',
                    'stage' => $stage,
                    'current_domain' => $domain,
                    'heartbeat_at' => now(),
                ],
            );
            $this->lastWorkerWriteAt[$key] = $now;
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, $workerIndex, $exception);
        }
    }

    public function slowLookup(int $scanId, int $workerIndex, string $stage, string $domain, int $durationMs): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        $key = $this->key($scanId, $workerIndex);
        $now = microtime(true);
        $eventInterval = max(5, (int) config('spf.activity_slow_event_interval_seconds', 15));
        $heartbeatInterval = max(1, (int) config('spf.activity_heartbeat_seconds', 2));
        try {
            if (($this->lastWorkerWriteAt[$key] ?? 0) + $heartbeatInterval <= $now) {
                WorkspaceSpfScanWorker::query()
                    ->where('scan_id', $scanId)
                    ->where('worker_index', $workerIndex)
                    ->update([
                        'last_lookup_duration_ms' => $durationMs,
                        'heartbeat_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->lastWorkerWriteAt[$key] = $now;
            }
            if (($this->lastEventAt[$key] ?? 0) + $eventInterval <= $now) {
                $this->recordEvent($scanId, 'warning', 'slow_dns_lookup', 'A DNS lookup is taking longer than expected.', [
                    'worker' => $workerIndex + 1,
                    'stage' => $stage,
                    'domain' => $domain,
                    'duration_ms' => $durationMs,
                ]);
                $this->lastEventAt[$key] = $now;
            }
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, $workerIndex, $exception);
        }
    }

    public function completeBatch(
        int $scanId,
        int $workerIndex,
        int $processed,
        int $spfRecordsFound,
        int $dnsErrors,
        int $durationMs,
        ?string $lastDomain,
    ): void {
        if ($this->telemetryUnavailable) {
            return;
        }
        $key = $this->key($scanId, $workerIndex);
        try {
            $worker = WorkspaceSpfScanWorker::query()->firstOrNew([
                'scan_id' => $scanId,
                'worker_index' => $workerIndex,
            ]);
            $previousCount = (int) $worker->domains_processed;
            $worker->forceFill([
                'status' => 'resolving',
                'stage' => 'batch_complete',
                'current_domain' => $lastDomain,
                'domains_processed' => $previousCount + $processed,
                'spf_records_found' => (int) $worker->spf_records_found + $spfRecordsFound,
                'dns_errors' => (int) $worker->dns_errors + $dnsErrors,
                'last_batch_duration_ms' => $durationMs,
                'heartbeat_at' => now(),
                'started_at' => $worker->started_at ?? now(),
                'ended_at' => null,
            ])->save();

            $this->lastWorkerWriteAt[$key] = microtime(true);
            $checkpointSize = max(50, (int) config('spf.activity_checkpoint_domains', 250));
            $checkpointDue = intdiv((int) $worker->domains_processed, $checkpointSize)
                > intdiv($this->lastCheckpointCount[$key] ?? 0, $checkpointSize);
            $heartbeatDue = ($this->lastEventAt[$key] ?? 0) + 30 <= microtime(true);
            if ($checkpointDue || $heartbeatDue) {
                $this->recordEvent($scanId, 'info', 'worker_checkpoint', 'DNS worker completed a scan batch.', [
                    'worker' => $workerIndex + 1,
                    'domains_processed' => (int) $worker->domains_processed,
                    'spf_records_found' => (int) $worker->spf_records_found,
                    'dns_errors' => (int) $worker->dns_errors,
                    'last_batch_duration_ms' => $durationMs,
                ]);
                $this->lastEventAt[$key] = microtime(true);
                $this->lastCheckpointCount[$key] = (int) $worker->domains_processed;
            }
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, $workerIndex, $exception);
        }
    }

    public function finishWorker(int $scanId, int $workerIndex, string $status): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        try {
            WorkspaceSpfScanWorker::query()
                ->where('scan_id', $scanId)
                ->where('worker_index', $workerIndex)
                ->update([
                    'status' => $status,
                    'stage' => $status,
                    'current_domain' => null,
                    'heartbeat_at' => now(),
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->recordEvent($scanId, $status === 'failed' ? 'error' : 'info', 'worker_'.$status, 'DNS worker '.$status.'.', [
                'worker' => $workerIndex + 1,
            ]);
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, $workerIndex, $exception);
        }
    }

    public function finishScan(WorkspaceSpfScan $scan, string $status): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        try {
            if ($status !== WorkspaceSpfScan::STATUS_FINISHED) {
                $workerStatus = match ($status) {
                    WorkspaceSpfScan::STATUS_KILLED => 'cancelled',
                    WorkspaceSpfScan::STATUS_PAUSED => 'paused',
                    default => 'failed',
                };
                WorkspaceSpfScanWorker::query()
                    ->where('scan_id', $scan->getKey())
                    ->whereIn('status', ['starting', 'resolving'])
                    ->update([
                        'status' => $workerStatus,
                        'stage' => $workerStatus,
                        'current_domain' => null,
                        'heartbeat_at' => now(),
                        'ended_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $this->recordEvent((int) $scan->getKey(), $status === WorkspaceSpfScan::STATUS_FAILED ? 'error' : 'info', 'scan_'.$status, 'SPF scan '.$status.'.', [
                'progress' => (int) $scan->progress,
                'total' => (int) $scan->valid_domains,
                'spf_records_found' => (int) $scan->total_found,
            ]);
            $this->pruneEvents((int) $scan->getKey());
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure((int) $scan->getKey(), null, $exception);
        }
    }

    private function pruneEvents(int $scanId): void
    {
        try {
            $keep = max(100, (int) config('spf.activity_event_retention', 500));
            $oldestKeptId = WorkspaceSpfScanEvent::query()
                ->where('scan_id', $scanId)
                ->orderByDesc('id')
                ->limit($keep)
                ->pluck('id')
                ->min();
            if ($oldestKeptId !== null) {
                WorkspaceSpfScanEvent::query()
                    ->where('scan_id', $scanId)
                    ->where('id', '<', $oldestKeptId)
                    ->delete();
            }
        } catch (Throwable $exception) {
            $this->reportTelemetryFailure($scanId, null, $exception);
        }
    }

    private function key(int $scanId, int $workerIndex): string
    {
        return $scanId.':'.$workerIndex;
    }

    private function reportTelemetryFailure(int $scanId, ?int $workerIndex, Throwable $exception): void
    {
        if ($this->telemetryUnavailable) {
            return;
        }
        $this->telemetryUnavailable = true;
        try {
            Log::warning('SPF scan activity could not be recorded.', [
                'scan_id' => $scanId,
                'worker_index' => $workerIndex,
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Keep the telemetry path fail-open even if the application logger is unavailable.
        }
    }
}
