<?php

namespace App\Console\Commands;

use App\Models\ContactListMembership;
use App\Services\Audiences\AudienceMembershipFilterIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

#[Signature('app:backfill-audience-mix-filter-indexes {--workspace= : Restrict the backfill to one workspace ID} {--list= : Restrict the backfill to one audience list ID} {--chunk=1000 : Membership rows processed per batch}')]
#[Description('Backfill keyed indexes used by audience mix sex and email-domain filters.')]
class BackfillAudienceMixFilterIndexes extends Command
{
    public function handle(AudienceMembershipFilterIndexer $indexer): int
    {
        if (! $indexer->isReady()) {
            $this->error('CONTACT_LOOKUP_KEY must be at least 32 characters and CONTACT_LOOKUP_KEY_VERSION must be between 1 and 32767.');

            return self::FAILURE;
        }

        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if (! is_int($chunkSize) || $chunkSize < 1) {
            $this->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $version = $indexer->version();
        $query = ContactListMembership::query()
            ->with('contact:id,email,profile')
            ->where(function (Builder $stale) use ($version): void {
                $stale->whereNull('attribute_index_version')
                    ->orWhere('attribute_index_version', '<>', $version)
                    ->orWhereNull('profile_sex_index')
                    ->orWhereNull('email_domain_index');
            })
            ->when($this->option('workspace'), fn (Builder $memberships, string $workspaceId) => $memberships->where('workspace_id', $workspaceId))
            ->when($this->option('list'), fn (Builder $memberships, string $listId) => $memberships->where('audience_list_id', $listId));

        $processed = 0;
        $updated = 0;
        $skipped = 0;

        $query->chunkById($chunkSize, function ($memberships) use ($indexer, $version, &$processed, &$updated, &$skipped): void {
            $rows = [];
            foreach ($memberships as $membership) {
                $processed++;
                $contact = $membership->contact;
                if (! $contact) {
                    $skipped++;

                    continue;
                }

                $profile = $membership->profile ?? $contact->profile ?? [];
                $rows[] = [
                    'id' => (string) $membership->getKey(),
                    'original_version' => $membership->getRawOriginal('attribute_index_version'),
                    'original_profile' => $membership->getRawOriginal('profile'),
                    'original_updated_at' => $membership->getRawOriginal('updated_at'),
                    ...$indexer->values(
                        $membership->workspace_id,
                        $membership->audience_list_id,
                        $profile,
                        (string) $contact->email,
                    ),
                ];
            }

            $batchUpdated = $this->backfillBatch($rows, $version);
            $updated += $batchUpdated;
            $skipped += count($rows) - $batchUpdated;
        }, 'id');

        $this->info("Processed {$processed} membership(s); backfilled {$updated}; skipped {$skipped} because the row changed or its contact is missing.");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array{id: string, original_version: mixed, original_profile: mixed, original_updated_at: mixed, profile_sex_index: string, email_domain_index: string, attribute_index_version: int}> $rows */
    private function backfillBatch(array $rows, int $version): int
    {
        if ($rows === []) {
            return 0;
        }

        $memberships = ContactListMembership::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->where(function (Builder $sameRows) use ($rows): void {
                foreach ($rows as $row) {
                    $sameRows->orWhere(function (Builder $sameRow) use ($row): void {
                        $sameRow->whereKey($row['id']);
                        if ($row['original_version'] === null) {
                            $sameRow->whereNull('attribute_index_version');
                        } else {
                            $sameRow->where('attribute_index_version', $row['original_version']);
                        }
                        if ($row['original_profile'] === null) {
                            $sameRow->whereNull('profile');
                        } else {
                            $sameRow->where('profile', $row['original_profile']);
                        }
                        if ($row['original_updated_at'] === null) {
                            $sameRow->whereNull('updated_at');
                        } else {
                            $sameRow->where('updated_at', $row['original_updated_at']);
                        }
                    });
                }
            });

        $grammar = $memberships->getQuery()->getGrammar();
        $pdo = DB::connection()->getPdo();
        $idColumn = $grammar->wrap('id');
        $case = function (string $valueColumn) use ($grammar, $pdo, $rows, $idColumn): string {
            $wrappedValueColumn = $grammar->wrap($valueColumn);
            $branches = array_map(
                fn (array $row): string => 'WHEN '.$this->sqlLiteral($pdo, $row['id']).' THEN '.$this->sqlLiteral($pdo, $row[$valueColumn]),
                $rows,
            );

            return 'CASE '.$idColumn.' '.implode(' ', $branches).' ELSE '.$wrappedValueColumn.' END';
        };

        return $memberships->toBase()->update([
            'profile_sex_index' => DB::raw($case('profile_sex_index')),
            'email_domain_index' => DB::raw($case('email_domain_index')),
            'attribute_index_version' => $version,
        ]);
    }

    private function sqlLiteral(PDO $pdo, string $value): string
    {
        $literal = $pdo->quote($value);
        if (! is_string($literal)) {
            throw new RuntimeException('Unable to safely prepare audience index backfill values.');
        }

        return $literal;
    }
}
