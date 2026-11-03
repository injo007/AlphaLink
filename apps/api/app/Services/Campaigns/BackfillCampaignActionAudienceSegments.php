<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\ContactListMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillCampaignActionAudienceSegments
{
    /** @var array<string, ContactListMembership|null> */
    private array $memberships = [];

    public function __construct(private readonly TrackedCountryListManager $membershipManager) {}

    /** @return array{event_sources:int, lead_conversions:int, action_segments:int} */
    public function handle(Campaign $campaign): array
    {
        $counts = ['event_sources' => 0, 'lead_conversions' => 0, 'action_segments' => 0];

        DB::table('campaign_engagement_event_sources as source')
            ->join('campaign_engagement_events as event', function ($join): void {
                $join->on('event.id', '=', 'source.engagement_event_id')
                    ->on('event.workspace_id', '=', 'source.workspace_id');
            })
            ->where('event.workspace_id', $campaign->workspace_id)
            ->where('event.campaign_id', $campaign->getKey())
            ->whereIn('event.action', ['open', 'click', 'unsubscribe'])
            ->orderBy('event.created_at')
            ->orderBy('event.id')
            ->orderBy('source.id')
            ->select([
                'event.id as event_id', 'event.revision_id', 'event.action', 'event.contact_id', 'event.created_at',
                'source.source_membership_id', 'source.source_list_id',
            ])
            ->cursor()
            ->each(function (object $row) use ($campaign, &$counts): void {
                $counts['event_sources']++;
                $membership = $this->membershipForSource(
                    (string) $campaign->workspace_id,
                    (string) $row->contact_id,
                    (string) $row->source_list_id,
                    $row->source_membership_id === null ? null : (string) $row->source_membership_id,
                );
                if (! $membership) {
                    return;
                }

                $countryCode = $this->countryCode($membership);
                if ($countryCode === null) {
                    return;
                }

                if ($this->membershipManager->recordActionSegment(
                    (string) $row->source_list_id,
                    $membership,
                    (string) $row->action,
                    $countryCode,
                    (string) $membership->email_type,
                    'campaign_tracking',
                    (string) $row->event_id,
                    Carbon::parse((string) $row->created_at),
                    [
                        'campaign_id' => (string) $campaign->getKey(),
                        'revision_id' => (string) $row->revision_id,
                        'engagement_event_id' => (string) $row->event_id,
                        'backfilled' => true,
                    ],
                )) {
                    $counts['action_segments']++;
                }
            });

        DB::table('campaign_lead_conversions')
            ->where('workspace_id', $campaign->workspace_id)
            ->where('campaign_id', $campaign->getKey())
            ->orderBy('converted_at')
            ->orderBy('id')
            ->select(['id', 'contact_id', 'audience_list_id', 'converted_at'])
            ->cursor()
            ->each(function (object $conversion) use ($campaign, &$counts): void {
                $counts['lead_conversions']++;
                $membership = $this->membershipForSource(
                    (string) $campaign->workspace_id,
                    (string) $conversion->contact_id,
                    (string) $conversion->audience_list_id,
                    null,
                );
                if (! $membership) {
                    return;
                }

                $countryCode = $this->countryCode($membership);
                if ($countryCode === null) {
                    return;
                }

                if ($this->membershipManager->recordActionSegment(
                    (string) $conversion->audience_list_id,
                    $membership,
                    'lead',
                    $countryCode,
                    (string) $membership->email_type,
                    'affiliate_conversion',
                    (string) $conversion->id,
                    Carbon::parse((string) $conversion->converted_at),
                    [
                        'campaign_id' => (string) $campaign->getKey(),
                        'conversion_id' => (string) $conversion->id,
                        'backfilled' => true,
                    ],
                )) {
                    $counts['action_segments']++;
                }
            });

        return $counts;
    }

    private function membershipForSource(
        string $workspaceId,
        string $contactId,
        string $sourceListId,
        ?string $sourceMembershipId,
    ): ?ContactListMembership {
        $cacheKey = $sourceMembershipId ?? implode('|', [$workspaceId, $contactId, $sourceListId]);
        if (array_key_exists($cacheKey, $this->memberships)) {
            return $this->memberships[$cacheKey];
        }

        if ($sourceMembershipId !== null) {
            return $this->memberships[$cacheKey] = ContactListMembership::query()
                ->with('audienceList')
                ->where('workspace_id', $workspaceId)
                ->where('contact_id', $contactId)
                ->whereKey($sourceMembershipId)
                ->first();
        }

        $memberships = ContactListMembership::query()
            ->with('audienceList')
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->whereDoesntHave('audienceList', fn ($query) => $query->where('slug', 'like', 'tracking-action-%'))
            ->orderBy('id')
            ->get();
        $membership = $memberships->count() === 1
            ? $memberships->first()
            : $memberships->first(fn (ContactListMembership $candidate): bool => (string) $candidate->audience_list_id === $sourceListId);

        return $this->memberships[$cacheKey] = $membership;
    }

    private function countryCode(ContactListMembership $membership): ?string
    {
        $countryCode = strtoupper(trim((string) $membership->audienceList?->country));

        return preg_match('/\A[A-Z]{2}\z/', $countryCode) === 1 ? $countryCode : null;
    }
}
