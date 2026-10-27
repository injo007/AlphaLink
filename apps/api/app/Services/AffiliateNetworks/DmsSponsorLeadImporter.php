<?php

namespace App\Services\AffiliateNetworks;

use App\Models\CampaignLeadConversion;
use App\Models\ContactListMembershipEvent;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\DmsCampaignAffiliateAttribution;
use App\Services\Campaigns\DmsDropAffiliateAttribution;
use App\Services\Campaigns\TrackedCountryListManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DmsSponsorLeadImporter
{
    public function __construct(
        private readonly DmsAffiliateProviderSalesReader $salesReader,
        private readonly DmsCampaignAffiliateAttribution $attribution,
        private readonly DmsDropAffiliateAttribution $dropAttribution,
        private readonly CampaignIpGeoResolver $geoResolver,
        private readonly TrackedCountryListManager $membershipManager,
    ) {}

    /**
     * Import one provider report, deduplicate by sponsor conversion ID, and advance matched recipients to lead.
     *
     * @return array{read: int, created: int, updated: int, skipped: int}
     */
    public function import(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $sales = $this->salesReader->read($sponsor, $from, $to);
        $counts = ['read' => count($sales), 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($sales as $sale) {
            $outcome = DB::transaction(function () use ($sponsor, $sale): string {
                WorkspaceSponsor::query()
                    ->where('workspace_id', $sponsor->workspace_id)
                    ->whereKey($sponsor->getKey())
                    ->lockForUpdate()
                    ->first();

                $conversionId = $sale['conversion_id'] ?? null;
                if ((! is_string($conversionId) && ! is_int($conversionId)) || trim((string) $conversionId) === '') {
                    return 'skipped';
                }

                $decodedS1 = $sale['id'] ?? null;
                if (is_string($decodedS1) && str_starts_with($decodedS1, 'C_')) {
                    $existing = CampaignLeadConversion::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->where('provider_conversion_id', (string) $conversionId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $this->updateRevenue($existing, (float) ($sale['earning'] ?? 0));
                    }

                    $attributes = $this->attribution->resolve($sponsor, $sale);

                    return $attributes === null
                        ? 'skipped'
                        : $this->importCampaignLead($sponsor, $attributes, (string) $conversionId);
                }

                if (is_string($decodedS1) && preg_match('/^D_([1-9][0-9]*)$/', $decodedS1, $dropMatch)) {
                    $dropId = filter_var($dropMatch[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($dropId === false || ! WorkspaceDrop::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->whereKey($dropId)
                        ->exists()) {
                        return 'skipped';
                    }

                    $existing = WorkspaceDropVisitor::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->where('type', 'lead')
                        ->where('revenue_id', (string) $conversionId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $this->updateDropRevenue($existing, (float) ($sale['earning'] ?? 0));
                    }

                    $dropAttributes = $this->dropAttribution->resolve($sponsor, $sale);

                    return $dropAttributes === null
                        ? 'skipped'
                        : $this->importDropLead($sponsor, $dropAttributes, (string) $conversionId);
                }

                return 'skipped';
            });

            $counts[$outcome]++;
        }

        return $counts;
    }

    /** @param array<string, mixed> $attributes */
    private function importCampaignLead(WorkspaceSponsor $sponsor, array $attributes, string $conversionId): string
    {
        $attributes['converted_at'] = $attributes['converted_at'] instanceof Carbon
            ? $attributes['converted_at']
            : Carbon::parse((string) $attributes['converted_at']);
        $location = is_string($attributes['conversion_ip'] ?? null)
            ? $this->geoResolver->resolve($sponsor->workspace, $attributes['conversion_ip'])
            : null;
        $attributes['country'] = $location['country'] ?? null;
        $attributes['state'] = $location['state'] ?? null;
        $attributes['city'] = $location['city'] ?? null;
        $conversion = CampaignLeadConversion::query()->create($attributes);

        $this->markMembershipLead(
            $sponsor,
            (string) $conversion->audience_list_id,
            (string) $conversion->contact_id,
            Carbon::parse((string) $conversion->converted_at),
            $conversionId,
            ['campaign_id' => (string) $conversion->campaign_id, 'conversion_id' => (string) $conversion->getKey()],
            $location['code'] ?? null,
            $location['country'] ?? null,
        );

        return 'created';
    }

    /** @param array{visitor: array<string, mixed>, membership: array{list_id: string, contact_id: string}|null} $attributes */
    private function importDropLead(WorkspaceSponsor $sponsor, array $attributes, string $conversionId): string
    {
        $visitorAttributes = $attributes['visitor'];
        $location = is_string($visitorAttributes['ip'] ?? null) && filter_var($visitorAttributes['ip'], FILTER_VALIDATE_IP)
            ? $this->geoResolver->resolve($sponsor->workspace, $visitorAttributes['ip'])
            : null;
        $visitorAttributes['country'] = $location['country'] ?? null;
        $visitorAttributes['state'] = $location['state'] ?? null;
        $visitorAttributes['city'] = $location['city'] ?? null;
        $visitor = WorkspaceDropVisitor::query()->create($visitorAttributes);
        $membership = $attributes['membership'];
        if ($membership !== null) {
            $this->markMembershipLead(
                $sponsor,
                $membership['list_id'],
                $membership['contact_id'],
                Carbon::parse((string) $visitor->created_at),
                $conversionId,
                ['drop_id' => (string) $visitor->workspace_drop_id, 'visitor_id' => (string) $visitor->getKey()],
                $location['code'] ?? null,
                $location['country'] ?? null,
            );
        }

        return 'created';
    }

    private function updateRevenue(CampaignLeadConversion $conversion, float $earning): string
    {
        if ((string) $conversion->revenue !== number_format($earning, 4, '.', '')) {
            $conversion->revenue = $earning;
            $conversion->save();
        }

        return 'updated';
    }

    private function updateDropRevenue(WorkspaceDropVisitor $visitor, float $earning): string
    {
        if ((string) $visitor->revenue !== number_format($earning, 4, '.', '')) {
            $visitor->revenue = $earning;
            $visitor->save();
        }

        return 'updated';
    }

    /** @param array<string, string> $metadata */
    private function markMembershipLead(
        WorkspaceSponsor $sponsor,
        string $listId,
        string $contactId,
        Carbon $occurredAt,
        string $conversionId,
        array $metadata,
        ?string $countryCode,
        ?string $countryName,
    ): void {
        $membership = $this->membershipManager->currentMembership(
            (string) $sponsor->workspace_id,
            $contactId,
            $listId,
        );
        if (! $membership) {
            return;
        }

        $moved = $this->membershipManager->moveIfTracked($membership, 'lead', $countryCode);

        $membershipEvent = ContactListMembershipEvent::query()->create([
            'workspace_id' => $sponsor->workspace_id,
            'audience_list_id' => $membership->audience_list_id,
            'contact_id' => $contactId,
            'action' => 'lead',
            'source' => 'affiliate_conversion',
            'idempotency_key' => $this->membershipEventKey($sponsor, $conversionId),
            'request_fingerprint' => hash('sha256', implode('|', [
                $sponsor->getKey(), $conversionId, $metadata['conversion_id'] ?? $metadata['visitor_id'] ?? '',
            ])),
            'actor_user_id' => null,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata + [
                'provider_type' => $sponsor->type,
                'source_audience_list_id' => $listId,
                'country' => $countryName,
                'country_list_move' => $moved,
            ],
            'created_at' => now(),
        ]);

        $membership->forceFill([
            'email_type' => 'lead',
            'last_event_id' => $membershipEvent->getKey(),
        ])->save();
    }

    private function membershipEventKey(WorkspaceSponsor $sponsor, string $conversionId): string
    {
        return 'affiliate-lead:'.$sponsor->getKey().':'.hash('sha256', $conversionId);
    }
}
