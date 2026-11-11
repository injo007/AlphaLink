<?php

namespace App\Services\OpenObserve;

use App\Services\AppErrorLogReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class ErrorIntelligenceService
{
    public function __construct(
        private readonly OpenObserveClient $client,
        private readonly AppErrorLogReader $logReader,
    ) {}

    /**
     * @return array{
     *     configured: bool,
     *     healthy: bool,
     *     mode: 'openobserve'|'local_fallback',
     *     url: string,
     *     stream: string,
     *     total_failed_jobs: int,
     *     last_error: ?string
     * }
     */
    public function getStatus(): array
    {
        $configured = $this->client->isConfigured();
        $healthy = $configured && $this->client->isHealthy();
        $mode = $healthy ? 'openobserve' : 'local_fallback';

        $failedJobsCount = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobsCount = (int) DB::table('failed_jobs')->count();
            }
        } catch (Throwable) {
            $failedJobsCount = 0;
        }

        return [
            'configured' => $configured,
            'healthy' => $healthy,
            'mode' => $mode,
            'url' => $this->client->getUrl(),
            'stream' => $this->client->getStream(),
            'total_failed_jobs' => $failedJobsCount,
            'last_error' => $this->client->getLastError(),
        ];
    }

    /**
     * Query error records with filters, aggregations, and trends.
     *
     * @param  array{
     *     search?: ?string,
     *     level?: ?string,
     *     module?: ?string,
     *     time_range?: ?string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return array<string, mixed>
     */
    public function getIntelligence(array $filters = []): array
    {
        $status = $this->getStatus();

        if ($status['mode'] === 'openobserve') {
            try {
                $result = $this->queryOpenObserve($filters);
                $result['status'] = $status;
                return $result;
            } catch (Throwable) {
                // If OpenObserve query fails mid-flight, fall back to local gracefully
            }
        }

        $result = $this->queryLocalFallback($filters);
        $result['status'] = $status;
        return $result;
    }

    /**
     * Ingest recent local log files and failed jobs into OpenObserve.
     *
     * @return array{success: bool, synced: int, error: ?string}
     */
    public function syncToOpenObserve(int $limit = 200): array
    {
        $records = [];
        $now = now();

        // 1. Ingest failed queue jobs
        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobs = DB::table('failed_jobs')
                    ->latest('failed_at')
                    ->limit(50)
                    ->get();

                foreach ($failedJobs as $job) {
                    $failedAt = Carbon::parse($job->failed_at);
                    $payload = json_decode((string) $job->payload, true) ?: [];
                    $jobName = $payload['displayName'] ?? $payload['job'] ?? 'Unknown Job';

                    $records[] = [
                        '_timestamp' => (int) ($failedAt->format('Uu')),
                        'level' => 'CRITICAL',
                        'channel' => 'horizon',
                        'message' => 'Queue job failed: '.$jobName,
                        'module' => 'horizon',
                        'request_id' => $job->uuid,
                        'failed_job_uuid' => $job->uuid,
                        'file' => 'failed_jobs',
                        'line' => 1,
                        'exception' => [
                            'class' => 'JobFailedException',
                            'message' => Str::limit($job->exception, 240),
                            'code' => 500,
                            'file' => 'JobHandler.php',
                            'line' => 1,
                            'trace' => Str::limit($job->exception, 2000),
                        ],
                        'context' => [
                            'connection' => $job->connection,
                            'queue' => $job->queue,
                            'job_name' => $jobName,
                        ],
                        'environment' => app()->environment(),
                    ];
                }
            }
        } catch (Throwable) {}

        // 2. Ingest raw log reader entries
        $localEntries = $this->logReader->entries();
        foreach (array_slice($localEntries, 0, $limit) as $entry) {
            $date = Carbon::parse($entry['date']);
            $module = $this->inferModule($entry['summary']);

            $records[] = [
                '_timestamp' => (int) ($date->format('Uu')),
                'level' => strtoupper($entry['level']),
                'channel' => 'laravel',
                'message' => $entry['summary'],
                'module' => $module,
                'request_id' => null,
                'file' => $entry['file'],
                'line' => null,
                'exception' => null,
                'context' => [],
                'environment' => app()->environment(),
            ];
        }

        if (empty($records)) {
            return ['success' => true, 'synced' => 0, 'error' => null];
        }

        $result = $this->client->ingest($records);

        return [
            'success' => $result['success'],
            'synced' => $result['count'],
            'error' => $result['error'],
        ];
    }

    /**
     * Query OpenObserve via SQL.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function queryOpenObserve(array $filters): array
    {
        $timeRange = $filters['time_range'] ?? '24h';
        [$startUs, $endUs] = $this->resolveTimeRange($timeRange);

        $conditions = ["1=1"];
        $stream = $this->client->getStream();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $escaped = str_replace("'", "''", $search);
            $conditions[] = "(message LIKE '%{$escaped}%' OR module LIKE '%{$escaped}%' OR request_id LIKE '%{$escaped}%' OR file LIKE '%{$escaped}%')";
        }

        $level = strtoupper(trim((string) ($filters['level'] ?? '')));
        if ($level !== '' && $level !== 'ALL') {
            $escapedLevel = str_replace("'", "''", $level);
            $conditions[] = "level = '{$escapedLevel}'";
        }

        $module = strtolower(trim((string) ($filters['module'] ?? '')));
        if ($module !== '' && $module !== 'all') {
            $escapedModule = str_replace("'", "''", $module);
            $conditions[] = "module = '{$escapedModule}'";
        }

        $whereClause = implode(' AND ', $conditions);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        // Query total count and breakdown
        $countSql = "SELECT COUNT(*) as total FROM \"{$stream}\" WHERE {$whereClause}";
        $countRes = $this->client->search($countSql, $startUs, $endUs, 0, 1);
        $total = (int) ($countRes['hits'][0]['total'] ?? $countRes['total'] ?? 0);

        // Query severity breakdown
        $severitySql = "SELECT level, COUNT(*) as count FROM \"{$stream}\" WHERE {$whereClause} GROUP BY level";
        $severityRes = $this->client->search($severitySql, $startUs, $endUs, 0, 20);
        $severityBreakdown = [];
        $criticalCount = 0;
        foreach ($severityRes['hits'] as $row) {
            $lvl = strtoupper((string) ($row['level'] ?? 'UNKNOWN'));
            $cnt = (int) ($row['count'] ?? 0);
            $severityBreakdown[$lvl] = $cnt;
            if (in_array($lvl, ['CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                $criticalCount += $cnt;
            }
        }

        // Query module breakdown
        $moduleSql = "SELECT module, COUNT(*) as count FROM \"{$stream}\" WHERE {$whereClause} GROUP BY module";
        $moduleRes = $this->client->search($moduleSql, $startUs, $endUs, 0, 20);
        $moduleBreakdown = [];
        $topModule = 'system';
        $maxModuleCount = -1;
        foreach ($moduleRes['hits'] as $row) {
            $mod = strtolower((string) ($row['module'] ?? 'system'));
            $cnt = (int) ($row['count'] ?? 0);
            $moduleBreakdown[$mod] = $cnt;
            if ($cnt > $maxModuleCount) {
                $maxModuleCount = $cnt;
                $topModule = $mod;
            }
        }

        // Query paginated items
        $dataSql = "SELECT * FROM \"{$stream}\" WHERE {$whereClause} ORDER BY _timestamp DESC LIMIT {$perPage} OFFSET {$offset}";
        $dataRes = $this->client->search($dataSql, $startUs, $endUs, $offset, $perPage);

        $hits = [];
        foreach ($dataRes['hits'] as $row) {
            $hits[] = $this->formatHit($row);
        }

        $isGrouped = ($filters['grouped'] ?? '1') !== '0';
        $displayHits = $isGrouped ? $this->clusterHits($hits) : $hits;
        foreach ($displayHits as &$h) {
            $h['exception']['frames'] = $this->parseStackFrames($h['exception']['trace'] ?? '');
            $h['fingerprint'] = $h['fingerprint'] ?? $this->computeFingerprint($h);
            $h['occurrences_count'] = $h['occurrences_count'] ?? 1;
            $h['first_seen'] = $h['first_seen'] ?? $h['date'];
            $h['last_seen'] = $h['last_seen'] ?? $h['date'];
        }
        unset($h);

        // Generate trend points
        $trend = $this->generateTrendPoints($hits, $timeRange);

        // Fetch failed jobs count
        $failedJobsCount = 0;
        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobsCount = (int) DB::table('failed_jobs')->count();
            }
        } catch (Throwable) {}

        return [
            'mode' => 'openobserve',
            'grouped' => $isGrouped,
            'total_errors' => $total,
            'total_unique_clusters' => count($displayHits),
            'critical_count' => $criticalCount,
            'failed_jobs_count' => $failedJobsCount,
            'top_module' => $topModule,
            'trend' => $trend,
            'severity_breakdown' => $severityBreakdown,
            'module_breakdown' => $moduleBreakdown,
            'hits' => $displayHits,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Query local log files and failed jobs when OpenObserve is offline.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function queryLocalFallback(array $filters): array
    {
        $allEntries = [];

        // 1. Fetch from failed_jobs table
        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobs = DB::table('failed_jobs')
                    ->latest('failed_at')
                    ->limit(100)
                    ->get();

                foreach ($failedJobs as $job) {
                    $failedAt = Carbon::parse($job->failed_at);
                    $payload = json_decode((string) $job->payload, true) ?: [];
                    $jobName = $payload['displayName'] ?? $payload['job'] ?? 'Unknown Job';

                    $allEntries[] = [
                        'id' => 'job-'.$job->id,
                        '_timestamp' => (int) ($failedAt->format('Uu')),
                        'date' => $failedAt->format('Y-m-d H:i:s'),
                        'relative_time' => $failedAt->diffForHumans(),
                        'level' => 'CRITICAL',
                        'module' => 'horizon',
                        'summary' => 'Queue Job Failed: '.$jobName,
                        'message' => 'Queue Job Failed: '.$jobName,
                        'file' => 'failed_jobs',
                        'line' => null,
                        'request_id' => $job->uuid,
                        'failed_job_uuid' => $job->uuid,
                        'is_failed_job' => true,
                        'horizon_url' => Route::has('horizon.index') ? route('horizon.index').'#/failed/'.$job->uuid : null,
                        'exception' => [
                            'class' => 'JobFailedException',
                            'message' => Str::limit($job->exception, 240),
                            'file' => 'JobHandler.php',
                            'line' => null,
                            'trace' => Str::limit($job->exception, 2000),
                        ],
                        'context' => [
                            'connection' => $job->connection,
                            'queue' => $job->queue,
                            'attempts' => $payload['attempts'] ?? 1,
                        ],
                    ];
                }
            }
        } catch (Throwable) {}

        // 2. Fetch from AppErrorLogReader
        $localLogs = $this->logReader->entries();
        foreach ($localLogs as $idx => $entry) {
            $parsedDate = Carbon::parse($entry['date']);
            $module = $this->inferModule($entry['summary']);

            $allEntries[] = [
                'id' => 'log-'.$idx.'-'.md5($entry['date'].$entry['summary']),
                '_timestamp' => (int) ($parsedDate->format('Uu')),
                'date' => $entry['date'],
                'relative_time' => $parsedDate->diffForHumans(),
                'level' => strtoupper($entry['level']),
                'module' => $module,
                'summary' => $entry['summary'],
                'message' => $entry['summary'],
                'file' => $entry['exception_file'] ?? $entry['file'],
                'line' => $entry['exception_line'] ?? null,
                'request_id' => null,
                'failed_job_uuid' => null,
                'is_failed_job' => false,
                'horizon_url' => null,
                'exception' => [
                    'class' => $entry['exception_class'] ?? 'RuntimeException',
                    'message' => $entry['summary'],
                    'file' => $entry['exception_file'] ?? $entry['file'],
                    'line' => $entry['exception_line'] ?? null,
                    'trace' => !empty($entry['trace']) ? $entry['trace'] : "{$entry['summary']}\nOrigin: {$entry['file']}",
                ],
                'context' => [],
            ];
        }

        // Sort by timestamp descending
        usort($allEntries, fn ($a, $b) => $b['_timestamp'] <=> $a['_timestamp']);

        // Filter
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $levelFilter = strtoupper(trim((string) ($filters['level'] ?? '')));
        $moduleFilter = strtolower(trim((string) ($filters['module'] ?? '')));
        $timeRange = $filters['time_range'] ?? null;
        [$startUs, $endUs] = ($timeRange && $timeRange !== 'all') ? $this->resolveTimeRange($timeRange) : [null, null];

        $filtered = array_values(array_filter($allEntries, function ($item) use ($search, $levelFilter, $moduleFilter, $startUs, $endUs) {
            if ($startUs !== null && $item['_timestamp'] < $startUs) {
                return false;
            }
            if ($endUs !== null && $item['_timestamp'] > $endUs) {
                return false;
            }
            if ($levelFilter && $levelFilter !== 'ALL' && $item['level'] !== $levelFilter) {
                return false;
            }
            if ($moduleFilter && $moduleFilter !== 'ALL' && $item['module'] !== $moduleFilter) {
                return false;
            }
            if ($search !== '') {
                $haystack = strtolower($item['message'].' '.$item['module'].' '.($item['request_id'] ?? '').' '.$item['file']);
                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }
            return true;
        }));

        $isGrouped = ($filters['grouped'] ?? '1') !== '0';
        $displayItems = $isGrouped ? $this->clusterHits($filtered) : $filtered;

        $total = count($displayItems);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $pagedHits = array_slice($displayItems, $offset, $perPage);
        foreach ($pagedHits as &$h) {
            $h['exception']['frames'] = $this->parseStackFrames($h['exception']['trace'] ?? '');
            $h['fingerprint'] = $h['fingerprint'] ?? $this->computeFingerprint($h);
            $h['occurrences_count'] = $h['occurrences_count'] ?? 1;
            $h['first_seen'] = $h['first_seen'] ?? $h['date'];
            $h['last_seen'] = $h['last_seen'] ?? $h['date'];
        }
        unset($h);

        // Calculate statistics
        $severityBreakdown = [];
        $moduleBreakdown = [];
        $criticalCount = 0;
        $failedJobsCount = 0;
        $topModule = 'system';
        $maxModuleCount = -1;

        foreach ($filtered as $item) {
            $lvl = $item['level'];
            $severityBreakdown[$lvl] = ($severityBreakdown[$lvl] ?? 0) + 1;
            if (in_array($lvl, ['CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                $criticalCount++;
            }

            $mod = $item['module'];
            $moduleBreakdown[$mod] = ($moduleBreakdown[$mod] ?? 0) + 1;
            if ($moduleBreakdown[$mod] > $maxModuleCount) {
                $maxModuleCount = $moduleBreakdown[$mod];
                $topModule = $mod;
            }

            if (! empty($item['is_failed_job'])) {
                $failedJobsCount++;
            }
        }

        $trend = $this->generateTrendPoints($filtered, $timeRange);

        return [
            'mode' => 'local_fallback',
            'grouped' => $isGrouped,
            'total_errors' => count($filtered),
            'total_unique_clusters' => count($displayItems),
            'critical_count' => $criticalCount,
            'failed_jobs_count' => $failedJobsCount,
            'top_module' => $topModule,
            'trend' => $trend,
            'severity_breakdown' => $severityBreakdown,
            'module_breakdown' => $moduleBreakdown,
            'hits' => $pagedHits,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function formatHit(array $row): array
    {
        $timestamp = (int) ($row['_timestamp'] ?? 0);
        $date = Carbon::createFromTimestampUTC((int) ($timestamp / 1_000_000));
        $exception = $row['exception'] ?? null;
        if (is_string($exception)) {
            $exception = json_decode($exception, true);
        }

        $message = (string) ($row['message'] ?? $exception['message'] ?? 'Unknown error');
        $requestId = $row['request_id'] ?? $row['failed_job_uuid'] ?? null;
        $isFailedJob = ! empty($row['failed_job_uuid']) || ($row['module'] ?? '') === 'horizon';

        return [
            'id' => (string) ($row['id'] ?? $row['_id'] ?? md5($timestamp.$message)),
            '_timestamp' => $timestamp,
            'date' => $date->format('Y-m-d H:i:s'),
            'relative_time' => $date->diffForHumans(),
            'level' => strtoupper((string) ($row['level'] ?? 'ERROR')),
            'module' => strtolower((string) ($row['module'] ?? 'system')),
            'summary' => Str::limit($message, 240),
            'message' => $message,
            'file' => $row['file'] ?? ($exception['file'] ?? 'laravel.log'),
            'line' => $row['line'] ?? ($exception['line'] ?? null),
            'request_id' => $requestId,
            'user_id' => $row['user_id'] ?? null,
            'http_method' => $row['http_method'] ?? null,
            'http_url' => $row['http_url'] ?? null,
            'failed_job_uuid' => $row['failed_job_uuid'] ?? null,
            'is_failed_job' => $isFailedJob,
            'horizon_url' => $isFailedJob && $requestId && Route::has('horizon.index')
                ? route('horizon.index').'#/failed/'.$requestId
                : null,
            'exception' => $exception,
            'context' => is_array($row['context'] ?? null) ? $row['context'] : (json_decode((string) ($row['context'] ?? ''), true) ?: []),
        ];
    }

    private function inferModule(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'job') || str_contains($lower, 'queue') || str_contains($lower, 'horizon')) {
            return 'horizon';
        }
        if (str_contains($lower, 'api') || str_contains($lower, 'token') || str_contains($lower, 'endpoint') || str_contains($lower, 'http')) {
            return 'api';
        }
        if (str_contains($lower, 'pmta') || str_contains($lower, 'server') || str_contains($lower, 'ssh') || str_contains($lower, 'domain') || str_contains($lower, 'dns')) {
            return 'infrastructure';
        }
        if (str_contains($lower, 'campaign') || str_contains($lower, 'mailer') || str_contains($lower, 'warmup') || str_contains($lower, 'send')) {
            return 'campaign';
        }
        if (str_contains($lower, 'track') || str_contains($lower, 'click') || str_contains($lower, 'drop') || str_contains($lower, 'lead')) {
            return 'tracking';
        }
        if (str_contains($lower, 'auth') || str_contains($lower, 'login') || str_contains($lower, 'password') || str_contains($lower, 'credential')) {
            return 'auth';
        }

        return 'system';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveTimeRange(?string $range): array
    {
        $now = now();
        $endUs = (int) ($now->format('Uu'));

        $start = match ($range) {
            '1h' => $now->copy()->subHour(),
            '24h' => $now->copy()->subDay(),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
            'all' => $now->copy()->subYears(5),
            default => $now->copy()->subDay(),
        };

        $startUs = (int) ($start->format('Uu'));

        return [$startUs, $endUs];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{label: string, count: int}>
     */
    private function generateTrendPoints(array $items, ?string $range): array
    {
        $range = $range ?: '24h';
        $buckets = [];
        $format = ($range === '1h') ? 'H:i' : (($range === '7d' || $range === '30d' || $range === 'all') ? 'M d' : 'H:00');

        $slots = ($range === '1h') ? 6 : (($range === '7d') ? 7 : (($range === '30d') ? 30 : 24));
        $now = now();

        for ($i = $slots - 1; $i >= 0; $i--) {
            $pointTime = match ($range) {
                '1h' => $now->copy()->subMinutes($i * 10),
                '7d' => $now->copy()->subDays($i),
                '30d' => $now->copy()->subDays($i),
                default => $now->copy()->subHours($i),
            };
            $key = $pointTime->format($format);
            $buckets[$key] = 0;
        }

        foreach ($items as $item) {
            $ts = (int) ($item['_timestamp'] ?? 0);
            if ($ts > 0) {
                $key = Carbon::createFromTimestampUTC((int) ($ts / 1_000_000))->format($format);
                if (isset($buckets[$key])) {
                    $buckets[$key]++;
                }
            }
        }

        $trend = [];
        foreach ($buckets as $label => $count) {
            $trend[] = ['label' => $label, 'count' => $count];
        }

        return $trend;
    }

    /**
     * Compute normalized error fingerprint for clustering.
     *
     * @param  array<string, mixed>  $item
     */
    public function computeFingerprint(array $item): string
    {
        $level = strtoupper((string) ($item['level'] ?? 'ERROR'));
        $module = strtolower((string) ($item['module'] ?? 'system'));
        $exceptionClass = (string) ($item['exception']['class'] ?? 'RuntimeException');
        $file = basename((string) ($item['file'] ?? ''));
        $line = (string) ($item['line'] ?? '');

        $msg = (string) ($item['message'] ?? $item['summary'] ?? '');
        $normalizedMsg = preg_replace('/\b[0-9a-f]{8,}\b|\b\d{4}-\d{2}-\d{2}\b|\b\d+\b/i', '#', $msg);
        $normalizedMsg = mb_substr(trim((string) $normalizedMsg), 0, 120);

        return md5("{$level}:{$module}:{$exceptionClass}:{$file}:{$line}:{$normalizedMsg}");
    }

    /**
     * Group recurring error events by fingerprint.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function clusterHits(array $items): array
    {
        $clusters = [];
        foreach ($items as $item) {
            $fp = $this->computeFingerprint($item);
            if (! isset($clusters[$fp])) {
                $item['fingerprint'] = $fp;
                $item['occurrences_count'] = 1;
                $item['first_seen'] = $item['date'];
                $item['last_seen'] = $item['date'];
                $item['first_seen_ts'] = $item['_timestamp'];
                $item['last_seen_ts'] = $item['_timestamp'];
                $clusters[$fp] = $item;
            } else {
                $clusters[$fp]['occurrences_count']++;
                if ($item['_timestamp'] < $clusters[$fp]['first_seen_ts']) {
                    $clusters[$fp]['first_seen'] = $item['date'];
                    $clusters[$fp]['first_seen_ts'] = $item['_timestamp'];
                }
                if ($item['_timestamp'] > $clusters[$fp]['last_seen_ts']) {
                    $clusters[$fp]['last_seen'] = $item['date'];
                    $clusters[$fp]['last_seen_ts'] = $item['_timestamp'];
                }
            }
        }

        return array_values($clusters);
    }

    /**
     * Parse raw stack trace string into structured frames with app vs vendor tags.
     *
     * @return list<array{index: int, file: string, short_file: string, line: ?int, call: string, is_app: bool, is_vendor: bool, raw: string}>
     */
    public function parseStackFrames(?string $trace): array
    {
        if (empty($trace)) {
            return [];
        }

        $lines = explode("\n", $trace);
        $frames = [];
        $idx = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '[stacktrace]' || str_starts_with($line, '[previous exception]')) {
                continue;
            }

            // Pattern: #0 /path/to/file.php(123): Class->method()
            if (preg_match('/^#\d+\s+([^(]+)(?:\((\d+)\))?:\s*(.*)$/', $line, $matches)) {
                $filePath = trim($matches[1]);
                $lineNum = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;
                $call = trim($matches[3] ?? '');
                $isVendor = str_contains($filePath, '/vendor/') || str_contains($filePath, '\\vendor\\');
                $isApp = ! $isVendor && (str_contains($filePath, '/app/') || str_contains($filePath, '/resources/') || str_contains($filePath, '/routes/'));

                $frames[] = [
                    'index' => $idx++,
                    'file' => $filePath,
                    'short_file' => basename($filePath),
                    'line' => $lineNum,
                    'call' => $call,
                    'is_app' => $isApp,
                    'is_vendor' => $isVendor,
                    'raw' => $line,
                ];
            } else {
                $isVendor = str_contains($line, '/vendor/') || str_contains($line, '\\vendor\\');
                $isApp = ! $isVendor && (str_contains($line, '/app/') || str_contains($line, '/resources/') || str_contains($line, '/routes/'));
                $frames[] = [
                    'index' => $idx++,
                    'file' => $line,
                    'short_file' => basename($line),
                    'line' => null,
                    'call' => '',
                    'is_app' => $isApp,
                    'is_vendor' => $isVendor,
                    'raw' => $line,
                ];
            }

            if (count($frames) >= 60) {
                break;
            }
        }

        return $frames;
    }
}
