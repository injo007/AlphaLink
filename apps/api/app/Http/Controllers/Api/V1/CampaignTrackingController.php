<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignTrackingEventJob;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\Workspace;
use App\Services\Campaigns\TrackingIngestAuthenticator;
use App\Services\Campaigns\TrackingUuidNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignTrackingController extends Controller
{
    public function store(Request $request, TrackingIngestAuthenticator $authenticator): Response
    {
        $normalizer = app(TrackingUuidNormalizer::class);
        foreach (['campaign_id', 'send_id', 'email_id', 'list_id', 'snapshot_item_id'] as $field) {
            if ($request->exists($field)) {
                $request->merge([$field => $normalizer->canonical($request->input($field))]);
            }
        }
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'action' => ['required', 'string', 'in:click,unsubscribe,open,bot'],
            'ip_address' => ['required', 'ip'],
            'user_agent' => ['required', 'string', 'max:4096'],
            'campaign_id' => ['required', 'uuid', 'exists:campaigns,id'],
            'send_id' => ['required', 'string', 'max:128'],
            'email_id' => ['required', 'string', 'max:64'],
            'snapshot_item_id' => ['nullable', 'uuid'],
            'type' => ['required', 'string', 'max:24'],
            'list_id' => ['required', 'string', 'max:64'],
            'isp_id' => ['required', 'integer', 'min:0'],
            'server_id' => ['required', 'string', 'max:128'],
            'ip_id' => ['required', 'string', 'max:128'],
            'domain_id' => ['required', 'string', 'max:128'],
            'from_id' => ['required', 'string', 'max:128'],
            'subject_id' => ['required', 'string', 'max:128'],
            'creative_id' => ['required', 'string', 'max:128'],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $campaign = Campaign::query()->whereKey($data['campaign_id'])->firstOrFail();
        $workspace = Workspace::query()->findOrFail($campaign->workspace_id);
        $authenticator->assertValid($workspace, $data['key']);

        if ($data['email_id'] !== '0') {
            if (! Str::isUuid($data['email_id']) || ! Str::isUuid($data['list_id'])) {
                throw ValidationException::withMessages([
                    'snapshot_item_id' => 'A campaign recipient snapshot is required for non-test tracking events.',
                ]);
            }

            $sourceType = match (strtolower(substr($data['type'], 0, 1))) {
                'f' => 'fresh', 'd' => 'delivery', 'o' => 'open', 'c' => 'click',
                'l' => 'lead', 'u' => 'unsubscribe', default => 'NA',
            };
            if (isset($data['snapshot_item_id'])) {
                $item = CampaignRecipientSnapshotItem::query()
                    ->whereKey($data['snapshot_item_id'])
                    ->where('workspace_id', $workspace->getKey())
                    ->where('contact_id', $data['email_id'])
                    ->first();
                $snapshotMatchesCampaign = $item !== null && CampaignRecipientSnapshot::query()
                    ->whereKey($item->snapshot_id)
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->exists();
                if (! $snapshotMatchesCampaign || ! $this->sourceMatches($item, (string) $data['list_id'], $sourceType)) {
                    throw ValidationException::withMessages([
                        'snapshot_item_id' => 'The tracking event does not match the campaign recipient snapshot.',
                    ]);
                }
            } else {
                $snapshot = CampaignRecipientSnapshot::query()
                    ->whereKey($data['send_id'])
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey())
                    ->first();
                $item = $snapshot === null ? null : CampaignRecipientSnapshotItem::query()
                    ->where('snapshot_id', $snapshot->getKey())
                    ->where('workspace_id', $workspace->getKey())
                    ->where('contact_id', $data['email_id'])
                    ->first();
                if ($item !== null && ! $this->sourceMatches($item, (string) $data['list_id'], $sourceType)) {
                    $item = null;
                }
                if ($item === null) {
                    throw ValidationException::withMessages([
                        'snapshot_item_id' => 'The tracking event does not match a campaign recipient snapshot.',
                    ]);
                }
                $data['snapshot_item_id'] = (string) $item->getKey();
            }

            ProcessCampaignTrackingEventJob::dispatch($campaign, $workspace, $data);
        }

        return response()->noContent();
    }

    private function sourceMatches(CampaignRecipientSnapshotItem $item, string $listId, string $emailType): bool
    {
        $sourceQuery = DB::table('campaign_recipient_snapshot_item_sources')
            ->where('workspace_id', $item->workspace_id)
            ->where('snapshot_item_id', $item->getKey());

        if (! (clone $sourceQuery)->exists()) {
            return (string) $item->source_list_id === $listId
                && (string) $item->source_email_type === $emailType;
        }

        return $sourceQuery->where('source_list_id', $listId)
            ->where('source_email_type', $emailType)
            ->exists();
    }
}
