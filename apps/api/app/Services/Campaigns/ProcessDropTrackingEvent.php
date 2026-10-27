<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use App\Models\SuppressionEvent;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessDropTrackingEvent
{
    /** @param array<string, mixed> $data */
    public function ingest(WorkspaceDrop $drop, Workspace $workspace, array $data): ?WorkspaceDropVisitor
    {
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

        return DB::transaction(function () use ($drop, $data, $metadata, $countryCode): WorkspaceDropVisitor {
            $emailId = $this->uuid((string) $data['email_id']);
            $listId = $this->uuid((string) $data['list_id']);
            $contact = $emailId === null ? null : Contact::query()
                ->where('workspace_id', $drop->workspace_id)
                ->whereKey($emailId)
                ->first();
            $ispId = (int) $data['isp_id'] > 0 && Isp::query()
                ->where('workspace_id', $drop->workspace_id)
                ->whereKey((int) $data['isp_id'])
                ->exists()
                ? (int) $data['isp_id']
                : null;
            $event = WorkspaceDropVisitor::query()->create([
                'workspace_id' => $drop->workspace_id,
                'workspace_drop_id' => $drop->getKey(),
                'type' => $data['action'],
                'revenue' => 0,
                'ip' => $data['ip_address'],
                ...$metadata,
                'server_id' => $this->identifier((string) $data['server_id']),
                'ip_id' => $this->identifier((string) $data['ip_id']),
                'domain_id' => $this->identifier((string) $data['domain_id']),
                'from_id' => $this->identifier((string) $data['from_id']),
                'subject_id' => $this->identifier((string) $data['subject_id']),
                'creative_id' => $this->identifier((string) $data['creative_id']),
                'isp_id' => $ispId,
                'list_id' => $this->identifier((string) $data['list_id']),
                'list_type' => $this->sourceEmailType((string) $data['type']),
                'email_id' => $this->identifier((string) $data['email_id']) ?? '0',
                'token_sha256' => hash('sha256', (string) $data['token']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dropListIds = collect($drop->lists ?? [])
                ->map(fn ($id): ?string => is_string($id) ? ($this->uuid($id) ?? $id) : (string) $id);
            $membershipManager = app(TrackedCountryListManager::class);
            $membership = $contact !== null && $listId !== null && $dropListIds->contains($listId)
                ? $membershipManager->currentMembership(
                    (string) $drop->workspace_id,
                    (string) $contact->getKey(),
                    $listId,
                )
                : null;

            if ($membership !== null) {
                $nextType = $this->nextType($membership->email_type, (string) $data['action']);
                $moved = $membershipManager->moveIfTracked($membership, (string) $data['action'], $countryCode);
                if ($nextType !== null || $moved) {
                    $membershipEvent = ContactListMembershipEvent::query()->create([
                        'workspace_id' => $drop->workspace_id,
                        'audience_list_id' => $membership->audience_list_id,
                        'contact_id' => $membership->contact_id,
                        'action' => $data['action'],
                        'source' => 'drop_tracking',
                        'idempotency_key' => (string) Str::uuid(),
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $event->getKey(), $membership->email_type, $nextType,
                        ])),
                        'actor_user_id' => null,
                        'occurred_at' => $event->created_at,
                        'metadata' => [
                            'drop_id' => (string) $drop->getKey(),
                            'visitor_id' => (string) $event->getKey(),
                            'from_type' => $membership->email_type,
                            'to_type' => $nextType ?? $membership->email_type,
                            'source_audience_list_id' => $listId,
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

            if ($data['action'] === 'unsubscribe' && $contact !== null && $drop->offer_id !== null) {
                $offer = WorkspaceOffer::query()
                    ->where('workspace_id', $drop->workspace_id)
                    ->whereKey($drop->offer_id)
                    ->first();
                if ($offer !== null) {
                    $idempotencyKey = 'drop-unsubscribe:'.$offer->getKey().':'.$contact->getKey();
                    if (! SuppressionEvent::query()
                        ->where('workspace_id', $drop->workspace_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->exists()) {
                        SuppressionEvent::query()->create([
                            'workspace_id' => $drop->workspace_id,
                            'contact_id' => $contact->getKey(),
                            'event_type' => 'added',
                            'reason' => 'unsubscribe',
                            'scope_type' => 'offer',
                            'scope_key' => (string) $offer->getKey(),
                            'source' => 'drop_unsubscribe',
                            'source_reference' => (string) $drop->getKey(),
                            'idempotency_key' => $idempotencyKey,
                            'request_fingerprint' => hash('sha256', implode('|', [
                                $drop->workspace_id, $drop->getKey(), $contact->getKey(), $offer->getKey(),
                            ])),
                            'actor_user_id' => null,
                            'effective_at' => $event->created_at,
                            'metadata' => [
                                'drop_id' => (string) $drop->getKey(),
                                'audience_list_id' => $listId,
                                'visitor_id' => (string) $event->getKey(),
                            ],
                            'created_at' => $event->created_at,
                        ]);
                    }
                }
            }

            return $event;
        });
    }

    private function nextType(string $currentType, string $action): ?string
    {
        $allowedCurrentTypes = match ($action) {
            'open' => ['fresh', 'delivery'],
            'click' => ['fresh', 'delivery', 'open'],
            'unsubscribe' => ['fresh', 'delivery', 'open', 'click'],
            'bot' => in_array($currentType, ['lead', 'bot'], true) ? [] : [$currentType],
            default => [],
        };

        return in_array($currentType, $allowedCurrentTypes, true) ? $action : null;
    }

    private function sourceEmailType(string $type): string
    {
        return match ($type) {
            'f' => 'fresh',
            'd' => 'delivery',
            'o' => 'open',
            'c' => 'click',
            'l' => 'lead',
            'u' => 'unsubscribe',
            default => 'NA',
        };
    }

    private function identifier(string $value): ?string
    {
        return $this->uuid($value) ?? (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value) ? $value : null);
    }

    private function uuid(string $value): ?string
    {
        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        if (! preg_match('/^[a-f0-9]{32}$/i', $value)) {
            return null;
        }

        return strtolower(substr($value, 0, 8).'-'.substr($value, 8, 4).'-'.substr($value, 12, 4).'-'.substr($value, 16, 4).'-'.substr($value, 20));
    }
}
