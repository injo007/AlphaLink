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
    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'open' => 'Open',
        'click' => 'Click',
        'lead' => 'Lead',
        'unsubscribe' => 'Unsub',
    ];

    /**
     * DMS keeps one current list on each email. AlphaLink's importer enforces
     * one membership per contact in a workspace, so this returns that current
     * membership even when an immutable campaign snapshot still names its old
     * source list. Ambiguous legacy memberships are left untouched.
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
     * Move the current membership into the DMS-style country list when the
     * source list is tracked and its ISP has enabled the resolved country.
     */
    public function moveIfTracked(
        ContactListMembership $membership,
        string $action,
        ?string $countryCode,
    ): bool {
        if (! in_array($action, ['open', 'click', 'lead', 'unsubscribe'], true) || ! $countryCode) {
            return false;
        }

        $source = AudienceList::query()
            ->where('workspace_id', $membership->workspace_id)
            ->whereKey($membership->audience_list_id)
            ->lockForUpdate()
            ->first();

        if (! $source?->is_tracked || $source->isp_id === null) {
            return false;
        }

        $isp = Isp::query()
            ->where('workspace_id', $membership->workspace_id)
            ->whereKey($source->isp_id)
            ->lockForUpdate()
            ->first();

        if (! $isp || ! in_array($countryCode, $isp->tracked_countries ?? [], true)) {
            return false;
        }

        $destination = AudienceList::query()
            ->where('workspace_id', $membership->workspace_id)
            ->where('isp_id', $isp->getKey())
            ->where('name', $countryCode)
            ->lockForUpdate()
            ->first();

        if (! $destination) {
            $slugBase = Str::limit(Str::slug($countryCode.'-isp-'.$isp->getKey()) ?: 'tracked-country', 145, '');
            $slug = $slugBase;
            while (AudienceList::withTrashed()->where('workspace_id', $membership->workspace_id)->where('slug', $slug)->exists()) {
                $slug = $slugBase.'-'.Str::lower(Str::random(8));
            }

            $destination = AudienceList::query()->create([
                'workspace_id' => $membership->workspace_id,
                'created_by' => $source->created_by,
                'name' => $countryCode,
                'slug' => $slug,
                'description' => null,
                'status' => $source->status,
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', implode('|', [
                    $membership->workspace_id,
                    $isp->getKey(),
                    $countryCode,
                ])),
                'isp_id' => $isp->getKey(),
                'country' => $countryCode,
                'allowed_users' => $source->allowed_users ?? [],
                'allowed_sponsors' => $source->allowed_sponsors ?? [],
                'is_tracked' => true,
                'is_fresh_locked' => (bool) $source->is_fresh_locked,
                'is_delivery_locked' => (bool) $source->is_delivery_locked,
                'is_open_locked' => (bool) $source->is_open_locked,
                'is_click_locked' => (bool) $source->is_click_locked,
                'is_lead_locked' => (bool) $source->is_lead_locked,
                'is_unsubscribe_locked' => (bool) $source->is_unsubscribe_locked,
            ]);
        }

        if ((string) $destination->getKey() === (string) $membership->audience_list_id) {
            return false;
        }

        $destinationAlreadyContainsContact = ContactListMembership::query()
            ->where('workspace_id', $membership->workspace_id)
            ->where('audience_list_id', $destination->getKey())
            ->where('contact_id', $membership->contact_id)
            ->where('id', '!=', $membership->getKey())
            ->exists();
        if ($destinationAlreadyContainsContact) {
            return false;
        }

        $membership->forceFill(['audience_list_id' => $destination->getKey()])->save();

        return true;
    }

    /**
     * Keep a durable, source-prefixed audience segment for each tracked action.
     * The campaign's current membership remains governed by the normal state transition.
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
        if (! isset(self::ACTION_LABELS[$action]) || $countryCode === null) {
            return false;
        }

        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/\A[A-Z]{2}\z/', $countryCode) !== 1) {
            return false;
        }

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

            if (! $source?->is_tracked || $source->isp_id === null) {
                return false;
            }

            $isp = Isp::query()
                ->where('workspace_id', $membership->workspace_id)
                ->whereKey($source->isp_id)
                ->lockForUpdate()
                ->first();

            $trackedCountries = array_map('strtoupper', $isp?->tracked_countries ?? []);
            if (! $isp || ! in_array($countryCode, $trackedCountries, true)) {
                return false;
            }

            $actionList = $this->firstOrCreateActionList($source, $isp, $countryCode, $action);
            if ($actionList === null) {
                return false;
            }

            $actionListEventKey = 'tracking-action-event:'.hash('sha256', implode('|', [
                $eventIdentity,
                $sourceListId,
                $action,
            ]));
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
                    ])),
                    'actor_user_id' => null,
                    'occurred_at' => $occurredAt,
                    'metadata' => array_merge($metadata, [
                        'source_audience_list_id' => $sourceListId,
                        'action_audience_list_id' => (string) $actionList->getKey(),
                        'country_code' => $countryCode,
                    ]),
                    'created_at' => $occurredAt,
                ]);
            }

            $existingSegmentListIds = AudienceList::query()
                ->where('workspace_id', $membership->workspace_id)
                ->whereIn('slug', array_map(
                    fn (string $segmentAction): string => $this->actionListSlug(
                        $membership->workspace_id,
                        $sourceListId,
                        $countryCode,
                        $segmentAction,
                    ),
                    array_keys(self::ACTION_LABELS),
                ))
                ->pluck('id');

            if ($existingSegmentListIds->isNotEmpty()) {
                ContactListMembership::query()
                    ->where('workspace_id', $membership->workspace_id)
                    ->whereIn('audience_list_id', $existingSegmentListIds)
                    ->where('contact_id', $membership->contact_id)
                    ->update([
                        'email_type' => $currentEmailType,
                        'status' => $membership->status,
                        'left_at' => $membership->left_at,
                        'updated_at' => now(),
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
                    'email_type' => $currentEmailType,
                    'status' => $membership->status,
                    'joined_at' => $membership->joined_at ?? $occurredAt,
                    'left_at' => $membership->left_at,
                    'last_event_id' => $membershipEvent->getKey(),
                    'profile' => $membership->profile,
                    'profile_sex_index' => $membership->profile_sex_index,
                    'email_domain_index' => $membership->email_domain_index,
                    'attribute_index_version' => $membership->attribute_index_version,
                ]);
            } else {
                $segmentMembership->forceFill([
                    'email_type' => $currentEmailType,
                    'status' => $membership->status,
                    'left_at' => $membership->left_at,
                    'last_event_id' => $membershipEvent->getKey(),
                ]);
            }

            $segmentMembership->save();

            return true;
        });
    }

    private function firstOrCreateActionList(AudienceList $source, Isp $isp, string $countryCode, string $action): ?AudienceList
    {
        $slug = $this->actionListSlug($source->workspace_id, (string) $source->getKey(), $countryCode, $action);
        $actionLabel = self::ACTION_LABELS[$action];
        $suffix = '_'.$countryCode.'_'.$actionLabel;
        $sourceName = Str::limit($source->name, max(1, 160 - Str::length($suffix)), '');
        $name = $sourceName.$suffix;
        $idempotencyKey = 'tracking-action-list:'.hash('sha256', implode('|', [
            $source->workspace_id,
            $source->getKey(),
            $countryCode,
            $action,
        ]));
        $existing = AudienceList::withTrashed()
            ->where('workspace_id', $source->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing->trashed() ? null : $existing;
        }

        $now = now();

        DB::table('audience_lists')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'workspace_id' => $source->workspace_id,
            'created_by' => $source->created_by,
            'name' => $name,
            'slug' => $slug,
            'description' => 'Tracked '.$actionLabel.' audience from '.$source->name.' in '.$countryCode.'.',
            'status' => $source->status,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', implode('|', [$source->getKey(), $countryCode, $action])),
            'isp_id' => $isp->getKey(),
            'country' => $countryCode,
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

        return AudienceList::query()
            ->where('workspace_id', $source->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();
    }

    private function actionListSlug(string $workspaceId, string $sourceListId, string $countryCode, string $action): string
    {
        $identity = hash('sha256', implode('|', [$workspaceId, $sourceListId, $countryCode, $action]));

        return 'tracking-action-'.$identity;
    }
}
