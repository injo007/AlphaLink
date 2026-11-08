<?php

namespace App\Services\AffiliateNetworks;

use App\Models\CampaignLeadConversion;
use App\Models\ContactListMembershipEvent;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceSponsor;
use App\Models\WorkspaceSponsorRevenue;
use App\Services\Campaigns\AlphaLinkCampaignAffiliateAttribution;
use App\Services\Campaigns\AlphaLinkDropAffiliateAttribution;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\TrackedCountryListManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AlphaLinkSponsorLeadImporter
{
    public function __construct(
        private readonly AlphaLinkAffiliateProviderSalesReader $salesReader,
        private readonly AlphaLinkCampaignAffiliateAttribution $attribution,
        private readonly AlphaLinkDropAffiliateAttribution $dropAttribution,
        private readonly CampaignIpGeoResolver $geoResolver,
        private readonly TrackedCountryListManager $membershipManager,
    ) {}

    /**
     * Import one provider report, deduplicate by sponsor conversion ID, and advance matched recipients to lead.
     *
     * @return array{read: int, created: int, updated: int, skipped: int, reported_created: int, reported_updated: int, reported_unchanged: int, reported_skipped: int}
     */
    public function import(WorkspaceSponsor $sponsor, Carbon $from, Carbon $to): array
    {
        $sales = $this->salesReader->read($sponsor, $from, $to);
        $counts = [
            'read' => count($sales),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'reported_created' => 0,
            'reported_updated' => 0,
            'reported_unchanged' => 0,
            'reported_skipped' => 0,
        ];

        foreach ($sales as $sale) {
            [$outcome, $reportedOutcome] = DB::transaction(function () use ($sponsor, $sale): array {
                WorkspaceSponsor::query()
                    ->where('workspace_id', $sponsor->workspace_id)
                    ->whereKey($sponsor->getKey())
                    ->lockForUpdate()
                    ->first();

                $reportedOutcome = $this->syncReportedRevenue($sponsor, $sale);

                $conversionId = $sale['conversion_id'] ?? null;
                if ((! is_string($conversionId) && ! is_int($conversionId)) || trim((string) $conversionId) === '') {
                    return ['skipped', $reportedOutcome];
                }

                $decodedS1 = $sale['id'] ?? null;
                if (is_string($decodedS1) && str_starts_with($decodedS1, 'C_')) {
                    $existing = CampaignLeadConversion::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->where('provider_conversion_id', (string) $conversionId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return [$this->updateRevenue($existing, (float) ($sale['earning'] ?? 0)), $reportedOutcome];
                    }

                    $attributes = $this->attribution->resolve($sponsor, $sale);

                    return [
                        $attributes === null ? 'skipped' : $this->importCampaignLead($sponsor, $attributes, (string) $conversionId),
                        $reportedOutcome,
                    ];
                }

                if (is_string($decodedS1) && preg_match('/^D_([1-9][0-9]*)$/', $decodedS1, $dropMatch)) {
                    $dropId = filter_var($dropMatch[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($dropId === false || ! WorkspaceDrop::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->whereKey($dropId)
                        ->exists()) {
                        return ['skipped', $reportedOutcome];
                    }

                    $existing = WorkspaceDropVisitor::query()
                        ->where('workspace_id', $sponsor->workspace_id)
                        ->where('type', 'lead')
                        ->where('revenue_id', (string) $conversionId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return [$this->updateDropRevenue($existing, (float) ($sale['earning'] ?? 0)), $reportedOutcome];
                    }

                    $dropAttributes = $this->dropAttribution->resolve($sponsor, $sale);

                    return [
                        $dropAttributes === null ? 'skipped' : $this->importDropLead($sponsor, $dropAttributes, (string) $conversionId),
                        $reportedOutcome,
                    ];
                }

                return ['skipped', $reportedOutcome];
            });

            $counts[$outcome]++;
            $counts['reported_'.$reportedOutcome]++;
        }

        return $counts;
    }

    /** @param array<string, mixed> $sale */
    private function syncReportedRevenue(WorkspaceSponsor $sponsor, array $sale): string
    {
        $conversionId = $sale['conversion_id'] ?? null;
        $earning = $sale['earning'] ?? null;
        $occurredAt = $sale['created_at'] ?? null;

        if ((! is_string($conversionId) && ! is_int($conversionId))
            || trim((string) $conversionId) === ''
            || strlen((string) $conversionId) > 255
            || ! is_numeric($earning)
            || ! is_finite((float) $earning)
            || (! $occurredAt instanceof Carbon && ! is_string($occurredAt))) {
            return 'skipped';
        }

        $occurredAt = $occurredAt instanceof Carbon ? $occurredAt : Carbon::parse($occurredAt);
        $record = WorkspaceSponsorRevenue::query()
            ->where('workspace_id', $sponsor->workspace_id)
            ->where('sponsor_id', $sponsor->getKey())
            ->where('provider_conversion_id', (string) $conversionId)
            ->lockForUpdate()
            ->first();

        $attributes = [
            'workspace_id' => $sponsor->workspace_id,
            'sponsor_id' => $sponsor->getKey(),
            'provider_type' => $sponsor->type,
            'provider_conversion_id' => (string) $conversionId,
            'amount' => round((float) $earning, 4),
            'currency_code' => is_string($sale['currency_code'] ?? null)
                && preg_match('/^[A-Z]{3}$/', $sale['currency_code'])
                ? $sale['currency_code']
                : null,
            'occurred_at' => $occurredAt,
        ];

        if (! $record) {
            WorkspaceSponsorRevenue::query()->create($attributes);

            return 'created';
        }

        if ((string) $record->amount === number_format((float) $earning, 4, '.', '')
            && $record->currency_code === $attributes['currency_code']
            && $record->occurred_at?->equalTo($occurredAt)
            && $record->provider_type === $sponsor->type) {
            return 'unchanged';
        }

        $record->fill($attributes)->save();

        return 'updated';
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

        $this->membershipManager->recordActionSegment(
            $listId,
            $membership,
            'lead',
            $countryCode,
            'lead',
            'affiliate_conversion',
            (string) $membershipEvent->getKey(),
            $occurredAt,
            $metadata,
        );
    }

    private function membershipEventKey(WorkspaceSponsor $sponsor, string $conversionId): string
    {
        return 'affiliate-lead:'.$sponsor->getKey().':'.hash('sha256', $conversionId);
    }
}
