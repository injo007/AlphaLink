<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\ContactListMembershipEvent;
use App\Models\SuppressionEvent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessCampaignTrackingEvent
{
    private const EVENT_TYPES = ['open', 'click', 'unsubscribe', 'bot'];

    /** @param array<string, mixed> $data */
    public function ingest(Campaign $campaign, Workspace $workspace, array $data): ?CampaignEngagementEvent
    {
        if ((string) $data['email_id'] === '0') {
            return null;
        }

        $metadata = app(CampaignVisitorMetadataResolver::class)->resolve(
            $workspace,
            (string) $data['ip_address'],
            (string) ($data['user_agent'] ?? ''),
            (string) $data['action'],
        );
        if ($metadata['blocked_open']) {
            return null;
        }
        $countryCode = $metadata['country_code'];
        unset($metadata['blocked_open'], $metadata['country_code']);

        return DB::transaction(function () use ($campaign, $data, $metadata, $countryCode): CampaignEngagementEvent {
            $item = CampaignRecipientSnapshotItem::query()
                ->whereKey($data['snapshot_item_id'])
                ->where('workspace_id', $campaign->workspace_id)
                ->firstOrFail();
            $snapshot = CampaignRecipientSnapshot::query()
                ->whereKey($item->snapshot_id)
                ->where('workspace_id', $campaign->workspace_id)
                ->where('campaign_id', $campaign->getKey())
                ->firstOrFail();
            $eventType = $this->sourceEmailType((string) $data['type']);

            if (
                (string) $item->contact_id !== (string) $data['email_id']
                || (string) $item->source_list_id !== (string) $data['list_id']
                || $eventType !== $item->source_email_type
            ) {
                throw ValidationException::withMessages([
                    'snapshot_item_id' => 'The tracking event does not match the campaign recipient snapshot.',
                ]);
            }

            $event = CampaignEngagementEvent::query()->create([
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $snapshot->revision_id,
                'recipient_snapshot_id' => $snapshot->getKey(),
                'snapshot_item_id' => $item->getKey(),
                'contact_id' => $item->contact_id,
                'audience_list_id' => $item->source_list_id,
                'source_email_type' => $eventType,
                'action' => $data['action'],
                'send_id' => $data['send_id'],
                'isp_id' => (int) $data['isp_id'] > 0 ? (int) $data['isp_id'] : null,
                'client_ip' => $data['ip_address'],
                'user_agent' => $data['user_agent'],
                ...$metadata,
                'dimension_ids' => [
                    'server_id' => (string) $data['server_id'],
                    'ip_id' => (string) $data['ip_id'],
                    'domain_id' => (string) $data['domain_id'],
                    'from_id' => (string) $data['from_id'],
                    'subject_id' => (string) $data['subject_id'],
                    'creative_id' => (string) $data['creative_id'],
                ],
                'token_sha256' => hash('sha256', $data['token']),
                'created_at' => now(),
            ]);

            $membershipManager = app(TrackedCountryListManager::class);
            $membership = $membershipManager->currentMembership(
                (string) $campaign->workspace_id,
                (string) $item->contact_id,
                (string) $item->source_list_id,
            );

            if ($membership) {
                $nextType = $this->nextType($membership->email_type, $data['action']);
                $moved = $membershipManager->moveIfTracked($membership, (string) $data['action'], $countryCode);
                if ($nextType !== null || $moved) {
                    $membershipEvent = ContactListMembershipEvent::query()->create([
                        'workspace_id' => $campaign->workspace_id,
                        'audience_list_id' => $membership->audience_list_id,
                        'contact_id' => $item->contact_id,
                        'action' => $data['action'],
                        'source' => 'campaign_tracking',
                        'idempotency_key' => (string) Str::uuid(),
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $event->getKey(), $membership->email_type, $nextType,
                        ])),
                        'actor_user_id' => null,
                        'occurred_at' => $event->created_at,
                        'metadata' => [
                            'campaign_id' => (string) $campaign->getKey(),
                            'revision_id' => (string) $snapshot->revision_id,
                            'engagement_event_id' => (string) $event->getKey(),
                            'from_type' => $membership->email_type,
                            'to_type' => $nextType ?? $membership->email_type,
                            'source_audience_list_id' => (string) $item->source_list_id,
                            'country_list_move' => $moved,
                        ],
                        'created_at' => $event->created_at,
                    ]);

                    $membership->forceFill([
                        'email_type' => $nextType ?? $membership->email_type,
                        'last_event_id' => $membershipEvent->getKey(),
                    ])->save();
                }
            }

            if ($data['action'] === 'unsubscribe') {
                $idempotencyKey = 'campaign-unsubscribe:'.$item->getKey();
                $alreadyRecorded = SuppressionEvent::query()
                    ->where('workspace_id', $campaign->workspace_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if (! $alreadyRecorded) {
                    SuppressionEvent::query()->create([
                        'workspace_id' => $campaign->workspace_id,
                        'contact_id' => $item->contact_id,
                        'event_type' => 'added',
                        'reason' => 'unsubscribe',
                        'scope_type' => 'offer',
                        'scope_key' => (string) $snapshot->offer_id,
                        'source' => 'campaign_unsubscribe',
                        'source_reference' => (string) $campaign->getKey(),
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $campaign->getKey(), $item->getKey(), $snapshot->offer_id,
                        ])),
                        'actor_user_id' => null,
                        'effective_at' => $event->created_at,
                        'metadata' => [
                            'campaign_id' => (string) $campaign->getKey(),
                            'audience_list_id' => (string) $item->source_list_id,
                            'engagement_event_id' => (string) $event->getKey(),
                        ],
                        'created_at' => $event->created_at,
                    ]);
                }
            }

            return $event;
        });
    }

    private function sourceEmailType(string $type): string
    {
        return match (strtolower(substr($type, 0, 1))) {
            'f' => 'fresh',
            'd' => 'delivery',
            'o' => 'open',
            'c' => 'click',
            'l' => 'lead',
            'u' => 'unsubscribe',
            default => 'NA',
        };
    }

    private function nextType(string $currentType, string $action): ?string
    {
        if (! in_array($action, self::EVENT_TYPES, true)) {
            return null;
        }

        $allowedCurrentTypes = match ($action) {
            'open' => ['fresh', 'delivery'],
            'click' => ['fresh', 'delivery', 'open'],
            'unsubscribe' => ['fresh', 'delivery', 'open', 'click'],
            'bot' => in_array($currentType, ['lead', 'bot'], true) ? [] : [$currentType],
        };

        return in_array($currentType, $allowedCurrentTypes, true) ? $action : null;
    }
}
