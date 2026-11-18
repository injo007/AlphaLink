<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignSendRun;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignSendRun;
use App\Services\Authorization\WorkspaceAccess;
use App\Services\Campaigns\AlphaLinkCampaignSendCatalog;
use App\Services\Campaigns\CampaignAutoFailoverService;
use App\Services\Campaigns\CampaignSendMessageBuilder;
use App\Services\Campaigns\PrepareCampaignSendRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignSendController extends Controller
{
    public function start(
        Request $request,
        string $workspace,
        string $campaign,
        AlphaLinkCampaignSendCatalog $catalog,
        PrepareCampaignSendRun $planner,
        CampaignSendMessageBuilder $messageBuilder,
    ): RedirectResponse {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace, ['owner', 'admin', 'operator']);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $campaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
        abort_if($campaign->is_restricted, 409, 'This campaign is restricted. Unrestrict it in Campaign Logs before starting a send.');
        abort_unless($campaign->kind === 'marketing' && $campaign->is_active && $campaign->status === 'draft', 409, 'Only active, prepared marketing drafts can be sent.');

        $revision = $campaign->latestRevision;
        abort_unless($revision && is_array($revision->payload['send_setup'] ?? null), 409, 'Save the campaign setup before sending.');
        $snapshot = CampaignRecipientSnapshot::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->where('revision_id', $revision->getKey())
            ->firstOrFail();

        $setup = $revision->payload['send_setup'];
        $catalog->validateRevisionRouting(
            $workspace,
            $request->user(),
            (string) $workspace->pivot->role,
            (string) ($setup['route_option'] ?? ''),
            (string) ($setup['ip_type'] ?? ''),
            array_map('strval', (array) ($setup['server_ids'] ?? [])),
            array_map('strval', (array) ($setup['ip_ids'] ?? [])),
        );

        [$run, $alreadyRunning] = DB::transaction(function () use ($campaign, $revision, $snapshot, $request, $data, $messageBuilder, $workspace, $planner): array {
            $lockedCampaign = Campaign::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($lockedCampaign->is_restricted, 409, 'This campaign is restricted. Unrestrict it in Campaign Logs before starting a send.');
            $activeRun = CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->whereIn('state', ['planned', 'running'])
                ->latest('created_at')
                ->lockForUpdate()
                ->first();
            if ($activeRun) {
                return [$activeRun, true];
            }

            abort_if(CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->where('revision_id', $revision->getKey())
                ->where(function ($query): void {
                    $query->whereIn('state', ['completed', 'needs_review', 'stopped'])
                        ->orWhere(function ($failed) {
                            $failed->where('state', 'failed')
                                ->where(function ($progress): void {
                                    $progress->where('accepted_count', '>', 0)
                                        ->orWhere('rejected_count', '>', 0)
                                        ->orWhere('suppressed_count', '>', 0)
                                        ->orWhere('uncertain_count', '>', 0);
                                });
                        });
                })
                ->exists(), 409, 'This campaign revision already has a send history. Revise or clone it before sending the same audience again.');

            $run = $planner->create($campaign, $revision, $snapshot, $request->user(), $data['idempotency_key']);
            $messageBuilder->assertReady($run);
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.send_started',
                'subject_type' => CampaignSendRun::class,
                'subject_id' => $run->getKey(),
                'properties' => [
                    'campaign_id' => (string) $campaign->getKey(),
                    'revision_id' => (string) $revision->getKey(),
                    'recipient_snapshot_id' => (string) $snapshot->getKey(),
                    'recipient_count' => (int) $run->recipient_count,
                    'send_type' => (string) $run->send_type,
                ],
                'occurred_at' => now(),
            ]);

            return [$run, false];
        }, attempts: 3);

        if (! $alreadyRunning) {
            ProcessCampaignSendRun::dispatch((string) $run->getKey())
                ->onQueue('campaign-sends')
                ->afterCommit();
        }

        return redirect()->route('console.campaigns.index', $workspace->getKey())
            ->with('status', $alreadyRunning
                ? 'This campaign send is already queued or running. Its latest progress is shown below.'
                : 'Campaign send queued. Progress and SMTP outcomes will update in the campaign register.');
    }

    public function stop(Request $request, string $workspace, string $campaign, string $run): RedirectResponse
    {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace, ['owner', 'admin', 'operator']);

        $stopped = DB::transaction(function () use ($request, $workspace, $campaign, $run): bool {
            $sendRun = CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign)
                ->whereHas('campaign', fn ($query) => $query->where('created_by', $request->user()->getKey()))
                ->whereKey($run)
                ->lockForUpdate()
                ->firstOrFail();
            if ($sendRun->state === 'stopped') {
                return false;
            }
            abort_unless(in_array($sendRun->state, ['planned', 'running'], true), 409, 'This campaign send has already finished.');

            $sendRun->state = 'stopped';
            $sendRun->completed_at = now();
            $sendRun->save();
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.send_stopped',
                'subject_type' => CampaignSendRun::class,
                'subject_id' => $sendRun->getKey(),
                'properties' => [
                    'campaign_id' => (string) $sendRun->campaign_id,
                    'accepted_count' => (int) $sendRun->accepted_count,
                    'rejected_count' => (int) $sendRun->rejected_count,
                    'uncertain_count' => (int) $sendRun->uncertain_count,
                ],
                'occurred_at' => now(),
            ]);

            return true;
        }, attempts: 3);

        return redirect()->route('console.campaigns.index', $workspace->getKey())
            ->with('status', $stopped
                ? 'Campaign send stopped. A message already in progress may finish; no new recipients will be started.'
                : 'Campaign send was already stopped.');
    }

    public function progress(Request $request, string $workspace, string $campaign, string $run): JsonResponse
    {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace, ['owner', 'admin', 'operator']);
        $sendRun = CampaignSendRun::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaign)
            ->whereHas('campaign', fn ($query) => $query->where('created_by', $request->user()->getKey()))
            ->whereKey($run)
            ->firstOrFail();
        $processed = (int) $sendRun->accepted_count + (int) $sendRun->rejected_count
            + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count;

        return response()->json([
            'state' => $sendRun->state,
            'processed' => $processed,
            'total' => (int) $sendRun->recipient_count,
            'percent' => $sendRun->recipient_count > 0 ? min(100, (int) floor($processed * 100 / $sendRun->recipient_count)) : 0,
            'accepted' => (int) $sendRun->accepted_count,
            'rejected' => (int) $sendRun->rejected_count,
            'suppressed' => (int) $sendRun->suppressed_count,
            'uncertain' => (int) $sendRun->uncertain_count,
            'next_batch_at' => $sendRun->execution_options['next_batch_at'] ?? null,
        ]);
    }

    public function telemetry(
        Request $request,
        string $workspace,
        string $campaign,
        CampaignAutoFailoverService $failoverService,
    ): JsonResponse {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace, ['owner', 'admin', 'operator']);

        $campaign = Campaign::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($campaign)
            ->with(['latestRevision', 'sendRuns' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->firstOrFail();

        $sendRun = $campaign->sendRuns->first();
        $setup = $campaign->latestRevision?->payload['send_setup'] ?? [];
        $configuredDomains = $sendRun ? $failoverService->extractConfiguredDomains($sendRun) : [];
        if (empty($configuredDomains) && ! empty($setup['domain'])) {
            $configuredDomains = [strtolower(trim((string) $setup['domain']))];
        }

        $disabledDomains = $sendRun ? $failoverService->getDisabledDomains($sendRun) : [];
        $errorCounts = $sendRun ? $failoverService->getDomainErrorCounts($sendRun) : [];
        $events = $sendRun ? $failoverService->getFailoverEvents($sendRun) : [];

        $domainDetails = [];
        foreach ($configuredDomains as $domain) {
            $isDisabled = in_array($domain, $disabledDomains, true);
            $domainDetails[] = [
                'domain' => $domain,
                'status' => $isDisabled ? 'disabled' : 'active',
                'error_count' => (int) ($errorCounts[$domain] ?? 0),
            ];
        }

        $autoFailoverEnabled = $sendRun
            ? $failoverService->isEnabled($sendRun)
            : (bool) ($setup['auto_failover_enabled'] ?? false);

        $threshold = (int) ($sendRun
            ? data_get($sendRun->execution_options, 'failover_threshold', 5)
            : ($setup['failover_threshold'] ?? 5));

        $patterns = (string) ($sendRun
            ? data_get($sendRun->execution_options, 'failover_error_patterns', '421, rate limit, too many connections, temporarily deferred')
            : ($setup['failover_error_patterns'] ?? '421, rate limit, too many connections, temporarily deferred'));

        $purgeSpool = (bool) ($sendRun
            ? data_get($sendRun->execution_options, 'failover_purge_spool', true)
            : ($setup['failover_purge_spool'] ?? true));

        $processed = $sendRun
            ? (int) $sendRun->accepted_count + (int) $sendRun->rejected_count + (int) $sendRun->suppressed_count + (int) $sendRun->uncertain_count
            : 0;

        return response()->json([
            'campaign_id' => $campaign->getKey(),
            'campaign_name' => $campaign->name,
            'auto_failover_enabled' => $autoFailoverEnabled,
            'threshold' => $threshold,
            'patterns' => $patterns,
            'purge_spool' => $purgeSpool,
            'domains' => $domainDetails,
            'disabled_domains' => $disabledDomains,
            'events' => $events,
            'send_run' => $sendRun ? [
                'id' => $sendRun->getKey(),
                'state' => $sendRun->state,
                'processed' => $processed,
                'total' => (int) $sendRun->recipient_count,
                'percent' => $sendRun->recipient_count > 0 ? min(100, (int) floor($processed * 100 / $sendRun->recipient_count)) : 0,
                'accepted' => (int) $sendRun->accepted_count,
                'rejected' => (int) $sendRun->rejected_count,
            ] : null,
        ]);
    }

    public function restoreDomain(
        Request $request,
        string $workspace,
        string $campaign,
        CampaignAutoFailoverService $failoverService,
    ): JsonResponse {
        $workspace = app(WorkspaceAccess::class)->find($request->user(), $workspace, ['owner', 'admin', 'operator']);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $campaign = Campaign::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($campaign)
            ->with(['sendRuns' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->firstOrFail();

        $sendRun = $campaign->sendRuns->first();
        abort_unless($sendRun, 404, 'No send run found for this campaign.');

        $result = $failoverService->restoreDomain($sendRun, $data['domain']);

        return response()->json($result);
    }
}
