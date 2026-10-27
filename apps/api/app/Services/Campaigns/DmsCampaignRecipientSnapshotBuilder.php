<?php

namespace App\Services\Campaigns;

use App\Models\AudienceMixSnapshot;
use App\Models\AudienceMixSnapshotMember;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\ContactListMembership;
use App\Models\SuppressionEvent;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferListSuppression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DmsCampaignRecipientSnapshotBuilder
{
    public function __construct(private readonly DmsOfferSuppressionFile $suppressionFiles) {}

    /**
     * Reproduce DMS campaign creation's synchronous recipient suppression and point-in-time manifest.
     * Only contact IDs and workspace HMACs are persisted; email plaintext stays encrypted at rest.
     *
     * @param  array<string, mixed>  $sendSetup
     */
    public function build(Workspace $workspace, Campaign $campaign, string $revisionId, array $sendSetup): CampaignRecipientSnapshot
    {
        $offer = WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($sendSetup['offer_id'])
            ->firstOrFail();

        if (! $this->suppressionFiles->isEligible($offer)) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'The offer suppression file is missing, changed, or older than seven days. Upload a current DMS-format suppression file.']);
        }

        $suppressionContent = Storage::disk('local')->get($offer->suppression_path);
        $suppressionSet = array_fill_keys(explode("\n", trim($suppressionContent)), true);
        $suppressionHash = hash('sha256', $suppressionContent);
        $selected = $sendSetup['audience_segments'] ?? [];
        if (! is_array($selected) || $selected === []) {
            throw ValidationException::withMessages(['send_setup.audience_segments' => 'Select at least one unlocked list segment with eligible addresses.']);
        }

        $mixIds = collect($selected)
            ->filter(fn (string $segment): bool => str_ends_with($segment, '_mix'))
            ->map(fn (string $segment): string => substr($segment, 0, -4))
            ->unique()
            ->values();
        $regularSegments = array_values(array_filter(
            $selected,
            static fn (string $segment): bool => ! str_ends_with($segment, '_mix'),
        ));
        $mixSnapshots = $mixIds->isEmpty()
            ? collect()
            : AudienceMixSnapshot::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $campaign->created_by)
                ->where('isp_id', $sendSetup['isp_id'] ?? null)
                ->where('status', 'active')
                ->where('created_at', '>=', now()->subDay()->endOfDay())
                ->whereIn('id', $mixIds)
                ->get()
                ->keyBy(fn (AudienceMixSnapshot $mix): string => (string) $mix->getKey());
        if ($mixSnapshots->count() !== $mixIds->count()) {
            throw ValidationException::withMessages(['send_setup.audience_segments' => 'One or more selected mixes expired or are unavailable to this user. Reload the send choices.']);
        }

        $workspaceSuppressed = SuppressionEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('event_type', 'added')
            ->where('scope_type', 'workspace')
            ->where('effective_at', '<=', now())
            ->pluck('contact_id')
            ->flip();
        $offerExcluded = SuppressionEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('event_type', 'added')
            ->where('scope_type', 'offer')
            ->where('scope_key', (string) $offer->getKey())
            ->where('effective_at', '<=', now())
            ->pluck('contact_id')
            ->flip();

        $mixSourceListIds = $mixIds->isEmpty()
            ? collect()
            : AudienceMixSnapshotMember::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('snapshot_id', $mixIds)
                ->distinct()
                ->pluck('source_list_id');
        $regularListIds = collect($regularSegments)->map(fn (string $segment): string => explode('_', $segment, 2)[0]);
        $selectedListIds = collect($sendSetup['audience_list_ids'] ?? [])
            ->merge($regularListIds)
            ->merge($mixSourceListIds)
            ->unique()
            ->values();
        $offerListExcluded = WorkspaceOfferListSuppression::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('offer_id', $offer->getKey())
            ->whereIn('audience_list_id', $selectedListIds)
            ->get(['audience_list_id', 'contact_id'])
            ->groupBy('audience_list_id')
            ->map(fn ($rows) => $rows->pluck('contact_id')->mapWithKeys(fn ($id): array => [(string) $id => true])->all());

        $seen = [];
        $recipients = [];
        $offerSuppressedCount = 0;
        $workspaceSuppressedCount = 0;
        $duplicateCount = 0;

        foreach ($regularSegments as $segment) {
            [$listId, $emailType] = explode('_', $segment, 2);
            $listOfferExcluded = ContactListMembership::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('audience_list_id', $listId)
                ->where('email_type', $emailType)
                ->where('status', 'active')
                ->whereHas('contact', fn ($query) => $query->where('status', 'active'))
                ->whereIn('contact_id', array_keys($offerListExcluded->get($listId, [])))
                ->count();
            $offerSuppressedCount += $listOfferExcluded;

            $memberships = ContactListMembership::query()
                ->with('contact:id,workspace_id,email,email_lookup_hash,status')
                ->where('workspace_id', $workspace->getKey())
                ->where('audience_list_id', $listId)
                ->where('email_type', $emailType)
                ->where('status', 'active')
                ->orderBy('joined_at')
                ->orderBy('id')
                ->lazy(1000);

            foreach ($memberships as $membership) {
                $contact = $membership->contact;
                if (! $contact || $contact->status !== 'active') {
                    continue;
                }
                if (isset($offerListExcluded->get($listId, [])[(string) $contact->getKey()])) {
                    continue;
                }
                if (isset($workspaceSuppressed[$contact->getKey()])) {
                    $workspaceSuppressedCount++;

                    continue;
                }
                if (isset($offerExcluded[$contact->getKey()])) {
                    $offerSuppressedCount++;

                    continue;
                }

                $email = mb_strtolower(trim((string) $contact->email));
                $md5 = md5($email);
                if (isset($suppressionSet[$offer->suppression_format === 'md5' ? $md5 : $email])) {
                    $offerSuppressedCount++;

                    continue;
                }

                $identity = (string) $contact->email_lookup_hash;
                if (isset($seen[$identity])) {
                    $duplicateCount++;
                    $this->addSource($recipients[$seen[$identity]], [
                        'source_membership_id' => (string) $membership->getKey(),
                        'source_list_id' => (string) $listId,
                        'source_email_type' => $emailType,
                    ]);

                    continue;
                }
                $seen[$identity] = count($recipients);
                $recipients[] = [
                    'contact_id' => $contact->getKey(),
                    'email_lookup_hash' => $identity,
                    'source_list_id' => $listId,
                    'source_email_type' => $emailType,
                    'sources' => [CampaignRecipientSourceIdentity::key((string) $membership->getKey(), (string) $listId, $emailType) => [
                        'source_membership_id' => (string) $membership->getKey(),
                        'source_list_id' => (string) $listId,
                        'source_email_type' => $emailType,
                    ]],
                    'email' => $email,
                ];
            }
        }

        if ($mixIds->isNotEmpty()) {
            $mixMembers = AudienceMixSnapshotMember::query()
                ->with('contact:id,workspace_id,email,email_lookup_hash,status')
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('snapshot_id', $mixIds)
                ->orderBy('snapshot_id')
                ->orderBy('position')
                ->lazy(1000);

            foreach ($mixMembers as $mixMember) {
                $contact = $mixMember->contact;
                if (! $contact || $contact->status !== 'active') {
                    continue;
                }
                $listId = (string) $mixMember->source_list_id;
                if (isset($offerListExcluded->get($listId, [])[(string) $contact->getKey()])) {
                    $offerSuppressedCount++;

                    continue;
                }
                if (isset($workspaceSuppressed[$contact->getKey()])) {
                    $workspaceSuppressedCount++;

                    continue;
                }
                if (isset($offerExcluded[$contact->getKey()])) {
                    $offerSuppressedCount++;

                    continue;
                }

                $email = mb_strtolower(trim((string) $contact->email));
                $md5 = md5($email);
                if (isset($suppressionSet[$offer->suppression_format === 'md5' ? $md5 : $email])) {
                    $offerSuppressedCount++;

                    continue;
                }

                $identity = (string) $contact->email_lookup_hash;
                if (isset($seen[$identity])) {
                    $duplicateCount++;
                    $this->addSource($recipients[$seen[$identity]], [
                        'source_membership_id' => $mixMember->source_membership_id === null
                            ? null
                            : (string) $mixMember->source_membership_id,
                        'source_list_id' => $listId,
                        'source_email_type' => (string) $mixMember->email_type,
                    ]);

                    continue;
                }
                $seen[$identity] = count($recipients);
                $sourceMembershipId = $mixMember->source_membership_id === null
                    ? null
                    : (string) $mixMember->source_membership_id;
                $sourceEmailType = (string) $mixMember->email_type;
                $recipients[] = [
                    'contact_id' => $contact->getKey(),
                    'email_lookup_hash' => $identity,
                    'source_list_id' => $listId,
                    'source_email_type' => $sourceEmailType,
                    'sources' => [CampaignRecipientSourceIdentity::key($sourceMembershipId, $listId, $sourceEmailType) => [
                        'source_membership_id' => $sourceMembershipId,
                        'source_list_id' => $listId,
                        'source_email_type' => $sourceEmailType,
                    ]],
                    'email' => $email,
                ];
            }
        }

        if ($sendSetup['order_by'] === 'asc') {
            usort($recipients, fn (array $left, array $right): int => strcmp($left['email'], $right['email']));
        } elseif ($sendSetup['order_by'] === 'desc') {
            usort($recipients, fn (array $left, array $right): int => strcmp($right['email'], $left['email']));
        } elseif ($sendSetup['order_by'] === 'random') {
            shuffle($recipients);
        }

        if ($recipients === []) {
            throw ValidationException::withMessages(['send_setup.audience_segments' => 'No eligible recipients remain after DMS suppression and duplicate handling.']);
        }

        $manifest = hash_init('sha256');
        foreach ($recipients as $recipient) {
            hash_update($manifest, $recipient['contact_id']."\n");
        }
        $recipientsSha256 = hash_final($manifest);

        return DB::transaction(function () use ($workspace, $campaign, $revisionId, $offer, $sendSetup, $suppressionHash, $recipients, $offerSuppressedCount, $workspaceSuppressedCount, $duplicateCount, $recipientsSha256): CampaignRecipientSnapshot {
            $snapshot = CampaignRecipientSnapshot::query()->create([
                'workspace_id' => $workspace->getKey(),
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $revisionId,
                'offer_id' => $offer->getKey(),
                'suppression_sha256' => $suppressionHash,
                'selection' => [
                    'audience_segments' => $sendSetup['audience_segments'],
                    'order_by' => $sendSetup['order_by'],
                    'targeting_basis' => 'active_memberships',
                ],
                'recipient_count' => count($recipients),
                'offer_suppressed_count' => $offerSuppressedCount,
                'workspace_suppressed_count' => $workspaceSuppressedCount,
                'duplicate_count' => $duplicateCount,
                'start_from' => min((int) $sendSetup['start_from'], count($recipients)),
                'recipients_sha256' => $recipientsSha256,
                'created_at' => now(),
            ]);

            foreach (array_chunk($recipients, 1000) as $chunkIndex => $chunk) {
                $createdAt = now();
                $itemRows = [];
                $sourceRows = [];
                foreach ($chunk as $offset => $recipient) {
                    $itemId = (string) Str::uuid();
                    $itemRows[] = [
                        'id' => $itemId,
                        'workspace_id' => $workspace->getKey(),
                        'snapshot_id' => $snapshot->getKey(),
                        'contact_id' => $recipient['contact_id'],
                        'position' => $chunkIndex * 1000 + $offset + 1,
                        'email_lookup_hash' => $recipient['email_lookup_hash'],
                        'source_list_id' => $recipient['source_list_id'],
                        'source_email_type' => $recipient['source_email_type'],
                        'created_at' => $createdAt,
                    ];
                    foreach ($recipient['sources'] as $source) {
                        $sourceRows[] = [
                            'id' => (string) Str::uuid(),
                            'workspace_id' => $workspace->getKey(),
                            'snapshot_item_id' => $itemId,
                            'source_membership_id' => $source['source_membership_id'],
                            'source_list_id' => $source['source_list_id'],
                            'source_email_type' => $source['source_email_type'],
                            'source_key' => CampaignRecipientSourceIdentity::key(
                                $source['source_membership_id'],
                                $source['source_list_id'],
                                $source['source_email_type'],
                            ),
                            'created_at' => $createdAt,
                        ];
                    }
                }
                DB::table('campaign_recipient_snapshot_items')->insert($itemRows);
                foreach (array_chunk($sourceRows, 1000) as $sourceChunk) {
                    DB::table('campaign_recipient_snapshot_item_sources')->insertOrIgnore($sourceChunk);
                }
            }

            return $snapshot;
        });
    }

    /**
     * Carry the exact point-in-time recipient manifest forward when only the
     * immutable campaign content revision changes. Re-reading live lists here
     * would silently change the audience of an existing DMS campaign.
     */
    public function copyToRevision(CampaignRecipientSnapshot $source, string $revisionId): CampaignRecipientSnapshot
    {
        return DB::transaction(function () use ($source, $revisionId): CampaignRecipientSnapshot {
            $copy = CampaignRecipientSnapshot::query()->create([
                'workspace_id' => $source->workspace_id,
                'campaign_id' => $source->campaign_id,
                'revision_id' => $revisionId,
                'offer_id' => $source->offer_id,
                'suppression_sha256' => $source->suppression_sha256,
                'selection' => $source->selection,
                'recipient_count' => $source->recipient_count,
                'offer_suppressed_count' => $source->offer_suppressed_count,
                'workspace_suppressed_count' => $source->workspace_suppressed_count,
                'duplicate_count' => $source->duplicate_count,
                'start_from' => $source->start_from,
                'recipients_sha256' => $source->recipients_sha256,
                'created_at' => now(),
            ]);

            DB::table('campaign_recipient_snapshot_items')
                ->where('snapshot_id', $source->getKey())
                ->orderBy('id')
                ->chunkById(1000, function ($items) use ($copy): void {
                    $createdAt = now();
                    $itemIds = [];
                    $itemRows = [];
                    foreach ($items as $item) {
                        $newItemId = (string) Str::uuid();
                        $itemIds[(string) $item->id] = $newItemId;
                        $itemRows[] = [
                            'id' => $newItemId,
                            'workspace_id' => $item->workspace_id,
                            'snapshot_id' => $copy->getKey(),
                            'contact_id' => $item->contact_id,
                            'position' => $item->position,
                            'email_lookup_hash' => $item->email_lookup_hash,
                            'source_list_id' => $item->source_list_id,
                            'source_email_type' => $item->source_email_type,
                            'created_at' => $createdAt,
                        ];
                    }
                    DB::table('campaign_recipient_snapshot_items')->insert($itemRows);

                    $sourceRows = DB::table('campaign_recipient_snapshot_item_sources')
                        ->whereIn('snapshot_item_id', array_keys($itemIds))
                        ->get();
                    foreach (array_chunk($sourceRows->map(fn ($source): array => [
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $source->workspace_id,
                        'snapshot_item_id' => $itemIds[(string) $source->snapshot_item_id],
                        'source_membership_id' => $source->source_membership_id,
                        'source_list_id' => $source->source_list_id,
                        'source_email_type' => $source->source_email_type,
                        'source_key' => $source->source_key,
                        'created_at' => $createdAt,
                    ])->all(), 1000) as $sourceChunk) {
                        DB::table('campaign_recipient_snapshot_item_sources')->insert($sourceChunk);
                    }
                }, 'id');

            return $copy;
        });
    }

    /** @param array<string, mixed> $recipient
     * @param  array{source_membership_id: ?string, source_list_id: string, source_email_type: string}  $source
     */
    private function addSource(array &$recipient, array $source): void
    {
        $key = CampaignRecipientSourceIdentity::key(
            $source['source_membership_id'],
            $source['source_list_id'],
            $source['source_email_type'],
        );
        if (isset($recipient['sources'][$key])) {
            return;
        }

        foreach ($recipient['sources'] as $existingKey => $existing) {
            if ($existing['source_list_id'] !== $source['source_list_id']
                || $existing['source_email_type'] !== $source['source_email_type']) {
                continue;
            }

            if ($existing['source_membership_id'] === null && $source['source_membership_id'] !== null) {
                unset($recipient['sources'][$existingKey]);
                break;
            }

            if ($existing['source_membership_id'] !== null || $source['source_membership_id'] === null) {
                return;
            }
        }

        $recipient['sources'][$key] = $source;
    }
}
