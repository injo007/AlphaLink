<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:backfill-campaign-snapshot-addresses {--workspace= : Restrict the backfill to one workspace UUID} {--batch=500 : Snapshot items processed per batch}')]
#[Description('Restore legacy campaign snapshot addresses only when the current email still matches the immutable keyed identity.')]
class BackfillCampaignSnapshotAddresses extends Command
{
    public function handle(): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if (! is_int($batchSize) || $batchSize < 1 || $batchSize > 5000) {
            $this->error('The batch size must be between 1 and 5,000 snapshot items.');

            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace');
        if ($workspaceId !== null && (! is_string($workspaceId) || ! Str::isUuid($workspaceId))) {
            $this->error('The workspace option must be a valid UUID.');

            return self::FAILURE;
        }

        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            $this->error('CONTACT_LOOKUP_KEY must be available to verify legacy snapshot identities.');

            return self::FAILURE;
        }

        $cursor = null;
        $processed = 0;
        $backfilled = 0;
        $unresolved = 0;

        while (true) {
            $items = DB::table('campaign_recipient_snapshot_items as item')
                ->leftJoin('campaign_recipient_snapshot_item_addresses as address', function ($join): void {
                    $join->on('address.snapshot_item_id', '=', 'item.id')
                        ->on('address.workspace_id', '=', 'item.workspace_id');
                })
                ->whereNull('address.snapshot_item_id')
                ->when($workspaceId, fn ($query) => $query->where('item.workspace_id', $workspaceId))
                ->when($cursor, fn ($query) => $query->where('item.id', '>', $cursor))
                ->orderBy('item.id')
                ->limit($batchSize)
                ->get([
                    'item.id as snapshot_item_id',
                    'item.workspace_id',
                    'item.contact_id',
                    'item.email_lookup_hash',
                ]);

            if ($items->isEmpty()) {
                break;
            }

            $cursor = (string) $items->last()->snapshot_item_id;
            $processed += $items->count();
            $contacts = Contact::query()
                ->whereIn('id', $items->pluck('contact_id')->unique()->all())
                ->get(['id', 'workspace_id', 'email', 'email_lookup_hash'])
                ->keyBy(fn (Contact $contact): string => $contact->workspace_id.':'.$contact->getKey());
            $addressRows = [];

            foreach ($items as $item) {
                $contact = $contacts->get($item->workspace_id.':'.$item->contact_id);
                if (! $contact || ! is_string($contact->email_lookup_hash)) {
                    $unresolved++;

                    continue;
                }

                $email = mb_strtolower(trim((string) $contact->email));
                $emailHash = hash_hmac('sha256', $email, $lookupKey);
                if ($contact->email_lookup_hash !== $item->email_lookup_hash
                    || ! hash_equals((string) $item->email_lookup_hash, $emailHash)
                    || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $unresolved++;

                    continue;
                }

                $addressRows[] = [
                    'snapshot_item_id' => $item->snapshot_item_id,
                    'workspace_id' => $item->workspace_id,
                    'email_lookup_hash' => $item->email_lookup_hash,
                    'email' => Crypt::encryptString($email),
                    'created_at' => now(),
                ];
            }

            if ($addressRows !== []) {
                $backfilled += DB::table('campaign_recipient_snapshot_item_addresses')->insertOrIgnore($addressRows);
            }
        }

        $this->info("Scanned {$processed} legacy snapshot item(s); backfilled {$backfilled}; unresolved {$unresolved} because the current email no longer matches the captured identity.");

        return $unresolved === 0 ? self::SUCCESS : self::FAILURE;
    }
}
