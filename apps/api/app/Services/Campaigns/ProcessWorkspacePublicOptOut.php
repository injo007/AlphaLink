<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class ProcessWorkspacePublicOptOut
{
    public function process(string $workspaceId, string $contactId, ?string $ip, string $userAgent): void
    {
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return;
        }

        $metadata = $ip === null
            ? [
                'country' => null,
                'country_code' => null,
                'state' => null,
                'city' => null,
                ...app(CampaignVisitorMetadataResolver::class)->parseUserAgent($userAgent),
            ]
            : app(CampaignVisitorMetadataResolver::class)->resolve($workspace, $ip, $userAgent, 'opt_out');
        unset($metadata['blocked_open'], $metadata['country_code']);

        DB::transaction(function () use ($workspace, $contactId, $ip, $userAgent, $metadata): void {
            $membership = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('contact_id', $contactId)
                ->lockForUpdate()
                ->first();
            if ($membership === null) {
                return;
            }

            $contact = Contact::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($contactId)
                ->lockForUpdate()
                ->first();
            if ($contact === null) {
                return;
            }

            $currentType = (string) $membership->email_type;
            $nextType = $currentType === 'lead' ? 'lead' : 'opt_out';
            $now = now();
            $idempotencyKey = sprintf('public-opt-out:%s:%s', $contactId, $now->toDateString());
            $event = ContactListMembershipEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($event === null) {
                $event = ContactListMembershipEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'audience_list_id' => $membership->audience_list_id,
                    'contact_id' => $contact->getKey(),
                    'action' => 'opt_out',
                    'source' => 'public_landing',
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => hash('sha256', implode('|', [
                        $workspace->getKey(), $contact->getKey(), $now->toDateString(), 'opt_out',
                    ])),
                    'actor_user_id' => null,
                    'occurred_at' => $now,
                    'metadata' => [
                        'from_type' => $currentType,
                        'to_type' => $nextType,
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'country' => $metadata['country'],
                        'state' => $metadata['state'],
                        'city' => $metadata['city'],
                        'device' => $metadata['device'],
                        'platform' => $metadata['platform'],
                        'browser' => $metadata['browser'],
                        'is_mobile' => $metadata['is_mobile'],
                        'country_list_move' => false,
                    ],
                    'created_at' => $now,
                ]);
            }

            $membership->forceFill([
                'email_type' => $nextType,
                'last_event_id' => $event->getKey(),
            ])->save();

            if (is_string($metadata['country']) && $metadata['country'] !== '') {
                $profile = is_array($contact->profile) ? $contact->profile : [];
                $contact->forceFill(['profile' => [...$profile, 'country' => $metadata['country']]])->save();
            }
        });
    }
}
