<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Support\Str;

class DmsCampaignAffiliateAttribution
{
    /**
     * Resolve the DMS s1/s2 campaign sale contract to AlphaLink's immutable recipient snapshot.
     * AlphaLink UUIDs are transported as 32-character hex components so the DMS hyphen format remains parseable.
     *
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>|null
     */
    public function resolve(WorkspaceSponsor $sponsor, array $sale): ?array
    {
        $decodedS1 = $sale['id'] ?? null;
        if (! is_string($decodedS1) || ! preg_match('/^C_(.+)$/', $decodedS1, $campaignMatch)) {
            return null;
        }

        $campaignId = $this->uuid($campaignMatch[1]);
        if ($campaignId === null) {
            return null;
        }

        $campaign = Campaign::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->whereKey($campaignId)
            ->first();
        if (! $campaign) {
            return null;
        }

        $conversionId = $sale['conversion_id'] ?? null;
        $earning = $sale['earning'] ?? null;
        if ((! is_string($conversionId) && ! is_int($conversionId))
            || trim((string) $conversionId) === ''
            || strlen((string) $conversionId) > 255
            || ! is_numeric($earning)
            || ! is_finite((float) $earning)) {
            return null;
        }

        $parts = explode('-', (string) ($sale['s2'] ?? ''));
        if (count($parts) < 14) {
            return null;
        }

        $tokenParts = explode('_', $parts[12], 2);
        $expectedApp = hash('adler32', (string) config('app.name', 'NA'), false);
        if (isset($tokenParts[1]) && ! hash_equals($expectedApp, $tokenParts[1])) {
            return null;
        }

        $s2CampaignId = $this->uuid($parts[0]);
        $snapshotId = $this->uuid($parts[1]);
        $contactId = $this->uuid($parts[2]);
        $listId = $this->uuid($parts[4]);
        $emailType = $this->emailType($parts[3]);
        if ($s2CampaignId !== $campaignId || $snapshotId === null || $contactId === null || $listId === null || $emailType === null) {
            return null;
        }

        $snapshot = CampaignRecipientSnapshot::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->whereKey($snapshotId)
            ->where('campaign_id', $campaignId)
            ->first();
        if (! $snapshot) {
            return null;
        }

        $offer = WorkspaceOffer::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->whereKey($snapshot->offer_id)
            ->where('sponsor_id', $sponsor->getKey())
            ->first();
        if (! $offer) {
            return null;
        }

        $item = CampaignRecipientSnapshotItem::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->where('snapshot_id', $snapshotId)
            ->where('contact_id', $contactId)
            ->where('source_list_id', $listId)
            ->where('source_email_type', $emailType)
            ->first();
        if (! $item) {
            return null;
        }

        $revision = CampaignRevision::query()->whereKey($snapshot->revision_id)->first();
        $revisionIspId = $revision?->payload['send_setup']['isp_id'] ?? null;
        $saleIspId = is_numeric($parts[5]) && (int) $parts[5] > 0 ? (int) $parts[5] : null;
        if ($revisionIspId !== null && $saleIspId !== null && (int) $revisionIspId !== $saleIspId) {
            return null;
        }

        $ispId = $saleIspId !== null && Isp::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->whereKey($saleIspId)
            ->exists()
            ? $saleIspId
            : null;

        return [
            'workspace_id' => $sponsor->workspace_id,
            'campaign_id' => $campaignId,
            'revision_id' => $snapshot->revision_id,
            'recipient_snapshot_id' => $snapshotId,
            'snapshot_item_id' => $item->getKey(),
            'contact_id' => $item->contact_id,
            'audience_list_id' => $item->source_list_id,
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'provider_type' => $sponsor->type,
            'provider_conversion_id' => (string) $conversionId,
            'revenue' => round((float) $earning, 4),
            'conversion_ip' => filter_var($sale['ip'] ?? null, FILTER_VALIDATE_IP) ? $sale['ip'] : null,
            'isp_id' => $ispId,
            'dimension_ids' => [
                'server_id' => $parts[6],
                'ip_id' => $parts[7],
                'domain_id' => $parts[8],
                'from_id' => $parts[9],
                'subject_id' => $parts[10],
                'creative_id' => $parts[11],
            ],
            'converted_at' => $sale['created_at'] ?? now(),
        ];
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

    private function emailType(string $type): ?string
    {
        $mapped = match (strtolower(substr($type, 0, 1))) {
            'f' => 'fresh',
            'd' => 'delivery',
            'o' => 'open',
            'c' => 'click',
            'l' => 'lead',
            'u' => 'unsubscribe',
            default => null,
        };

        return $mapped !== null && in_array($mapped, ContactListMembership::CAMPAIGN_TYPES, true) ? $mapped : null;
    }
}
