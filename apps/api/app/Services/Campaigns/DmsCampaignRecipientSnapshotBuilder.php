<?php

namespace App\Services\Campaigns;

use App\Models\AudienceMixSnapshot;
use App\Models\AudienceMixSnapshotMember;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\ContactListMembership;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DmsCampaignRecipientSnapshotBuilder
{
    public function __construct(private readonly DmsOfferSuppressionFile $suppressionFiles) {}

    /**
     * Reproduce DMS campaign creation's recipient suppression and point-in-time manifest.
     * Persist keyed identities in the immutable manifest and addresses in an
     * append-only encrypted table; normalized plaintext is limited to staging.
     *
     * @param  array<string, mixed>  $sendSetup
     */
    public function build(
        Workspace $workspace,
        Campaign $campaign,
        string $revisionId,
        array $sendSetup,
        ?callable $onProgress = null,
    ): CampaignRecipientSnapshot {
        $offer = WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($sendSetup['offer_id'])
            ->firstOrFail();

        if (! $this->suppressionFiles->hasFreshStoredReference($offer)) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'The offer suppression file is missing, changed, or older than seven days. Upload a current DMS-format suppression file.']);
        }

        $selected = $sendSetup['audience_segments'] ?? [];
        if (! is_array($selected) || $selected === []) {
            throw ValidationException::withMessages(['send_setup.audience_segments' => 'Select at least one unlocked list segment with eligible addresses.']);
        }

        $tables = $this->createStagingTables();
        $suppressionLookup = null;

        try {
            $suppressionBytes = (int) (Storage::disk('local')->size((string) $offer->suppression_path) ?: 0);
            $this->reportProgress($onProgress, 'suppression_file', 0, $suppressionBytes, 0);
            $suppressionLookup = $this->suppressionFiles->createLookupTable(
                $offer,
                function (int $readBytes, int $totalBytes) use ($onProgress): void {
                    $ratio = $totalBytes > 0 ? min(1, $readBytes / $totalBytes) : 1;
                    $percent = (int) floor($ratio * 100);
                    $this->reportProgress($onProgress, 'suppression_file', $readBytes, $totalBytes, $percent);
                },
            );
            $mixIds = collect($selected)
                ->filter(fn (string $segment): bool => str_ends_with($segment, '_mix'))
                ->map(fn (string $segment): string => substr($segment, 0, -4))
                ->unique()
                ->values();
            $regularSegments = array_values(array_filter(
                $selected,
                static fn (string $segment): bool => ! str_ends_with($segment, '_mix'),
            ));
            $this->reportProgress($onProgress, 'counting_audience', 0, 0, 0);
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

            $totalCandidates = 0;
            foreach ($regularSegments as $segment) {
                [$listId, $emailType] = explode('_', $segment, 2);
                $totalCandidates += ContactListMembership::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('audience_list_id', $listId)
                    ->where('email_type', $emailType)
                    ->where('status', 'active')
                    ->whereHas('contact', fn ($query) => $query->where('status', 'active'))
                    ->count();
            }
            $totalCandidates += (int) $mixSnapshots->sum('recipient_count');
            $processedCandidates = 0;
            $this->reportProgress($onProgress, 'audience', 0, $totalCandidates, 0);

            $offerSuppressedCount = 0;
            $workspaceSuppressedCount = 0;
            $duplicateCount = 0;
            $nextPosition = 0;

            foreach ($regularSegments as $segment) {
                [$listId, $emailType] = explode('_', $segment, 2);
                $offerSuppressedCount += ContactListMembership::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('audience_list_id', $listId)
                    ->where('email_type', $emailType)
                    ->where('status', 'active')
                    ->whereHas('contact', fn ($query) => $query->where('status', 'active'))
                    ->whereExists(function ($query) use ($workspace, $offer, $listId): void {
                        $query->selectRaw('1')
                            ->from('workspace_offer_list_suppressions')
                            ->whereColumn('workspace_offer_list_suppressions.contact_id', 'contact_list_memberships.contact_id')
                            ->where('workspace_offer_list_suppressions.workspace_id', $workspace->getKey())
                            ->where('workspace_offer_list_suppressions.offer_id', $offer->getKey())
                            ->where('workspace_offer_list_suppressions.audience_list_id', $listId);
                    })
                    ->count();

                $cursorJoinedAt = null;
                $cursorMembershipId = null;
                while (true) {
                    $membershipsQuery = ContactListMembership::query()
                        ->with('contact:id,workspace_id,email,email_lookup_hash,status')
                        ->where('workspace_id', $workspace->getKey())
                        ->where('audience_list_id', $listId)
                        ->where('email_type', $emailType)
                        ->where('status', 'active')
                        ->whereHas('contact', fn ($query) => $query->where('status', 'active'));

                    if ($cursorJoinedAt !== null && $cursorMembershipId !== null) {
                        $membershipsQuery->where(function ($query) use ($cursorJoinedAt, $cursorMembershipId): void {
                            $query->where('joined_at', '>', $cursorJoinedAt)
                                ->orWhere(function ($query) use ($cursorJoinedAt, $cursorMembershipId): void {
                                    $query->where('joined_at', '=', $cursorJoinedAt)
                                        ->where('id', '>', $cursorMembershipId);
                                });
                        });
                    }

                    $memberships = $membershipsQuery
                        ->orderBy('joined_at')
                        ->orderBy('id')
                        ->limit(500)
                        ->get();
                    if ($memberships->isEmpty()) {
                        break;
                    }

                    $candidates = [];
                    foreach ($memberships as $membership) {
                        $contact = $membership->contact;
                        if (! $contact || $contact->status !== 'active') {
                            continue;
                        }

                        $email = mb_strtolower(trim((string) $contact->email));
                        $candidates[] = [
                            'contact_id' => (string) $contact->getKey(),
                            'email_lookup_hash' => (string) $contact->email_lookup_hash,
                            'source_membership_id' => (string) $membership->getKey(),
                            'source_list_id' => (string) $listId,
                            'source_email_type' => $emailType,
                            'email' => $email,
                            'suppression_key' => $this->suppressionFiles->lookupKeyForEmail($email, (string) $offer->suppression_format),
                            'count_list_suppression' => false,
                        ];
                    }

                    if ($candidates !== []) {
                        $this->processCandidates(
                            $candidates,
                            $suppressionLookup,
                            $tables,
                            $workspace,
                            $offer,
                            $nextPosition,
                            $offerSuppressedCount,
                            $workspaceSuppressedCount,
                            $duplicateCount,
                        );
                    }

                    $processedCandidates += $memberships->count();
                    $audiencePercent = $totalCandidates > 0
                        ? (int) floor(min(1, $processedCandidates / $totalCandidates) * 100)
                        : 100;
                    $this->reportProgress($onProgress, 'audience', $processedCandidates, $totalCandidates, $audiencePercent);

                    $lastMembership = $memberships->last();
                    $cursorJoinedAt = $lastMembership->getRawOriginal('joined_at');
                    $cursorMembershipId = (string) $lastMembership->getKey();
                }
            }

            if ($mixIds->isNotEmpty()) {
                $cursorSnapshotId = null;
                $cursorPosition = null;
                $cursorMixMemberId = null;
                while (true) {
                    $mixMembersQuery = AudienceMixSnapshotMember::query()
                        ->with('contact:id,workspace_id,email,email_lookup_hash,status')
                        ->where('workspace_id', $workspace->getKey())
                        ->whereIn('snapshot_id', $mixIds);

                    if ($cursorSnapshotId !== null && $cursorPosition !== null && $cursorMixMemberId !== null) {
                        $mixMembersQuery->where(function ($query) use ($cursorSnapshotId, $cursorPosition, $cursorMixMemberId): void {
                            $query->where('snapshot_id', '>', $cursorSnapshotId)
                                ->orWhere(function ($query) use ($cursorSnapshotId, $cursorPosition, $cursorMixMemberId): void {
                                    $query->where('snapshot_id', '=', $cursorSnapshotId)
                                        ->where(function ($query) use ($cursorPosition, $cursorMixMemberId): void {
                                            $query->where('position', '>', $cursorPosition)
                                                ->orWhere(function ($query) use ($cursorPosition, $cursorMixMemberId): void {
                                                    $query->where('position', '=', $cursorPosition)
                                                        ->where('id', '>', $cursorMixMemberId);
                                                });
                                        });
                                });
                        });
                    }

                    $mixMembers = $mixMembersQuery
                        ->orderBy('snapshot_id')
                        ->orderBy('position')
                        ->orderBy('id')
                        ->limit(500)
                        ->get();
                    if ($mixMembers->isEmpty()) {
                        break;
                    }

                    $candidates = [];
                    foreach ($mixMembers as $mixMember) {
                        $contact = $mixMember->contact;
                        if (! $contact || $contact->status !== 'active') {
                            continue;
                        }

                        $email = mb_strtolower(trim((string) $contact->email));
                        $candidates[] = [
                            'contact_id' => (string) $contact->getKey(),
                            'email_lookup_hash' => (string) $contact->email_lookup_hash,
                            'source_membership_id' => $mixMember->source_membership_id === null
                                ? null
                                : (string) $mixMember->source_membership_id,
                            'source_list_id' => (string) $mixMember->source_list_id,
                            'source_email_type' => (string) $mixMember->email_type,
                            'email' => $email,
                            'suppression_key' => $this->suppressionFiles->lookupKeyForEmail($email, (string) $offer->suppression_format),
                            'count_list_suppression' => true,
                        ];
                    }

                    if ($candidates !== []) {
                        $this->processCandidates(
                            $candidates,
                            $suppressionLookup,
                            $tables,
                            $workspace,
                            $offer,
                            $nextPosition,
                            $offerSuppressedCount,
                            $workspaceSuppressedCount,
                            $duplicateCount,
                        );
                    }

                    $processedCandidates += $mixMembers->count();
                    $audiencePercent = $totalCandidates > 0
                        ? (int) floor(min(1, $processedCandidates / $totalCandidates) * 100)
                        : 100;
                    $this->reportProgress($onProgress, 'audience', $processedCandidates, $totalCandidates, $audiencePercent);

                    $lastMixMember = $mixMembers->last();
                    $cursorSnapshotId = (string) $lastMixMember->snapshot_id;
                    $cursorPosition = (int) $lastMixMember->position;
                    $cursorMixMemberId = (string) $lastMixMember->getKey();
                }
            }

            $recipientCount = (int) DB::table($tables['recipients'])->count();
            if ($recipientCount === 0) {
                throw ValidationException::withMessages(['send_setup.audience_segments' => 'No eligible recipients remain after DMS suppression and duplicate handling.']);
            }

            $manifest = hash_init('sha256');
            $cursor = null;
            while (true) {
                $page = $this->orderedRecipientPage($tables['recipients'], $sendSetup['order_by'], $cursor, 1000);
                if ($page->isEmpty()) {
                    break;
                }

                foreach ($page as $recipient) {
                    hash_update($manifest, (string) $recipient->contact_id."\n");
                }
                $cursor = $page->last();
            }
            $recipientsSha256 = hash_final($manifest);
            $this->reportProgress($onProgress, 'writing_snapshot', 0, 0, 0);

            return DB::transaction(function () use ($workspace, $campaign, $revisionId, $offer, $sendSetup, $tables, $recipientCount, $offerSuppressedCount, $workspaceSuppressedCount, $duplicateCount, $recipientsSha256): CampaignRecipientSnapshot {
                $snapshot = CampaignRecipientSnapshot::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'campaign_id' => $campaign->getKey(),
                    'revision_id' => $revisionId,
                    'offer_id' => $offer->getKey(),
                    'suppression_sha256' => $offer->suppression_sha256,
                    'selection' => [
                        'audience_segments' => $sendSetup['audience_segments'],
                        'order_by' => $sendSetup['order_by'],
                        'targeting_basis' => 'active_memberships',
                    ],
                    'recipient_count' => $recipientCount,
                    'offer_suppressed_count' => $offerSuppressedCount,
                    'workspace_suppressed_count' => $workspaceSuppressedCount,
                    'duplicate_count' => $duplicateCount,
                    'start_from' => min((int) $sendSetup['start_from'], $recipientCount),
                    'recipients_sha256' => $recipientsSha256,
                    'created_at' => now(),
                ]);

                $cursor = null;
                $position = 0;
                while (true) {
                    $chunk = $this->orderedRecipientPage($tables['recipients'], $sendSetup['order_by'], $cursor, 1000);
                    if ($chunk->isEmpty()) {
                        break;
                    }

                    $cursor = $chunk->last();
                    $createdAt = now();
                    $itemRows = [];
                    $addressRows = [];
                    $hashes = $chunk->pluck('email_lookup_hash')->map(fn ($hash): string => (string) $hash)->all();
                    $itemIdsByHash = [];

                    foreach ($chunk as $recipient) {
                        $position++;
                        $itemId = (string) Str::uuid();
                        $itemIdsByHash[(string) $recipient->email_lookup_hash] = $itemId;
                        $itemRows[] = [
                            'id' => $itemId,
                            'workspace_id' => $workspace->getKey(),
                            'snapshot_id' => $snapshot->getKey(),
                            'contact_id' => $recipient->contact_id,
                            'position' => $position,
                            'email_lookup_hash' => $recipient->email_lookup_hash,
                            'source_list_id' => $recipient->source_list_id,
                            'source_email_type' => $recipient->source_email_type,
                            'created_at' => $createdAt,
                        ];
                        $addressRows[] = [
                            'snapshot_item_id' => $itemId,
                            'workspace_id' => $workspace->getKey(),
                            'email_lookup_hash' => $recipient->email_lookup_hash,
                            'email' => Crypt::encryptString((string) $recipient->email),
                            'created_at' => $createdAt,
                        ];
                    }
                    DB::table('campaign_recipient_snapshot_items')->insert($itemRows);
                    DB::table('campaign_recipient_snapshot_item_addresses')->insert($addressRows);

                    $sourceCursor = null;
                    while (true) {
                        $sourceQuery = DB::table($tables['sources'])
                            ->whereIn('email_lookup_hash', $hashes);
                        if ($sourceCursor !== null) {
                            $sourceQuery->where(function ($query) use ($sourceCursor): void {
                                $query->where('email_lookup_hash', '>', $sourceCursor->email_lookup_hash)
                                    ->orWhere(function ($query) use ($sourceCursor): void {
                                        $query->where('email_lookup_hash', '=', $sourceCursor->email_lookup_hash)
                                            ->where('source_list_id', '>', $sourceCursor->source_list_id);
                                    })
                                    ->orWhere(function ($query) use ($sourceCursor): void {
                                        $query->where('email_lookup_hash', '=', $sourceCursor->email_lookup_hash)
                                            ->where('source_list_id', '=', $sourceCursor->source_list_id)
                                            ->where('source_email_type', '>', $sourceCursor->source_email_type);
                                    });
                            });
                        }

                        $sourceChunk = $sourceQuery
                            ->orderBy('email_lookup_hash')
                            ->orderBy('source_list_id')
                            ->orderBy('source_email_type')
                            ->limit(1000)
                            ->get();
                        if ($sourceChunk->isEmpty()) {
                            break;
                        }

                        $sourceRows = [];
                        foreach ($sourceChunk as $source) {
                            $sourceRows[] = [
                                'id' => (string) Str::uuid(),
                                'workspace_id' => $workspace->getKey(),
                                'snapshot_item_id' => $itemIdsByHash[(string) $source->email_lookup_hash],
                                'source_membership_id' => $source->source_membership_id,
                                'source_list_id' => $source->source_list_id,
                                'source_email_type' => $source->source_email_type,
                                'source_key' => CampaignRecipientSourceIdentity::key(
                                    $source->source_membership_id === null ? null : (string) $source->source_membership_id,
                                    (string) $source->source_list_id,
                                    (string) $source->source_email_type,
                                ),
                                'created_at' => $createdAt,
                            ];
                        }
                        DB::table('campaign_recipient_snapshot_item_sources')->insertOrIgnore($sourceRows);
                        $sourceCursor = $sourceChunk->last();
                    }
                }

                return $snapshot;
            });
        } finally {
            if (is_string($suppressionLookup)) {
                $this->suppressionFiles->dropLookupTable($suppressionLookup);
            }
            $this->dropStagingTables($tables);
        }
    }

    private function reportProgress(?callable $callback, string $stage, int $processed, int $total, int $percent): void
    {
        if ($callback === null) {
            return;
        }

        $callback([
            'stage' => $stage,
            'processed' => max(0, $processed),
            'total' => max(0, $total),
            'percent' => max(0, min(100, $percent)),
        ]);
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

                    $addressRows = DB::table('campaign_recipient_snapshot_item_addresses')
                        ->whereIn('snapshot_item_id', array_keys($itemIds))
                        ->get(['snapshot_item_id', 'workspace_id', 'email_lookup_hash', 'email', 'created_at'])
                        ->map(function ($address) use ($itemIds): array {
                            return [
                                'snapshot_item_id' => $itemIds[(string) $address->snapshot_item_id],
                                'workspace_id' => $address->workspace_id,
                                'email_lookup_hash' => $address->email_lookup_hash,
                                'email' => $address->email,
                                'created_at' => $address->created_at,
                            ];
                        })
                        ->all();
                    if ($addressRows !== []) {
                        DB::table('campaign_recipient_snapshot_item_addresses')->insert($addressRows);
                    }

                    $sourceCursor = null;
                    while (true) {
                        $sourceQuery = DB::table('campaign_recipient_snapshot_item_sources')
                            ->whereIn('snapshot_item_id', array_keys($itemIds));
                        if ($sourceCursor !== null) {
                            $sourceQuery->where('id', '>', $sourceCursor->id);
                        }
                        $sourceChunk = $sourceQuery->orderBy('id')->limit(1000)->get();
                        if ($sourceChunk->isEmpty()) {
                            break;
                        }

                        $sourceRows = $sourceChunk->map(fn ($source): array => [
                            'id' => (string) Str::uuid(),
                            'workspace_id' => $source->workspace_id,
                            'snapshot_item_id' => $itemIds[(string) $source->snapshot_item_id],
                            'source_membership_id' => $source->source_membership_id,
                            'source_list_id' => $source->source_list_id,
                            'source_email_type' => $source->source_email_type,
                            'source_key' => $source->source_key,
                            'created_at' => $createdAt,
                        ])->all();
                        DB::table('campaign_recipient_snapshot_item_sources')->insert($sourceRows);
                        $sourceCursor = $sourceChunk->last();
                    }
                }, 'id');

            return $copy;
        });
    }

    /**
     * Create session-scoped work tables so a large campaign audience is never
     * accumulated in PHP memory.
     *
     * @return array{candidates: string, recipients: string, sources: string}
     */
    private function createStagingTables(): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $suffix = Str::lower(Str::random(18));
        $tables = [
            'candidates' => 'tmp_campaign_candidates_'.$suffix,
            'recipients' => 'tmp_campaign_recipients_'.$suffix,
            'sources' => 'tmp_campaign_sources_'.$suffix,
        ];
        $wrappedCandidates = $grammar->wrapTable($tables['candidates']);
        $wrappedRecipients = $grammar->wrapTable($tables['recipients']);
        $wrappedSources = $grammar->wrapTable($tables['sources']);

        try {
            $connection->statement("CREATE TEMPORARY TABLE {$wrappedCandidates} (
                candidate_position BIGINT PRIMARY KEY,
                contact_id UUID NOT NULL,
                email_lookup_hash CHAR(64) NOT NULL,
                source_membership_id UUID NULL,
                source_list_id UUID NOT NULL,
                source_email_type VARCHAR(24) NOT NULL,
                email VARCHAR(320) NOT NULL,
                suppression_key VARCHAR(320) NOT NULL,
                count_list_suppression SMALLINT NOT NULL
            )");
            $connection->statement("CREATE TEMPORARY TABLE {$wrappedRecipients} (
                email_lookup_hash CHAR(64) PRIMARY KEY,
                contact_id UUID NOT NULL,
                source_list_id UUID NOT NULL,
                source_email_type VARCHAR(24) NOT NULL,
                email VARCHAR(320) NOT NULL,
                first_position BIGINT NOT NULL UNIQUE,
                random_order DOUBLE PRECISION NOT NULL
            )");
            $connection->statement("CREATE TEMPORARY TABLE {$wrappedSources} (
                email_lookup_hash CHAR(64) NOT NULL,
                source_membership_id UUID NULL,
                source_list_id UUID NOT NULL,
                source_email_type VARCHAR(24) NOT NULL,
                UNIQUE (email_lookup_hash, source_list_id, source_email_type)
            )");

            $emailCollation = $connection->getDriverName() === 'pgsql'
                ? 'email COLLATE "C"'
                : 'email COLLATE BINARY';
            foreach ([
                [$tables['recipients'].'_email_idx', $tables['recipients'], "{$emailCollation}, first_position"],
                [$tables['recipients'].'_random_idx', $tables['recipients'], 'random_order, first_position'],
            ] as [$indexName, $tableName, $columns]) {
                $connection->statement(
                    'CREATE INDEX '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($tableName).' ('.$columns.')',
                );
            }
        } catch (\Throwable $exception) {
            $this->dropStagingTables($tables);
            throw $exception;
        }

        return $tables;
    }

    /**
     * @param  array{candidates: string, recipients: string, sources: string}  $tables
     * @param  list<array{contact_id: string, email_lookup_hash: string, source_membership_id: ?string, source_list_id: string, source_email_type: string, email: string, suppression_key: string, count_list_suppression: bool}>  $candidates
     */
    private function processCandidates(
        array $candidates,
        string $suppressionLookup,
        array $tables,
        Workspace $workspace,
        WorkspaceOffer $offer,
        int &$nextPosition,
        int &$offerSuppressedCount,
        int &$workspaceSuppressedCount,
        int &$duplicateCount,
    ): void {
        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            throw ValidationException::withMessages([
                'send_setup.audience_segments' => 'CONTACT_LOOKUP_KEY must be configured before campaign recipients can be snapshotted.',
            ]);
        }

        $candidateTable = $tables['candidates'];
        DB::table($candidateTable)->delete();
        $candidateRows = [];
        foreach ($candidates as $position => $candidate) {
            $email = mb_strtolower(trim($candidate['email']));
            $expectedHash = hash_hmac('sha256', $email, $lookupKey);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || ! hash_equals($expectedHash, $candidate['email_lookup_hash'])) {
                throw ValidationException::withMessages([
                    'send_setup.audience_segments' => 'A selected contact address does not match its keyed identity. Repair or re-import the contact before creating a campaign snapshot.',
                ]);
            }

            $candidateRows[] = [
                'candidate_position' => $position + 1,
                'contact_id' => $candidate['contact_id'],
                'email_lookup_hash' => $candidate['email_lookup_hash'],
                'source_membership_id' => $candidate['source_membership_id'],
                'source_list_id' => $candidate['source_list_id'],
                'source_email_type' => $candidate['source_email_type'],
                'email' => $email,
                'suppression_key' => $candidate['suppression_key'],
                'count_list_suppression' => $candidate['count_list_suppression'] ? 1 : 0,
            ];
        }
        DB::table($candidateTable)->insert($candidateRows);

        $effectiveAt = now();
        $resolvedCandidates = DB::table($candidateTable.' as candidates')
            ->select('candidates.*')
            ->selectRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM workspace_offer_list_suppressions AS offer_list_suppression
                    WHERE offer_list_suppression.workspace_id = ?
                        AND offer_list_suppression.offer_id = ?
                        AND offer_list_suppression.audience_list_id = candidates.source_list_id
                        AND offer_list_suppression.contact_id = candidates.contact_id) THEN 1 ELSE 0 END AS list_suppressed',
                [$workspace->getKey(), $offer->getKey()],
            )
            ->selectRaw(
                "CASE WHEN EXISTS (SELECT 1 FROM suppression_events AS workspace_suppression
                    WHERE workspace_suppression.workspace_id = ?
                        AND workspace_suppression.contact_id = candidates.contact_id
                        AND workspace_suppression.event_type = 'added'
                        AND workspace_suppression.scope_type = 'workspace'
                        AND workspace_suppression.effective_at <= ?) THEN 1 ELSE 0 END AS workspace_suppressed",
                [$workspace->getKey(), $effectiveAt],
            )
            ->selectRaw(
                "CASE WHEN EXISTS (SELECT 1 FROM suppression_events AS offer_suppression
                    WHERE offer_suppression.workspace_id = ?
                        AND offer_suppression.contact_id = candidates.contact_id
                        AND offer_suppression.event_type = 'added'
                        AND offer_suppression.scope_type = 'offer'
                        AND offer_suppression.scope_key = ?
                        AND offer_suppression.effective_at <= ?) THEN 1 ELSE 0 END AS offer_suppressed",
                [$workspace->getKey(), (string) $offer->getKey(), $effectiveAt],
            )
            ->selectRaw(
                "CASE WHEN EXISTS (SELECT 1 FROM {$suppressionLookup} AS file_suppression
                    WHERE file_suppression.entry_value = candidates.suppression_key) THEN 1 ELSE 0 END AS file_suppressed",
            )
            ->orderBy('candidates.candidate_position')
            ->get();

        $eligibleCandidates = [];
        foreach ($resolvedCandidates as $candidate) {
            if ((int) $candidate->list_suppressed === 1) {
                if ((int) $candidate->count_list_suppression === 1) {
                    $offerSuppressedCount++;
                }

                continue;
            }
            if ((int) $candidate->workspace_suppressed === 1) {
                $workspaceSuppressedCount++;

                continue;
            }
            if ((int) $candidate->offer_suppressed === 1 || (int) $candidate->file_suppressed === 1) {
                $offerSuppressedCount++;

                continue;
            }

            $eligibleCandidates[] = $candidate;
        }

        if ($eligibleCandidates === []) {
            return;
        }

        $identityHashes = array_values(array_unique(array_column($eligibleCandidates, 'email_lookup_hash')));
        $existingHashes = DB::table($tables['recipients'])
            ->whereIn('email_lookup_hash', $identityHashes)
            ->pluck('email_lookup_hash')
            ->flip();
        $firstByHash = [];
        $sourceByIdentity = [];
        foreach ($eligibleCandidates as $candidate) {
            $identity = (string) $candidate->email_lookup_hash;
            if (! isset($firstByHash[$identity])) {
                $firstByHash[$identity] = true;
                if (! isset($existingHashes[$identity])) {
                    $nextPosition++;
                    $recipientRows[] = [
                        'email_lookup_hash' => $identity,
                        'contact_id' => (string) $candidate->contact_id,
                        'source_list_id' => (string) $candidate->source_list_id,
                        'source_email_type' => (string) $candidate->source_email_type,
                        'email' => (string) $candidate->email,
                        'first_position' => $nextPosition,
                        'random_order' => mt_rand() / mt_getrandmax(),
                    ];
                } else {
                    $duplicateCount++;
                }
            } else {
                $duplicateCount++;
            }

            $sourceIdentity = $identity.'|'.$candidate->source_list_id.'|'.$candidate->source_email_type;
            $sourceMembershipId = $candidate->source_membership_id === null
                ? null
                : (string) $candidate->source_membership_id;
            if (! isset($sourceByIdentity[$sourceIdentity])
                || ($sourceByIdentity[$sourceIdentity]['source_membership_id'] === null && $sourceMembershipId !== null)) {
                $sourceByIdentity[$sourceIdentity] = [
                    'email_lookup_hash' => $identity,
                    'source_membership_id' => $sourceMembershipId,
                    'source_list_id' => (string) $candidate->source_list_id,
                    'source_email_type' => (string) $candidate->source_email_type,
                ];
            }
        }

        if (isset($recipientRows) && $recipientRows !== []) {
            DB::table($tables['recipients'])->insertOrIgnore($recipientRows);
            unset($recipientRows);
        }

        $sourceRows = array_values($sourceByIdentity);
        $wrappedSourceTable = DB::connection()->getQueryGrammar()->wrapTable($tables['sources']);
        DB::table($tables['sources'])->upsert(
            $sourceRows,
            ['email_lookup_hash', 'source_list_id', 'source_email_type'],
            [
                'source_membership_id' => DB::raw(
                    "COALESCE({$wrappedSourceTable}.source_membership_id, excluded.source_membership_id)",
                ),
            ],
        );
    }

    /**
     * Read the next ordered page from the staged recipient set using a keyset
     * cursor. The database performs sorting without returning the full audience.
     *
     * @return Collection<int, \stdClass>
     */
    private function orderedRecipientPage(string $table, string $orderBy, ?object $cursor, int $limit): Collection
    {
        $query = DB::table($table);
        $driver = DB::connection()->getDriverName();

        if (in_array($orderBy, ['asc', 'desc'], true)) {
            $sortExpression = $driver === 'pgsql' ? 'email COLLATE "C"' : 'email COLLATE BINARY';
            $operator = $orderBy === 'desc' ? '<' : '>';
            if ($cursor !== null) {
                $query->where(function ($query) use ($sortExpression, $operator, $cursor): void {
                    $query->whereRaw($sortExpression.' '.$operator.' ?', [$cursor->email])
                        ->orWhere(function ($query) use ($sortExpression, $cursor): void {
                            $query->whereRaw($sortExpression.' = ?', [$cursor->email])
                                ->where('first_position', '>', $cursor->first_position);
                        });
                });
            }
            $query->orderByRaw($sortExpression.' '.strtoupper($orderBy))
                ->orderBy('first_position');
        } elseif ($orderBy === 'random') {
            if ($cursor !== null) {
                $query->where(function ($query) use ($cursor): void {
                    $query->where('random_order', '>', $cursor->random_order)
                        ->orWhere(function ($query) use ($cursor): void {
                            $query->where('random_order', '=', $cursor->random_order)
                                ->where('first_position', '>', $cursor->first_position);
                        });
                });
            }
            $query->orderBy('random_order')->orderBy('first_position');
        } else {
            if ($cursor !== null) {
                $query->where('first_position', '>', $cursor->first_position);
            }
            $query->orderBy('first_position');
        }

        return $query->limit($limit)->get();
    }

    /**
     * @param  array{candidates: string, recipients: string, sources: string}  $tables
     */
    private function dropStagingTables(array $tables): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        foreach (array_reverse(array_values($tables)) as $table) {
            if (preg_match('/^tmp_campaign_(?:candidates|recipients|sources)_[a-z0-9]{18}$/', $table) !== 1) {
                continue;
            }

            try {
                $connection->statement('DROP TABLE IF EXISTS '.$grammar->wrapTable($table));
            } catch (\Throwable) {
                // Session-scoped temporary tables are discarded with the connection.
            }
        }
    }
}
