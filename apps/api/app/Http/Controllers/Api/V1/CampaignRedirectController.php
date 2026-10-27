<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignEngagementEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\TrackingIngestAuthenticator;
use App\Services\Campaigns\TrackingUuidNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignRedirectController extends Controller
{
    public function store(
        Request $request,
        TrackingIngestAuthenticator $authenticator,
        CampaignIpGeoResolver $geoResolver,
    ): JsonResponse {
        $normalizer = app(TrackingUuidNormalizer::class);
        $request->merge(['campaign_id' => $normalizer->canonical($request->input('campaign_id')),
            'email_id' => $normalizer->canonical($request->input('email_id'))]);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'campaign_id' => ['required', 'uuid'],
            'email_id' => ['required', 'string', 'max:64'],
            'isp_id' => ['required', 'integer', 'min:0'],
            'token' => ['required', 'string', 'max:4096'],
            'ip_address' => ['required', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:4096'],
            'type' => ['required', 'string', 'in:red,unsub'],
        ]);

        $campaign = Campaign::query()->whereKey($data['campaign_id'])->first();
        if (! $campaign) {
            return response()->json('NFE', 404);
        }

        $workspace = Workspace::query()->findOrFail($campaign->workspace_id);
        $authenticator->assertValid($workspace, $data['key']);

        $contact = $data['email_id'] !== '0' && Str::isUuid($data['email_id'])
            ? Contact::query()->where('workspace_id', $workspace->getKey())->whereKey($data['email_id'])->first()
            : null;
        if ($contact) {
            $blocked = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('contact_id', $contact->getKey())
                ->whereIn('email_type', ['bot', 'blacklist', 'proxy'])
                ->exists();
            if ($blocked) {
                return response()->json('EBE', 404);
            }
        }

        $offerId = data_get($campaign->latestRevision?->payload, 'send_setup.offer_id');
        $offer = WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($offerId)
            ->first();
        $sponsor = $offer?->sponsor;
        if (! $offer || ! $sponsor) {
            return response()->json('NFE', 404);
        }

        $genericSettings = $workspace->appSettings?->settings ?? [];
        if (! empty($offer->geo_target)) {
            $location = $geoResolver->resolve($workspace, $data['ip_address']);
            if ($location !== null && $contact) {
                $blockedType = $this->blockedRecipientType($location, $genericSettings);
                if ($blockedType !== null) {
                    $this->recordBlockedVisit(
                        $workspace,
                        $campaign,
                        $contact,
                        $blockedType,
                        $data,
                    );

                    return response()->json('TBE', 404);
                }
            }

            if (
                $location !== null
                && ! in_array('ALL', array_map('strtoupper', $offer->geo_target), true)
                && filter_var($genericSettings['filter_geo'] ?? true, FILTER_VALIDATE_BOOLEAN)
                && ! in_array(strtoupper($location['code']), array_map('strtoupper', $offer->geo_target), true)
            ) {
                return response()->json('GTE', 404);
            }
        }

        return response()->json($data['type'] === 'red'
            ? ['type' => $sponsor->type, 'link' => $offer->redirect, 'user' => $campaign->created_by]
            : ['link' => $offer->unsubscribe ?: 'unsubscribe?u=1']);
    }

    /** @param array<string, mixed> $location
     * @param  array<string, mixed>  $settings
     */
    private function blockedRecipientType(array $location, array $settings): ?string
    {
        $blockProxies = filter_var($settings['block_proxies'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $configuredOrganizations = $settings['allowed_proxies'] ?? [];
        $allowedOrganizations = is_array($configuredOrganizations)
            ? array_map('strtolower', array_filter($configuredOrganizations, 'is_string'))
            : [];
        $organization = strtolower((string) ($location['org'] ?? ''));
        $allowedProxy = collect($allowedOrganizations)->contains(fn (string $allowed): bool => $allowed !== '' && str_contains($organization, $allowed));

        if (strtoupper((string) ($location['code'] ?? '')) === 'MA') {
            return 'blacklist';
        }

        return $blockProxies && ((($location['proxy'] ?? false) && ! $allowedProxy) || ($location['hosting'] ?? false))
            ? 'proxy'
            : null;
    }

    /** @param array<string, mixed> $data */
    private function recordBlockedVisit(
        Workspace $workspace,
        Campaign $campaign,
        Contact $contact,
        string $blockedType,
        array $data,
    ): void {
        DB::transaction(function () use ($workspace, $campaign, $contact, $blockedType, $data): void {
            $tokenHash = hash('sha256', $data['token']);
            $memberships = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('contact_id', $contact->getKey())
                ->lockForUpdate()
                ->get();
            foreach ($memberships as $lockedMembership) {
                if ($lockedMembership && $lockedMembership->email_type !== 'lead' && $lockedMembership->email_type !== $blockedType) {
                    $idempotencyKey = 'campaign-redirect-type:'.hash('sha256', implode('|', [
                        $campaign->getKey(), $lockedMembership->getKey(), $blockedType, $tokenHash,
                    ]));
                    if (ContactListMembershipEvent::query()->where('workspace_id', $workspace->getKey())->where('idempotency_key', $idempotencyKey)->exists()) {
                        continue;
                    }
                    $membershipEvent = ContactListMembershipEvent::query()->create([
                        'workspace_id' => $workspace->getKey(),
                        'audience_list_id' => $lockedMembership->audience_list_id,
                        'contact_id' => $contact->getKey(),
                        'action' => $blockedType,
                        'source' => 'campaign_redirect',
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $campaign->getKey(), $lockedMembership->getKey(), $lockedMembership->email_type, $blockedType,
                        ])),
                        'actor_user_id' => null,
                        'occurred_at' => now(),
                        'metadata' => [
                            'campaign_id' => (string) $campaign->getKey(),
                            'from_type' => $lockedMembership->email_type,
                            'to_type' => $blockedType,
                        ],
                        'created_at' => now(),
                    ]);
                    $lockedMembership->forceFill([
                        'email_type' => $blockedType,
                        'last_event_id' => $membershipEvent->getKey(),
                    ])->save();
                }
            }

            $snapshotItem = CampaignRecipientSnapshotItem::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('contact_id', $contact->getKey())
                ->whereIn('snapshot_id', CampaignRecipientSnapshot::query()
                    ->select('id')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('campaign_id', $campaign->getKey()))
                ->orderByDesc('created_at')
                ->first();
            if (! $snapshotItem) {
                return;
            }

            $snapshot = CampaignRecipientSnapshot::query()->whereKey($snapshotItem->snapshot_id)->firstOrFail();
            $alreadyLogged = CampaignEngagementEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('snapshot_item_id', $snapshotItem->getKey())
                ->where('token_sha256', $tokenHash)
                ->where('action', 'ban')
                ->exists();
            if ($alreadyLogged) {
                return;
            }

            CampaignEngagementEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $snapshot->revision_id,
                'recipient_snapshot_id' => $snapshot->getKey(),
                'snapshot_item_id' => $snapshotItem->getKey(),
                'contact_id' => $snapshotItem->contact_id,
                'audience_list_id' => $snapshotItem->source_list_id,
                'source_email_type' => $snapshotItem->source_email_type,
                'action' => 'ban',
                'send_id' => null,
                'isp_id' => (int) $data['isp_id'] > 0 ? (int) $data['isp_id'] : null,
                'client_ip' => $data['ip_address'],
                'user_agent' => $data['user_agent'] ?? '',
                'dimension_ids' => [
                    'server_id' => '0', 'ip_id' => '0', 'domain_id' => '0',
                    'from_id' => '0', 'subject_id' => '0', 'creative_id' => '0',
                ],
                'token_sha256' => $tokenHash,
                'created_at' => now(),
            ]);
        });
    }
}
