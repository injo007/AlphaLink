<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\SuppressionEvent;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProcessCampaignTrackingEvent
{
    private const EVENT_TYPES = ['open', 'click', 'unsubscribe', 'bot'];

    /** @param array<string, mixed> $data */
    public function ingest(Campaign $campaign, Workspace $workspace, array $data): ?CampaignEngagementEvent
    {
        if ((string) $data['email_id'] === '0') {
            return null;
        }

        $trackingEventId = (string) ($data['tracking_event_id'] ?? Str::uuid());
        if (! Str::isUuid($trackingEventId)) {
            throw new LogicException('The tracking event request ID must be a UUID.');
        }

        $data['tracking_event_id'] = $trackingEventId;
        $existingEvent = $this->findExistingRequestEvent($trackingEventId, $campaign, $data);
        if ($existingEvent !== null) {
            return $existingEvent;
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

        return DB::transaction(function () use ($campaign, $data, $metadata, $countryCode, $trackingEventId): CampaignEngagementEvent {
            $existingEvent = $this->findExistingRequestEvent($trackingEventId, $campaign, $data);
            if ($existingEvent !== null) {
                return $existingEvent;
            }

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
            $sources = DB::table('campaign_recipient_snapshot_item_sources')
                ->where('workspace_id', $campaign->workspace_id)
                ->where('snapshot_item_id', $item->getKey())
                ->orderBy('source_list_id')
                ->orderBy('source_key')
                ->get();

            if (
                (string) $item->contact_id !== (string) $data['email_id']
                || ! $this->matchesSource($item, $sources, (string) $data['list_id'], $eventType)
            ) {
                throw ValidationException::withMessages([
                    'snapshot_item_id' => 'The tracking event does not match the campaign recipient snapshot.',
                ]);
            }

            $event = new CampaignEngagementEvent([
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $snapshot->revision_id,
                'recipient_snapshot_id' => $snapshot->getKey(),
                'snapshot_item_id' => $item->getKey(),
                'contact_id' => $item->contact_id,
                'audience_list_id' => $data['list_id'],
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
            $event->setAttribute($event->getKeyName(), $trackingEventId);
            $event->save();

            $eventSources = $sources->isEmpty()
                ? [[
                    'source_membership_id' => null,
                    'source_list_id' => (string) $item->source_list_id,
                    'source_email_type' => (string) $item->source_email_type,
                ]]
                : $sources->map(fn (object $source): array => [
                    'source_membership_id' => $source->source_membership_id === null
                        ? null
                        : (string) $source->source_membership_id,
                    'source_list_id' => (string) $source->source_list_id,
                    'source_email_type' => (string) $source->source_email_type,
                ])->all();
            $eventSourceRows = array_map(fn (array $source): array => [
                'id' => (string) Str::uuid(),
                'workspace_id' => $campaign->workspace_id,
                'engagement_event_id' => $event->getKey(),
                'source_membership_id' => $source['source_membership_id'],
                'source_list_id' => $source['source_list_id'],
                'source_email_type' => $source['source_email_type'],
                'source_key' => CampaignRecipientSourceIdentity::key(
                    $source['source_membership_id'],
                    $source['source_list_id'],
                    $source['source_email_type'],
                ),
                'created_at' => $event->created_at,
            ], $eventSources);
            foreach (array_chunk($eventSourceRows, 1000) as $sourceChunk) {
                DB::table('campaign_engagement_event_sources')->insertOrIgnore($sourceChunk);
            }

            $membershipManager = app(TrackedCountryListManager::class);
            $membershipTargets = $this->membershipTargets(
                $campaign,
                $item,
                $sources,
                $membershipManager,
            );
            foreach ($membershipTargets as $target) {
                /** @var ContactListMembership $membership */
                $membership = $target['membership'];
                $nextType = $this->nextType($membership->email_type, $data['action']);
                $moved = $membershipManager->moveIfTracked($membership, (string) $data['action'], $countryCode);
                if ($nextType !== null || $moved) {
                    $idempotencyKey = 'campaign-tracking:'.hash('sha256', $event->getKey().'|'.$membership->getKey());
                    if (ContactListMembershipEvent::query()
                        ->where('workspace_id', $campaign->workspace_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->exists()) {
                        continue;
                    }

                    $membershipEvent = ContactListMembershipEvent::query()->create([
                        'workspace_id' => $campaign->workspace_id,
                        'audience_list_id' => $membership->audience_list_id,
                        'contact_id' => $item->contact_id,
                        'action' => $data['action'],
                        'source' => 'campaign_tracking',
                        'idempotency_key' => $idempotencyKey,
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
                            'source_audience_list_id' => $target['source_list_id'],
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

    /** @param array<string, mixed> $data */
    private function findExistingRequestEvent(
        string $trackingEventId,
        Campaign $campaign,
        array $data,
    ): ?CampaignEngagementEvent {
        $event = CampaignEngagementEvent::query()->whereKey($trackingEventId)->first();
        if ($event === null) {
            return null;
        }

        $expected = [
            'workspace_id' => (string) $campaign->workspace_id,
            'campaign_id' => (string) $campaign->getKey(),
            'snapshot_item_id' => (string) $data['snapshot_item_id'],
            'contact_id' => (string) $data['email_id'],
            'audience_list_id' => (string) $data['list_id'],
            'source_email_type' => $this->sourceEmailType((string) $data['type']),
            'action' => (string) $data['action'],
            'send_id' => (string) $data['send_id'],
            'token_sha256' => hash('sha256', (string) $data['token']),
        ];

        foreach ($expected as $field => $value) {
            if ((string) $event->getAttribute($field) !== $value) {
                throw new LogicException('A tracking event request ID was reused with a different payload.');
            }
        }

        return $event;
    }

    /**
     * @param  Collection<int, object>  $sources
     * @return array<string, array{membership: ContactListMembership, source_list_id: string}>
     */
    private function membershipTargets(
        Campaign $campaign,
        CampaignRecipientSnapshotItem $item,
        Collection $sources,
        TrackedCountryListManager $membershipManager,
    ): array {
        if ($sources->isEmpty()) {
            $membership = $membershipManager->currentMembership(
                (string) $campaign->workspace_id,
                (string) $item->contact_id,
                (string) $item->source_list_id,
            );

            return $membership === null ? [] : [(string) $membership->getKey() => [
                'membership' => $membership,
                'source_list_id' => (string) $item->source_list_id,
            ]];
        }

        $membershipIds = $sources->pluck('source_membership_id')->filter()->unique()->values();
        $memberships = $membershipIds->isEmpty()
            ? collect()
            : ContactListMembership::query()
                ->where('workspace_id', $campaign->workspace_id)
                ->where('contact_id', $item->contact_id)
                ->where('status', 'active')
                ->whereIn('id', $membershipIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ContactListMembership $membership): string => (string) $membership->getKey());

        $missingMembershipListIds = $sources
            ->whereNull('source_membership_id')
            ->pluck('source_list_id')
            ->unique()
            ->values();
        if ($missingMembershipListIds->isNotEmpty()) {
            $legacyMemberships = ContactListMembership::query()
                ->where('workspace_id', $campaign->workspace_id)
                ->where('contact_id', $item->contact_id)
                ->where('status', 'active')
                ->whereIn('audience_list_id', $missingMembershipListIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($legacyMemberships as $membership) {
                $memberships->put((string) $membership->getKey(), $membership);
            }
        }

        $targets = [];
        foreach ($sources as $source) {
            $membershipId = $source->source_membership_id === null
                ? null
                : (string) $source->source_membership_id;
            $membership = $membershipId === null
                ? $memberships->first(fn (ContactListMembership $candidate): bool => (string) $candidate->audience_list_id === (string) $source->source_list_id)
                : $memberships->get($membershipId);
            if ($membership === null) {
                continue;
            }

            $targets[(string) $membership->getKey()] = [
                'membership' => $membership,
                'source_list_id' => (string) $source->source_list_id,
            ];
        }

        ksort($targets);

        return $targets;
    }

    /** @param Collection<int, object> $sources */
    private function matchesSource(
        CampaignRecipientSnapshotItem $item,
        Collection $sources,
        string $listId,
        string $emailType,
    ): bool {
        if ($sources->isEmpty()) {
            return (string) $item->source_list_id === $listId
                && (string) $item->source_email_type === $emailType;
        }

        return $sources->contains(fn (object $source): bool => (string) $source->source_list_id === $listId
            && (string) $source->source_email_type === $emailType);
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
