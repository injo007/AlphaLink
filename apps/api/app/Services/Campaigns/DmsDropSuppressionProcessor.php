<?php

namespace App\Services\Campaigns;

use App\Models\ContactListMembership;
use App\Models\WorkspaceDropSuppression;
use App\Models\WorkspaceOffer;
use App\Services\AffiliateNetworks\DmsAffiliateSuppressionRefresher;
use Illuminate\Support\Facades\DB;
use Throwable;

class DmsDropSuppressionProcessor
{
    private const ACTIVE_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    private const INSERT_CHUNK_SIZE = 100;

    public function process(int $suppressionId): void
    {
        $run = WorkspaceDropSuppression::query()->findOrFail($suppressionId);
        if ($this->isTerminal($run->status)) {
            return;
        }

        $started = microtime(true);
        $found = 0;
        $dataCount = 0;
        $stagingPath = null;
        $stagingStream = null;
        $lookupTable = null;

        try {
            $offer = WorkspaceOffer::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($run->offer_id)
                ->firstOrFail();
            $files = app(DmsOfferSuppressionFile::class);
            if (! $files->isEligible($offer)) {
                if (! $this->setStatusUnlessKilled($run, 'downloading')) {
                    return;
                }

                $refreshed = app(DmsAffiliateSuppressionRefresher::class)->refresh($offer->workspace, $offer);
                if ($this->wasKilled($run)) {
                    return;
                }

                if (! $refreshed) {
                    $this->finish($run, 'error NFS', 0, $started, 'The current suppression file could not be refreshed from its affiliate source.');

                    return;
                }
                $offer->refresh();
                if (! $files->isEligible($offer)) {
                    $this->finish($run, 'error NFS', 0, $started, 'The refreshed suppression file is missing, changed, stale, or invalid.');

                    return;
                }
            }

            $lookupTable = $files->createLookupTable($offer);
            if (! $this->setStatusUnlessKilled($run, 'progress')) {
                return;
            }

            $stagingPath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-');
            if (! is_string($stagingPath)) {
                throw new \RuntimeException('Unable to create the suppression match staging file.');
            }
            $stagingStream = fopen($stagingPath, 'wb');
            if (! is_resource($stagingStream)) {
                throw new \RuntimeException('Unable to open the suppression match staging file.');
            }

            foreach ($run->lists ?? [] as $listId) {
                if ($this->wasKilled($run)) {
                    return;
                }

                $memberships = ContactListMembership::query()
                    ->with('contact:id,workspace_id,email,status')
                    ->where('workspace_id', $run->workspace_id)
                    ->where('audience_list_id', (string) $listId)
                    ->where('status', 'active')
                    ->whereIn('email_type', self::ACTIVE_TYPES)
                    ->whereHas('contact', fn ($query) => $query->where('status', 'active'))
                    ->whereNotExists(function ($query) use ($run, $listId): void {
                        $query->selectRaw('1')->from('workspace_offer_list_suppressions')
                            ->whereColumn('workspace_offer_list_suppressions.contact_id', 'contact_list_memberships.contact_id')
                            ->whereColumn('workspace_offer_list_suppressions.audience_list_id', 'contact_list_memberships.audience_list_id')
                            ->where('workspace_offer_list_suppressions.workspace_id', $run->workspace_id)
                            ->where('workspace_offer_list_suppressions.offer_id', $run->offer_id)
                            ->where('workspace_offer_list_suppressions.audience_list_id', (string) $listId);
                    });

                $memberships->chunkById(500, function ($chunk) use ($run, $offer, $files, $lookupTable, $stagingStream, &$dataCount, $listId): bool {
                    if ($this->wasKilled($run)) {
                        return false;
                    }

                    $dataCount += $chunk->count();
                    $candidateKeys = [];
                    $keysByContact = [];
                    foreach ($chunk as $membership) {
                        $contact = $membership->contact;
                        if ($contact === null) {
                            continue;
                        }
                        $email = mb_strtolower(trim((string) $contact->email));
                        $key = $files->lookupKeyForEmail($email, (string) $offer->suppression_format);
                        $candidateKeys[] = $key;
                        $keysByContact[(string) $contact->getKey()] = $key;
                    }
                    $matchedKeys = $candidateKeys === []
                        ? []
                        : DB::table($lookupTable)->whereIn('entry_value', array_values(array_unique($candidateKeys)))
                            ->pluck('entry_value')->flip()->all();
                    $matches = [];
                    foreach ($chunk as $membership) {
                        $contact = $membership->contact;
                        if ($contact === null) {
                            continue;
                        }
                        $key = $keysByContact[(string) $contact->getKey()] ?? null;
                        if (is_string($key) && isset($matchedKeys[$key])) {
                            $matches[] = [
                                'workspace_id' => $run->workspace_id,
                                'offer_id' => $offer->getKey(),
                                'audience_list_id' => (string) $listId,
                                'contact_id' => $contact->getKey(),
                                'suppression_run_id' => $run->getKey(),
                                'suppression_sha256' => $offer->suppression_sha256,
                                'created_at' => now(),
                            ];
                        }
                    }

                    if ($matches !== []) {
                        $this->writeMatches($stagingStream, $matches);
                    }

                    return ! $this->wasKilled($run);
                }, 'contact_list_memberships.id', 'id');
            }

            if ($this->wasKilled($run)) {
                return;
            }

            if (! fflush($stagingStream)) {
                throw new \RuntimeException('Unable to flush the suppression match staging file.');
            }
            fclose($stagingStream);
            $stagingStream = null;

            $status = $dataCount === 0 ? 'complete NE' : (DB::table($lookupTable)->exists() ? 'complete' : 'complete NS');
            $found = $this->importMatchesAndFinish($run, $stagingPath, $status, $started);
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($run, 'error EX', $found, $started, 'Suppression processing failed. Check the application error log.');
        } finally {
            if (is_string($lookupTable)) {
                app(DmsOfferSuppressionFile::class)->dropLookupTable($lookupTable);
            }
            if (is_resource($stagingStream)) {
                fclose($stagingStream);
            }
            if (is_string($stagingPath) && is_file($stagingPath)) {
                @unlink($stagingPath);
            }
        }
    }

    private function wasKilled(WorkspaceDropSuppression $run): bool
    {
        return $run->fresh()?->status === 'killed';
    }

    private function setStatusUnlessKilled(WorkspaceDropSuppression $run, string $status): bool
    {
        return WorkspaceDropSuppression::query()
            ->whereKey($run->getKey())
            ->where('status', 'not like', 'complete%')
            ->where('status', 'not like', 'error%')
            ->where('status', '!=', 'killed')
            ->update(['status' => $status]) > 0;
    }

    /**
     * @param  resource  $stream
     * @param  list<array<string, mixed>>  $matches
     */
    private function writeMatches($stream, array $matches): void
    {
        foreach ($matches as $match) {
            $line = json_encode($match, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($stream, $line) !== strlen($line)) {
                throw new \RuntimeException('Unable to stage a suppression match.');
            }
        }
    }

    private function importMatchesAndFinish(WorkspaceDropSuppression $run, string $stagingPath, string $status, float $started): int
    {
        return DB::transaction(function () use ($run, $stagingPath, $status, $started): int {
            $lockedRun = WorkspaceDropSuppression::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($this->isTerminal($lockedRun->status)) {
                return 0;
            }

            $stream = fopen($stagingPath, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to read the suppression match staging file.');
            }

            $found = 0;
            $batch = [];
            try {
                while (($line = fgets($stream)) !== false) {
                    $match = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($match)) {
                        throw new \UnexpectedValueException('The suppression match staging file is invalid.');
                    }
                    $batch[] = $match;
                    if (count($batch) >= self::INSERT_CHUNK_SIZE) {
                        $found += DB::table('workspace_offer_list_suppressions')->insertOrIgnore($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $found += DB::table('workspace_offer_list_suppressions')->insertOrIgnore($batch);
                }
            } finally {
                fclose($stream);
            }

            $seconds = max(0, (int) round(microtime(true) - $started));
            $elapsed = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            $lockedRun->forceFill([
                'status' => $status,
                'found' => $found,
                'elapsed' => $elapsed,
                'failure_message' => null,
                'finished_at' => now(),
            ])->save();

            return $found;
        });
    }

    private function isTerminal(string $status): bool
    {
        return str_contains($status, 'complete') || str_contains($status, 'error') || str_contains($status, 'killed');
    }

    private function finish(WorkspaceDropSuppression $run, string $status, int $found, float $started, ?string $message = null): void
    {
        $seconds = max(0, (int) round(microtime(true) - $started));
        $elapsed = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        WorkspaceDropSuppression::query()
            ->whereKey($run->getKey())
            ->where('status', 'not like', 'complete%')
            ->where('status', 'not like', 'error%')
            ->where('status', '!=', 'killed')
            ->update([
                'status' => $status,
                'found' => $found,
                'elapsed' => $elapsed,
                'failure_message' => $message,
                'finished_at' => now(),
            ]);
    }
}
