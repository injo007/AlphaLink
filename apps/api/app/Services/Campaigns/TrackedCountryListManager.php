<?php

namespace App\Services\Campaigns;

use App\Models\AudienceList;
use App\Models\ContactListMembership;
use App\Models\Isp;
use Illuminate\Support\Str;

class TrackedCountryListManager
{
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
            while (AudienceList::query()->where('workspace_id', $membership->workspace_id)->where('slug', $slug)->exists()) {
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

        $membership->forceFill(['audience_list_id' => $destination->getKey()])->save();

        return true;
    }
}
