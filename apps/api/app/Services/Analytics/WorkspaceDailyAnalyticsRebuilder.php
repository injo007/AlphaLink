<?php

namespace App\Services\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceDailyAnalyticsRebuilder
{
    /** @var list<string> */
    private const DIMENSION_COLUMNS = [
        'user_id',
        'campaign_id',
        'workspace_drop_id',
        'isp_id',
        'list_id',
        'list_type',
        'server_id',
        'ip_id',
        'domain_id',
        'sponsor_id',
        'offer_id',
        'from_id',
        'subject_id',
        'creative_id',
    ];

    /**
     * Rebuild the current and preceding daily campaign and Drop event rows.
     */
    public function rebuild(int $days = 1): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('The analytics lookback must be zero or greater.');
        }

        return $this->rebuildRange(now()->subDays($days), now());
    }

    /**
     * Backfill every currently stored source event for first-time rollup setup.
     */
    public function rebuildAll(): int
    {
        $earliest = collect([
            DB::table('campaign_engagement_events')->min('created_at'),
            DB::table('campaign_lead_conversions')->min('converted_at'),
            DB::table('workspace_drop_visitors')->min('created_at'),
            DB::table('workspace_pmta_feedback_events')->min('at'),
        ])->filter()->sort()->first();

        if (! $earliest) {
            return 0;
        }

        $date = Carbon::parse($earliest)->startOfDay();
        $lastDate = now()->startOfDay();
        $rows = 0;
        while ($date->lte($lastDate)) {
            $rows += $this->rebuildRange($date, $date);
            $date->addDay();
        }

        return $rows;
    }

    public function rebuildRange(Carbon $from, Carbon $to, ?string $workspaceId = null): int
    {
        if ($from->gt($to)) {
            throw new \InvalidArgumentException('The analytics end date must not be before its start date.');
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $buckets = [];

        $this->mergeGroups($buckets, 'campaign', $this->campaignEngagementGroups($from, $to, $workspaceId));
        $this->mergeGroups($buckets, 'campaign', $this->campaignLeadGroups($from, $to, $workspaceId));
        $this->mergeGroups($buckets, 'drop', $this->dropVisitorGroups($from, $to, $workspaceId));
        $this->mergeGroups($buckets, 'campaign', $this->campaignFeedbackGroups($from, $to, $workspaceId));
        $this->mergeGroups($buckets, 'drop', $this->dropFeedbackGroups($from, $to, $workspaceId));

        $updatedAt = now();
        $records = array_map(fn (array $row): array => [
            ...$row,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ], array_values($buckets));

        foreach (array_chunk($records, 500) as $batch) {
            DB::table('workspace_daily_analytics')->upsert(
                $batch,
                ['workspace_id', 'source_type', 'source_id', 'at', 'dimensions_hash'],
                [
                    ...self::DIMENSION_COLUMNS,
                    'opens', 'clicks', 'leads', 'unsubscribes', 'bots', 'deliveries', 'soft_bounces', 'hard_bounces', 'lead_revenue', 'updated_at',
                ],
            );
        }

        return count($records);
    }

    /** @return Collection<int, object> */
    private function campaignEngagementGroups(Carbon $from, Carbon $to, ?string $workspaceId): Collection
    {
        $dimensions = [
            'user_id' => 'c.created_by',
            'campaign_id' => 'ce.campaign_id',
            'workspace_drop_id' => 'NULL',
            'isp_id' => 'ce.isp_id',
            'list_id' => 'ce.audience_list_id',
            'list_type' => 'ce.source_email_type',
            'server_id' => $this->jsonTextExpression('ce.dimension_ids', 'server_id'),
            'ip_id' => $this->jsonTextExpression('ce.dimension_ids', 'ip_id'),
            'domain_id' => $this->jsonTextExpression('ce.dimension_ids', 'domain_id'),
            'sponsor_id' => 'o.sponsor_id',
            'offer_id' => 'cs.offer_id',
            'from_id' => $this->jsonTextExpression('ce.dimension_ids', 'from_id'),
            'subject_id' => $this->jsonTextExpression('ce.dimension_ids', 'subject_id'),
            'creative_id' => $this->jsonTextExpression('ce.dimension_ids', 'creative_id'),
        ];

        $query = DB::table('campaign_engagement_events as ce')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'ce.campaign_id')
                    ->on('c.workspace_id', '=', 'ce.workspace_id');
            })
            ->join('campaign_recipient_snapshots as cs', function (JoinClause $join): void {
                $join->on('cs.id', '=', 'ce.recipient_snapshot_id')
                    ->on('cs.workspace_id', '=', 'ce.workspace_id');
            })
            ->join('workspace_offers as o', function (JoinClause $join): void {
                $join->on('o.id', '=', 'cs.offer_id')
                    ->on('o.workspace_id', '=', 'c.workspace_id');
            })
            ->whereBetween('ce.created_at', [$from, $to])
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('ce.workspace_id', $workspaceId))
            ->selectRaw('ce.workspace_id')
            ->selectRaw('DATE(ce.created_at) AS at');

        $this->selectDimensions($query, $dimensions);

        return $query
            ->selectRaw("SUM(CASE WHEN ce.action = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN ce.action = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN ce.action = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->selectRaw("SUM(CASE WHEN ce.action = 'bot' THEN 1 ELSE 0 END) AS bots")
            ->selectRaw('0 AS leads, 0 AS lead_revenue')
            ->groupBy('ce.workspace_id')
            ->groupByRaw('DATE(ce.created_at)')
            ->orderBy('at')
            ->get();
    }

    /** @return Collection<int, object> */
    private function campaignLeadGroups(Carbon $from, Carbon $to, ?string $workspaceId): Collection
    {
        $dimensions = [
            'user_id' => 'c.created_by',
            'campaign_id' => 'clc.campaign_id',
            'workspace_drop_id' => 'NULL',
            'isp_id' => 'clc.isp_id',
            'list_id' => 'clc.audience_list_id',
            'list_type' => 'ri.source_email_type',
            'server_id' => $this->jsonTextExpression('clc.dimension_ids', 'server_id'),
            'ip_id' => $this->jsonTextExpression('clc.dimension_ids', 'ip_id'),
            'domain_id' => $this->jsonTextExpression('clc.dimension_ids', 'domain_id'),
            'sponsor_id' => 'clc.sponsor_id',
            'offer_id' => 'clc.offer_id',
            'from_id' => $this->jsonTextExpression('clc.dimension_ids', 'from_id'),
            'subject_id' => $this->jsonTextExpression('clc.dimension_ids', 'subject_id'),
            'creative_id' => $this->jsonTextExpression('clc.dimension_ids', 'creative_id'),
        ];

        $query = DB::table('campaign_lead_conversions as clc')
            ->join('campaigns as c', function (JoinClause $join): void {
                $join->on('c.id', '=', 'clc.campaign_id')
                    ->on('c.workspace_id', '=', 'clc.workspace_id');
            })
            ->join('campaign_recipient_snapshot_items as ri', function (JoinClause $join): void {
                $join->on('ri.id', '=', 'clc.snapshot_item_id')
                    ->on('ri.workspace_id', '=', 'clc.workspace_id');
            })
            ->whereBetween('clc.converted_at', [$from, $to])
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('clc.workspace_id', $workspaceId))
            ->selectRaw('clc.workspace_id')
            ->selectRaw('DATE(clc.converted_at) AS at');

        $this->selectDimensions($query, $dimensions);

        return $query
            ->selectRaw('0 AS opens, 0 AS clicks, COUNT(*) AS leads, 0 AS unsubscribes, 0 AS bots')
            ->selectRaw('SUM(clc.revenue) AS lead_revenue')
            ->groupBy('clc.workspace_id')
            ->groupByRaw('DATE(clc.converted_at)')
            ->orderBy('at')
            ->get();
    }

    /** @return Collection<int, object> */
    private function dropVisitorGroups(Carbon $from, Carbon $to, ?string $workspaceId): Collection
    {
        $dimensions = [
            'user_id' => 'd.user_id',
            'campaign_id' => 'NULL',
            'workspace_drop_id' => 'wv.workspace_drop_id',
            'isp_id' => 'wv.isp_id',
            'list_id' => 'wv.list_id',
            'list_type' => 'wv.list_type',
            'server_id' => 'wv.server_id',
            'ip_id' => 'wv.ip_id',
            'domain_id' => 'wv.domain_id',
            'sponsor_id' => 'd.sponsor_id',
            'offer_id' => 'd.offer_id',
            'from_id' => 'wv.from_id',
            'subject_id' => 'wv.subject_id',
            'creative_id' => 'wv.creative_id',
        ];

        $query = DB::table('workspace_drop_visitors as wv')
            ->join('workspace_drops as d', function (JoinClause $join): void {
                $join->on('d.id', '=', 'wv.workspace_drop_id')
                    ->on('d.workspace_id', '=', 'wv.workspace_id');
            })
            ->whereBetween('wv.created_at', [$from, $to])
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('wv.workspace_id', $workspaceId))
            ->selectRaw('wv.workspace_id')
            ->selectRaw('DATE(wv.created_at) AS at');

        $this->selectDimensions($query, $dimensions);

        return $query
            ->selectRaw("SUM(CASE WHEN wv.type = 'open' THEN 1 ELSE 0 END) AS opens")
            ->selectRaw("SUM(CASE WHEN wv.type = 'click' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN wv.type = 'lead' THEN 1 ELSE 0 END) AS leads")
            ->selectRaw("SUM(CASE WHEN wv.type = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribes")
            ->selectRaw("SUM(CASE WHEN wv.type = 'bot' THEN 1 ELSE 0 END) AS bots")
            ->selectRaw("SUM(CASE WHEN wv.type = 'lead' THEN wv.revenue ELSE 0 END) AS lead_revenue")
            ->groupBy('wv.workspace_id')
            ->groupByRaw('DATE(wv.created_at)')
            ->orderBy('at')
            ->get();
    }

    /** @return Collection<int, object> */
    private function campaignFeedbackGroups(Carbon $from, Carbon $to, ?string $workspaceId): Collection
    {
        $dimensions = [
            'user_id' => 'c.created_by',
            'campaign_id' => 'pf.campaign_id',
            'workspace_drop_id' => 'NULL',
            'isp_id' => $this->jsonTextExpression('pf.dimension_ids', 'isp_id'),
            'list_id' => 'pf.source_list_id',
            'list_type' => 'pf.list_type',
            'server_id' => $this->jsonTextExpression('pf.dimension_ids', 'server_id'),
            'ip_id' => $this->jsonTextExpression('pf.dimension_ids', 'ip_id'),
            'domain_id' => $this->jsonTextExpression('pf.dimension_ids', 'domain_id'),
            'sponsor_id' => 'o.sponsor_id',
            'offer_id' => 'cs.offer_id',
            'from_id' => $this->jsonTextExpression('pf.dimension_ids', 'from_id'),
            'subject_id' => $this->jsonTextExpression('pf.dimension_ids', 'subject_id'),
            'creative_id' => $this->jsonTextExpression('pf.dimension_ids', 'creative_id'),
        ];

        $query = DB::table('workspace_pmta_feedback_events as pf')
            ->join('campaigns as c', fn (JoinClause $join) => $join->on('c.id', '=', 'pf.campaign_id')->on('c.workspace_id', '=', 'pf.workspace_id'))
            ->join('campaign_recipient_snapshots as cs', fn (JoinClause $join) => $join->on('cs.id', '=', 'pf.snapshot_id')->on('cs.workspace_id', '=', 'pf.workspace_id'))
            ->join('workspace_offers as o', fn (JoinClause $join) => $join->on('o.id', '=', 'cs.offer_id')->on('o.workspace_id', '=', 'pf.workspace_id'))
            ->where('pf.source_type', 'campaign')
            ->whereBetween('pf.at', [$from->toDateString(), $to->toDateString()])
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('pf.workspace_id', $workspaceId))
            ->selectRaw('pf.workspace_id, pf.at');
        $this->selectDimensions($query, $dimensions);

        return $query->selectRaw($this->feedbackMetricSums())
            ->groupBy('pf.workspace_id', 'pf.at')->get();
    }

    /** @return Collection<int, object> */
    private function dropFeedbackGroups(Carbon $from, Carbon $to, ?string $workspaceId): Collection
    {
        $dimensions = [
            'user_id' => 'd.user_id',
            'campaign_id' => 'NULL',
            'workspace_drop_id' => 'pf.workspace_drop_id',
            'isp_id' => $this->jsonTextExpression('pf.dimension_ids', 'isp_id'),
            'list_id' => 'pf.source_list_id',
            'list_type' => 'pf.list_type',
            'server_id' => $this->jsonTextExpression('pf.dimension_ids', 'server_id'),
            'ip_id' => $this->jsonTextExpression('pf.dimension_ids', 'ip_id'),
            'domain_id' => $this->jsonTextExpression('pf.dimension_ids', 'domain_id'),
            'sponsor_id' => 'd.sponsor_id',
            'offer_id' => 'd.offer_id',
            'from_id' => $this->jsonTextExpression('pf.dimension_ids', 'from_id'),
            'subject_id' => $this->jsonTextExpression('pf.dimension_ids', 'subject_id'),
            'creative_id' => $this->jsonTextExpression('pf.dimension_ids', 'creative_id'),
        ];

        $query = DB::table('workspace_pmta_feedback_events as pf')
            ->join('workspace_drops as d', fn (JoinClause $join) => $join->on('d.id', '=', 'pf.workspace_drop_id')->on('d.workspace_id', '=', 'pf.workspace_id'))
            ->where('pf.source_type', 'drop')
            ->whereBetween('pf.at', [$from->toDateString(), $to->toDateString()])
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('pf.workspace_id', $workspaceId))
            ->selectRaw('pf.workspace_id, pf.at');
        $this->selectDimensions($query, $dimensions);

        return $query->selectRaw($this->feedbackMetricSums())
            ->groupBy('pf.workspace_id', 'pf.at')->get();
    }

    private function feedbackMetricSums(): string
    {
        return "0 AS opens, 0 AS clicks, 0 AS leads, 0 AS unsubscribes, 0 AS bots, 0 AS lead_revenue,
            SUM(CASE WHEN pf.metric = 'deliveries' THEN 1 ELSE 0 END) AS deliveries,
            SUM(CASE WHEN pf.metric = 'soft_bounces' THEN 1 ELSE 0 END) AS soft_bounces,
            SUM(CASE WHEN pf.metric = 'hard_bounces' THEN 1 ELSE 0 END) AS hard_bounces";
    }

    /** @param array<string, string> $dimensions */
    private function selectDimensions(Builder $query, array $dimensions): void
    {
        foreach ($dimensions as $column => $expression) {
            $query->selectRaw($expression.' AS '.$column);
            if ($expression !== 'NULL') {
                $query->groupByRaw($expression);
            }
        }
    }

    private function jsonTextExpression(string $column, string $key): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $column."->>'{$key}'";
        }

        return "json_extract({$column}, '$.{$key}')";
    }

    /** @param array<string, array<string, mixed>> $buckets
     * @param  Collection<int, object>  $groups
     */
    private function mergeGroups(array &$buckets, string $sourceType, Collection $groups): void
    {
        foreach ($groups as $group) {
            $dimensions = [];
            foreach (self::DIMENSION_COLUMNS as $column) {
                $value = $group->{$column} ?? null;
                $dimensions[$column] = $value === null || $value === '' ? null : (string) $value;
            }

            $workspaceId = (string) $group->workspace_id;
            $sourceId = (string) ($sourceType === 'campaign' ? $dimensions['campaign_id'] : $dimensions['workspace_drop_id']);
            $at = (string) $group->at;
            $dimensionsHash = hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR));
            $key = implode(':', [$workspaceId, $sourceType, $sourceId, $at, $dimensionsHash]);
            $buckets[$key] ??= [
                'workspace_id' => $workspaceId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'at' => $at,
                'dimensions_hash' => $dimensionsHash,
                'user_id' => $dimensions['user_id'] === null ? null : (int) $dimensions['user_id'],
                'campaign_id' => $sourceType === 'campaign' ? $dimensions['campaign_id'] : null,
                'workspace_drop_id' => $sourceType === 'drop' ? (int) $dimensions['workspace_drop_id'] : null,
                'isp_id' => $dimensions['isp_id'],
                'list_id' => $dimensions['list_id'],
                'list_type' => $dimensions['list_type'],
                'server_id' => $dimensions['server_id'],
                'ip_id' => $dimensions['ip_id'],
                'domain_id' => $dimensions['domain_id'],
                'sponsor_id' => $dimensions['sponsor_id'],
                'offer_id' => $dimensions['offer_id'],
                'from_id' => $dimensions['from_id'],
                'subject_id' => $dimensions['subject_id'],
                'creative_id' => $dimensions['creative_id'],
                'opens' => 0,
                'clicks' => 0,
                'leads' => 0,
                'unsubscribes' => 0,
                'bots' => 0,
                'deliveries' => 0,
                'soft_bounces' => 0,
                'hard_bounces' => 0,
                'lead_revenue' => 0,
            ];

            foreach (['opens', 'clicks', 'leads', 'unsubscribes', 'bots', 'deliveries', 'soft_bounces', 'hard_bounces'] as $metric) {
                $buckets[$key][$metric] += (int) ($group->{$metric} ?? 0);
            }
            $buckets[$key]['lead_revenue'] += (float) $group->lead_revenue;
        }
    }
}
