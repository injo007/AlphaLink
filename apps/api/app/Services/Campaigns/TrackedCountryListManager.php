<?php

namespace App\Services\Campaigns;

use App\Models\AudienceList;
use App\Models\ContactListMembership;
use App\Models\ContactListMembershipEvent;
use App\Models\Isp;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrackedCountryListManager
{
    /** @var array<string, AudienceList|null> */
    private array $actionLists = [];

    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'fresh' => 'fresh',
        'delivery' => 'delivery',
        'open' => 'open',
        'click' => 'click',
        'lead' => 'lead',
        'unsubscribe' => 'unsub',
        'opt_out' => 'optout',
    ];

    /**
     * Resolve the single canonical membership for a workspace contact. Generated
     * action Lists are projections and must never become the source membership.
     */
    public function currentMembership(string $workspaceId, string $contactId, string $sourceListId): ?ContactListMembership
    {
        $memberships = ContactListMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('status', 'active')
            ->whereDoesntHave('audienceList', fn ($query) => $query->where('slug', 'like', 'tracking-action-%'))
            ->lockForUpdate()
            ->get();

        if ($memberships->count() === 1) {
            return $memberships->first();
        }

        return $memberships->count() > 1
            ? $memberships->first(fn (ContactListMembership $membership): bool => (string) $membership->audience_list_id === $sourceListId)
            : null;
    }

    /**
     * Kept for callers that still record this legacy metadata. Country actions
     * now create source-prefixed projections, so the canonical membership stays
     * on the uploaded source List.
     */
    public function moveIfTracked(
        ContactListMembership $membership,
        string $action,
        ?string $countryCode,
    ): bool {
        return false;
    }

    /**
     * Materialize a unique contact in a source/action segment and, when the ISP
     * tracks that ISO country, in a source/action/country segment as well.
     * Segment email types describe the segment itself and are never overwritten
     * by a later action on the canonical membership.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordActionSegment(
        string $sourceListId,
        ContactListMembership $membership,
        string $action,
        ?string $countryCode,
        string $currentEmailType,
        string $eventSource,
        string $eventIdentity,
        DateTimeInterface $occurredAt,
        array $metadata = [],
    ): bool {
        if (! isset(self::ACTION_LABELS[$action])) {
            return false;
        }

        $countryCode = $this->normalizeCountryCode($countryCode);

        return DB::transaction(function () use (
            $sourceListId,
            $membership,
            $action,
            $countryCode,
            $currentEmailType,
            $eventSource,
            $eventIdentity,
            $occurredAt,
            $metadata,
        ): bool {
            $source = AudienceList::query()
                ->where('workspace_id', $membership->workspace_id)
                ->whereKey($sourceListId)
                ->lockForUpdate()
                ->first();

            if ($source === null || $source->segment_source_list_id !== null) {
                return false;
            }

            $this->deactivateCurrentStatusSegments($source, $membership, $occurredAt);

            $isp = $source->isp_id === null
                ? null
                : Isp::query()
                    ->where('workspace_id', $membership->workspace_id)
                    ->whereKey($source->isp_id)
                    ->lockForUpdate()
                    ->first();

            $segmentCountries = [null];
            if ($countryCode !== null && $isp !== null) {
                $trackedCountries = array_map(
                    fn (mixed $value): string => strtoupper((string) $value),
                    $isp->tracked_countries ?? [],
                );
                if (in_array($countryCode, $trackedCountries, true)) {
                    $segmentCountries[] = $countryCode;
                }
            }

            $segmentEmailType = $action === 'opt_out' ? 'opt_out' : $action;
            $segmentStatus = in_array($action, ['unsubscribe', 'opt_out'], true) ? 'inactive' : 'active';

            foreach ($segmentCountries as $segmentCountry) {
                $actionList = $this->firstOrCreateActionList($source, $isp, $action, $segmentCountry);
                if ($actionList === null) {
                    continue;
                }

                $eventIdentityParts = [$eventIdentity, $sourceListId, $action];
                if ($segmentCountry === null) {
                    $eventIdentityParts[] = 'all';
                }
                $actionListEventKey = 'tracking-action-event:'.hash('sha256', implode('|', $eventIdentityParts));
                $membershipEvent = ContactListMembershipEvent::query()
                    ->where('workspace_id', $membership->workspace_id)
                    ->where('idempotency_key', $actionListEventKey)
                    ->first();

                if ($membershipEvent === null) {
                    $membershipEvent = ContactListMembershipEvent::query()->create([
                        'workspace_id' => $membership->workspace_id,
                        'audience_list_id' => $actionList->getKey(),
                        'contact_id' => $membership->contact_id,
                        'action' => $action,
                        'source' => $eventSource,
                        'idempotency_key' => $actionListEventKey,
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $eventIdentity,
                            $sourceListId,
                            $action,
                            $membership->contact_id,
                            $currentEmailType,
                            $segmentCountry ?? 'all',
                        ])),
                        'actor_user_id' => null,
                        'occurred_at' => $occurredAt,
                        'metadata' => array_merge($metadata, [
                            'source_audience_list_id' => $sourceListId,
                            'action_audience_list_id' => (string) $actionList->getKey(),
                            'country_code' => $segmentCountry,
                            'segment_action' => $action,
                        ]),
                        'created_at' => $occurredAt,
                    ]);
                }

                $segmentMembership = ContactListMembership::query()
                    ->where('workspace_id', $membership->workspace_id)
                    ->where('audience_list_id', $actionList->getKey())
                    ->where('contact_id', $membership->contact_id)
                    ->lockForUpdate()
                    ->first();

                if ($segmentMembership === null) {
                    $segmentMembership = new ContactListMembership([
                        'workspace_id' => $membership->workspace_id,
                        'audience_list_id' => $actionList->getKey(),
                        'contact_id' => $membership->contact_id,
                        'email_type' => $segmentEmailType,
                        'status' => $segmentStatus,
                        'joined_at' => $occurredAt,
                        'left_at' => $segmentStatus === 'active' ? null : $occurredAt,
                        'last_event_id' => $membershipEvent->getKey(),
                        'profile' => $membership->profile,
                        'profile_sex_index' => $membership->profile_sex_index,
                        'email_domain_index' => $membership->email_domain_index,
                        'attribute_index_version' => $membership->attribute_index_version,
                    ]);
                    $segmentMembership->save();
                } else {
                    $segmentMembership->forceFill([
                        'email_type' => $segmentEmailType,
                        'status' => $segmentStatus,
                        'left_at' => $segmentStatus === 'active' ? null : ($segmentMembership->left_at ?? $occurredAt),
                        'last_event_id' => $membershipEvent->getKey(),
                    ])->save();
                }
            }

            return true;
        });
    }

    public function countryCodeForMembership(ContactListMembership $membership): ?string
    {
        $profile = is_array($membership->profile) ? $membership->profile : [];
        foreach (['country_code', 'countryCode', 'country'] as $key) {
            $countryCode = $this->normalizeCountryCode($profile[$key] ?? null);
            if ($countryCode !== null) {
                return $countryCode;
            }
        }

        return $this->normalizeCountryCode($membership->audienceList?->country);
    }

    private function firstOrCreateActionList(
        AudienceList $source,
        ?Isp $isp,
        string $action,
        ?string $countryCode,
    ): ?AudienceList {
        $actionLabel = self::ACTION_LABELS[$action];
        $scope = $countryCode ?? 'ALL';
        $cacheKey = implode('|', [(string) $source->workspace_id, (string) $source->getKey(), $scope, $action]);
        if (array_key_exists($cacheKey, $this->actionLists)) {
            return $this->actionLists[$cacheKey];
        }

        $slug = $this->actionListSlug((string) $source->workspace_id, (string) $source->getKey(), $scope, $action);
        $suffix = '_'.$actionLabel.($countryCode === null ? '' : '_'.strtolower($countryCode));
        $sourceName = Str::limit($source->name, max(1, 160 - Str::length($suffix)), '');
        $name = $sourceName.$suffix;
        $idempotencyKey = 'tracking-action-list:'.hash('sha256', implode('|', [
            $source->workspace_id,
            $source->getKey(),
            $scope,
            $action,
        ]));
        $existing = AudienceList::withTrashed()
            ->where('workspace_id', $source->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                return $this->actionLists[$cacheKey] = null;
            }

            $existing->forceFill([
                'name' => $name,
                'description' => 'Tracked '.$actionLabel.' audience from '.$source->name
                    .($countryCode === null ? '.' : ' in '.$countryCode.'.'),
                'isp_id' => $source->isp_id,
                'country' => $countryCode,
                'segment_source_list_id' => $source->getKey(),
                'segment_action' => $action,
                'segment_country_code' => $countryCode,
                'allowed_users' => $source->allowed_users ?? [],
                'allowed_sponsors' => $source->allowed_sponsors ?? [],
                'is_tracked' => (bool) $source->is_tracked,
            ])->save();

            return $this->actionLists[$cacheKey] = $existing;
        }

        $now = now();

        DB::table('audience_lists')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'workspace_id' => $source->workspace_id,
            'created_by' => $source->created_by,
            'name' => $name,
            'slug' => $slug,
            'description' => 'Tracked '.$actionLabel.' audience from '.$source->name
                .($countryCode === null ? '.' : ' in '.$countryCode.'.'),
            'status' => $source->status,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', implode('|', [$source->getKey(), $scope, $action])),
            'isp_id' => $isp?->getKey() ?? $source->isp_id,
            'country' => $countryCode,
            'segment_source_list_id' => $source->getKey(),
            'segment_action' => $action,
            'segment_country_code' => $countryCode,
            'allowed_users' => json_encode($source->allowed_users ?? [], JSON_THROW_ON_ERROR),
            'allowed_sponsors' => json_encode($source->allowed_sponsors ?? [], JSON_THROW_ON_ERROR),
            'is_tracked' => (bool) $source->is_tracked,
            'is_fresh_locked' => (bool) $source->is_fresh_locked,
            'is_delivery_locked' => (bool) $source->is_delivery_locked,
            'is_open_locked' => (bool) $source->is_open_locked,
            'is_click_locked' => (bool) $source->is_click_locked,
            'is_lead_locked' => (bool) $source->is_lead_locked,
            'is_unsubscribe_locked' => (bool) $source->is_unsubscribe_locked,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actionLists[$cacheKey] = AudienceList::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        return $this->actionLists[$cacheKey];
    }

    private function deactivateCurrentStatusSegments(
        AudienceList $source,
        ContactListMembership $membership,
        DateTimeInterface $occurredAt,
    ): void {
        $currentStatusListIds = AudienceList::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('segment_source_list_id', $source->getKey())
            ->whereIn('segment_action', ['fresh', 'delivery'])
            ->pluck('id');

        if ($currentStatusListIds->isEmpty()) {
            return;
        }

        ContactListMembership::query()
            ->where('workspace_id', $membership->workspace_id)
            ->whereIn('audience_list_id', $currentStatusListIds)
            ->where('contact_id', $membership->contact_id)
            ->where('status', 'active')
            ->update([
                'status' => 'inactive',
                'left_at' => $occurredAt,
                'updated_at' => now(),
            ]);
    }

    private function actionListSlug(string $workspaceId, string $sourceListId, string $scope, string $action): string
    {
        $identity = hash('sha256', implode('|', [$workspaceId, $sourceListId, $scope, $action]));

        return 'tracking-action-'.$identity;
    }

    private function normalizeCountryCode(mixed $countryCode): ?string
    {
        if (! is_string($countryCode)) {
            return null;
        }

        $countryCode = strtoupper(trim($countryCode));

        return preg_match('/\A[A-Z]{2}\z/', $countryCode) === 1 ? $countryCode : null;
    }
}
