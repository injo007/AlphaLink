<?php

namespace App\Services\Spf;

use App\Models\WorkspaceSpfRoot;
use App\Models\WorkspaceSpfSearchResult;
use App\Models\WorkspaceSpfSearchRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class AlphaLinkSpfSearchRunner
{
    public function run(int $searchRunId): void
    {
        $run = WorkspaceSpfSearchRun::query()->find($searchRunId);
        if (! $run || $run->status !== WorkspaceSpfSearchRun::STATUS_PENDING) {
            return;
        }

        $run->update([
            'status' => WorkspaceSpfSearchRun::STATUS_PROCESSING,
            'stage' => 'matching',
            'started_at' => now(),
            'heartbeat_at' => now(),
            'failure' => null,
        ]);

        $matcher = app(AlphaLinkSpfDnsCheckMatcher::class);
        $remainingChecks = null;
        $pendingRows = [];
        $processedSinceCheckpoint = 0;
        $insertedSinceCheckpoint = 0;

        try {
            $roots = WorkspaceSpfRoot::query()
                ->where('workspace_id', $run->workspace_id)
                ->select(['id', 'domain', 'record', 'hosts']);

            foreach ($roots->lazyById(100) as $root) {
                if ($run->status === WorkspaceSpfSearchRun::STATUS_CANCELLED) {
                    return;
                }

                foreach ($this->matchRoot($root, $run->ips ?? [], $matcher, $remainingChecks) as $row) {
                    $pendingRows[] = [
                        'search_run_id' => $run->getKey(),
                        'root_id' => $row['root_id'],
                        'matched_ip' => $row['matched_ip'],
                        'root' => $row['root'],
                        'include' => $row['include'],
                        'network' => $row['network'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($pendingRows) >= 1000) {
                        $insertedSinceCheckpoint += $this->persistRows($pendingRows);
                        $pendingRows = [];
                    }
                }

                $processedSinceCheckpoint++;
                if ($processedSinceCheckpoint >= 25) {
                    $insertedSinceCheckpoint += $this->persistRows($pendingRows);
                    $pendingRows = [];
                    $this->checkpoint($run, $processedSinceCheckpoint, $insertedSinceCheckpoint, $matcher->dnsCheckLimitReached());
                    $processedSinceCheckpoint = 0;
                    $insertedSinceCheckpoint = 0;
                    $run->refresh();
                    if ($run->status === WorkspaceSpfSearchRun::STATUS_CANCELLED) {
                        return;
                    }
                }
            }

            $insertedSinceCheckpoint += $this->persistRows($pendingRows);
            if ($processedSinceCheckpoint > 0 || $insertedSinceCheckpoint > 0) {
                $this->checkpoint($run, $processedSinceCheckpoint, $insertedSinceCheckpoint, $matcher->dnsCheckLimitReached());
            }

            $run->refresh();
            if ($run->status !== WorkspaceSpfSearchRun::STATUS_CANCELLED) {
                $run->update([
                    'status' => WorkspaceSpfSearchRun::STATUS_COMPLETE,
                    'stage' => 'complete',
                    'progress' => $run->total_roots,
                    'dns_checks_limited' => $matcher->dnsCheckLimitReached(),
                    'heartbeat_at' => now(),
                    'ended_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
            $run->refresh();
            if ($run->status !== WorkspaceSpfSearchRun::STATUS_CANCELLED) {
                $run->update([
                    'status' => WorkspaceSpfSearchRun::STATUS_FAILED,
                    'stage' => 'failed',
                    'failure' => 'The SPF comparison could not be completed. Review the worker and DNS logs.',
                    'heartbeat_at' => now(),
                    'ended_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $ips
     * @return list<array{root_id: int|string, matched_ip: string, root: string, include: string, network: string}>
     */
    private function matchRoot(WorkspaceSpfRoot $root, array $ips, AlphaLinkSpfDnsCheckMatcher $matcher, ?int &$remainingChecks): array
    {
        $rows = [];
        foreach ($ips as $ip) {
            foreach ($root->hosts ?? [] as $host) {
                foreach ($host['cidrs'] ?? [] as $cidr) {
                    if (SpfParser::ipInCidr($ip, $cidr)) {
                        $rows[] = [
                            'root_id' => $root->getKey(),
                            'matched_ip' => $ip,
                            'root' => $root->domain,
                            'include' => $host['domain'] ?? $root->domain,
                            'network' => $cidr,
                        ];
                    }
                }
            }
        }

        foreach ($matcher->findMatches([$root], $ips, $remainingChecks) as $row) {
            $rows[] = $row;
        }

        $uniqueRows = [];
        foreach ($rows as $row) {
            $key = $row['matched_ip'].'|'.$row['root'].'|'.$row['include'];
            $uniqueRows[$key] ??= $row;
        }

        return array_values($uniqueRows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function persistRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += WorkspaceSpfSearchResult::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    private function checkpoint(WorkspaceSpfSearchRun $run, int $processed, int $inserted, bool $dnsChecksLimited): void
    {
        DB::transaction(function () use ($run, $processed, $inserted, $dnsChecksLimited): void {
            WorkspaceSpfSearchRun::query()->whereKey($run->getKey())->increment('progress', $processed, [
                'heartbeat_at' => now(),
                'dns_checks_limited' => $dnsChecksLimited,
            ]);
            if ($inserted > 0) {
                WorkspaceSpfSearchRun::query()->whereKey($run->getKey())->increment('total_matches', $inserted);
            }
        });
    }
}
