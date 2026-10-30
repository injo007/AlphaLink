<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignSendRun;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignSendRun;
use App\Services\Campaigns\CampaignSendMessageBuilder;
use App\Services\Campaigns\DmsCampaignSendCatalog;
use App\Services\Campaigns\PrepareCampaignSendRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignSendController extends Controller
{
    public function start(
        Request $request,
        string $workspace,
        string $campaign,
        DmsCampaignSendCatalog $catalog,
        PrepareCampaignSendRun $planner,
        CampaignSendMessageBuilder $messageBuilder,
    ): RedirectResponse {
        $workspace = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspace)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin', 'operator'], true), 403);
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $campaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaign)
            ->firstOrFail();
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

        if (! $this->hasEligibleConsentedRecipient($snapshot, $setup)) {
            throw ValidationException::withMessages([
                'send' => 'No selected recipient has a current, evidenced marketing email consent record. Add valid opt-in evidence, then build a fresh campaign snapshot.',
            ]);
        }

        [$run, $alreadyRunning] = DB::transaction(function () use ($campaign, $revision, $snapshot, $request, $data, $messageBuilder, $workspace, $planner): array {
            Campaign::query()->where('workspace_id', $workspace->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            $activeRun = CampaignSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->where('revision_id', $revision->getKey())
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
                    $query->whereIn('state', ['completed', 'needs_review'])
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

        ProcessCampaignSendRun::dispatch((string) $run->getKey())
            ->onQueue('campaign-sends')
            ->afterCommit();

        return redirect()->route('console.campaigns.index', $workspace->getKey())
            ->with('status', $alreadyRunning
                ? 'This campaign send is already running. Its latest progress is shown below.'
                : 'Campaign send queued. Progress and SMTP outcomes will update in the campaign register.');
    }

    public function progress(Request $request, string $workspace, string $campaign, string $run): JsonResponse
    {
        $workspace = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspace)
            ->firstOrFail();
        abort_unless(in_array($workspace->pivot->role, ['owner', 'admin', 'operator'], true), 403);
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
        ]);
    }

    private function hasEligibleConsentedRecipient(CampaignRecipientSnapshot $snapshot, array $setup): bool
    {
        $configuredTotal = data_get($setup, 'parameters.total_send', $setup['total_send'] ?? $snapshot->recipient_count);
        $endPosition = min(
            (int) $snapshot->recipient_count,
            (int) $snapshot->start_from + max(1, (int) $configuredTotal),
        );

        return DB::table('campaign_recipient_snapshot_items as item')
            ->join('contacts as contact', function ($join): void {
                $join->on('contact.id', '=', 'item.contact_id')->on('contact.workspace_id', '=', 'item.workspace_id');
            })
            ->where('item.workspace_id', $snapshot->workspace_id)
            ->where('item.snapshot_id', $snapshot->getKey())
            ->whereBetween('item.position', [(int) $snapshot->start_from + 1, $endPosition])
            ->where('contact.status', 'active')
            ->whereExists(function ($grant): void {
                $grant->selectRaw('1')
                    ->from('consent_records as consent')
                    ->whereColumn('consent.workspace_id', 'item.workspace_id')
                    ->whereColumn('consent.contact_id', 'item.contact_id')
                    ->where('consent.channel', 'email')
                    ->where('consent.purpose', 'marketing')
                    ->where('consent.action', 'granted')
                    ->where('consent.occurred_at', '<=', now())
                    ->whereNotNull('consent.evidence_reference')
                    ->where('consent.evidence_reference', '<>', '')
                    ->where(function ($unexpired): void {
                        $unexpired->whereNull('consent.expires_at')->orWhere('consent.expires_at', '>', now());
                    })
                    ->whereNotExists(function ($newer): void {
                        $newer->selectRaw('1')
                            ->from('consent_records as newer_consent')
                            ->whereColumn('newer_consent.workspace_id', 'consent.workspace_id')
                            ->whereColumn('newer_consent.contact_id', 'consent.contact_id')
                            ->where('newer_consent.channel', 'email')
                            ->where('newer_consent.purpose', 'marketing')
                            ->where(function ($ordered): void {
                                $ordered->whereColumn('newer_consent.occurred_at', '>', 'consent.occurred_at')
                                    ->orWhere(function ($sameTime): void {
                                        $sameTime->whereColumn('newer_consent.occurred_at', '=', 'consent.occurred_at')
                                            ->whereColumn('newer_consent.id', '>', 'consent.id');
                                    });
                            })
                            ->where('newer_consent.occurred_at', '<=', now());
                    });
            })
            ->exists();
    }
}
