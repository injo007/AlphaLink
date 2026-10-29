<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class DmsOfferSuppressionFile
{
    private const DISK = 'local';

    private ?string $cachedLookupSecret = null;

    /** @return array{path: string, sha256: string, format: 'email'|'md5'|'sha512', count: int} */
    public function store(Workspace $workspace, WorkspaceOffer $offer, UploadedFile $upload): array
    {
        return $this->normalizeDownloadedFile($workspace, $offer, $upload->getRealPath());
    }

    /** @return array{path: string, sha256: string, format: 'email'|'md5'|'sha512', count: int} */
    public function storeContent(Workspace $workspace, WorkspaceOffer $offer, string $content): array
    {
        if ($content === '') {
            throw ValidationException::withMessages(['suppression_file' => 'The suppression file is empty.']);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-upload-');
        if (! is_string($temporaryPath) || file_put_contents($temporaryPath, $content) !== strlen($content)) {
            if (is_string($temporaryPath)) {
                @unlink($temporaryPath);
            }
            throw new \RuntimeException('Unable to stage the suppression file.');
        }

        try {
            return $this->normalizeDownloadedFile($workspace, $offer, $temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Store a sponsor download without loading the file into PHP memory.
     *
     * @return array{path: string, sha256: string, format: 'email'|'md5'|'sha512', count: int}
     */
    public function storeDownloadedFile(
        Workspace $workspace,
        WorkspaceOffer $offer,
        string $sourcePath,
        ?Closure $onProgress = null,
        ?int $downloadedBytes = null,
    ): array {
        $source = $sourcePath;
        $extractedPath = null;

        try {
            if (! is_file($source) || filesize($source) < 1) {
                throw ValidationException::withMessages(['suppression_file' => 'The suppression file is empty or unavailable.']);
            }

            $signature = file_get_contents($source, false, null, 0, 4);
            if ($signature === "PK\x03\x04") {
                $extractedPath = $this->extractLargestZipEntry($source, $onProgress);
                if ($extractedPath === null) {
                    throw ValidationException::withMessages(['suppression_file' => 'The sponsor archive does not contain a valid suppression list.']);
                }
                $source = $extractedPath;
            }

            return $this->normalizeDownloadedFile($workspace, $offer, $source, $onProgress, $downloadedBytes);
        } finally {
            if (is_string($extractedPath) && is_file($extractedPath)) {
                @unlink($extractedPath);
            }
        }
    }

    /** Check inexpensive metadata, freshness, and storage presence for campaign choices. */
    public function hasFreshStoredReference(WorkspaceOffer $offer): bool
    {
        if (! filled($offer->suppression_path)
            || ! filled($offer->suppression_sha256)
            || preg_match('/^[a-f0-9]{64}$/', (string) $offer->suppression_sha256) !== 1
            || ! in_array($offer->suppression_format, ['email', 'md5', 'sha512'], true)
            || $offer->suppression_updated_at === null
            || $offer->suppression_updated_at->diffInDays(now()) > 7) {
            return false;
        }

        return Storage::disk(self::DISK)->exists($offer->suppression_path);
    }

    /** Check a saved artifact without rehashing it after its exact lookup index is ready. */
    public function isEligible(WorkspaceOffer $offer): bool
    {
        if (! $this->hasFreshStoredReference($offer)) {
            return false;
        }

        if ($this->readyLookupIndexId($offer) !== null) {
            return true;
        }

        $disk = Storage::disk(self::DISK);
        $stream = $disk->readStream($offer->suppression_path);
        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if (! is_string($chunk)) {
                    return false;
                }
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_equals($offer->suppression_sha256, hash_final($context));
    }

    /** Check that the offer points to its own digest-addressed private file. */
    public function hasStoredFile(Workspace $workspace, WorkspaceOffer $offer): bool
    {
        return $this->hasStoredFileForWorkspaceId((string) $workspace->getKey(), $offer);
    }

    private function hasStoredFileForWorkspaceId(string $workspaceId, WorkspaceOffer $offer): bool
    {
        $sha256 = (string) $offer->suppression_sha256;
        $path = (string) $offer->suppression_path;
        $expectedPath = sprintf(
            'offers/%s/%d/suppression/%s.txt',
            $workspaceId,
            $offer->getKey(),
            $sha256,
        );

        return (string) $offer->workspace_id === $workspaceId
            && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1
            && hash_equals($expectedPath, $path)
            && Storage::disk(self::DISK)->exists($path);
    }

    /** Convert a normalized contact address to the key stored in the offer file. */
    public function lookupKeyForEmail(string $email, string $format): string
    {
        $email = mb_strtolower(trim($email));

        return match ($format) {
            'md5' => md5($email),
            'sha512' => hash('sha512', $email),
            default => $email,
        };
    }

    /** Return a keyed hash of the same normalized value stored in the offer file. */
    public function lookupHashForEmail(string $email, string $format): string
    {
        return hash_hmac('sha256', $this->lookupKeyForEmail($email, $format), $this->lookupSecret());
    }

    /**
     * Return the reusable exact-match index for an offer's current file.
     * The first use streams and verifies the canonical file; later runs query
     * the indexed hashes directly without rebuilding a session-sized table.
     */
    public function prepareLookupIndex(WorkspaceOffer $offer, ?Closure $onProgress = null): int
    {
        if (! $this->hasFreshStoredReference($offer)) {
            throw ValidationException::withMessages(['suppression' => 'The saved offer suppression file is missing, stale, or has invalid metadata. Upload a current DMS-format suppression file.']);
        }

        $secret = $this->lookupSecret();
        $fingerprint = hash('sha256', $secret);
        $readyId = $this->readyLookupIndexId($offer, $fingerprint);
        if ($readyId !== null) {
            $totalBytes = (int) (Storage::disk(self::DISK)->size((string) $offer->suppression_path) ?: 0);
            $onProgress?->__invoke($totalBytes, $totalBytes);

            return $readyId;
        }

        $lockKey = sprintf('offer-suppression-index:%s:%s:%s', (string) $offer->getKey(), (string) $offer->suppression_sha256, $fingerprint);

        return (int) Cache::lock($lockKey, 21_600)->block(21_600, function () use ($offer, $onProgress, $secret, $fingerprint): int {
            $readyId = $this->readyLookupIndexId($offer, $fingerprint);
            if ($readyId !== null) {
                $totalBytes = (int) (Storage::disk(self::DISK)->size((string) $offer->suppression_path) ?: 0);
                $onProgress?->__invoke($totalBytes, $totalBytes);

                return $readyId;
            }

            return $this->buildLookupIndex($offer, $secret, $fingerprint, $onProgress);
        });
    }

    private function readyLookupIndexId(WorkspaceOffer $offer, ?string $fingerprint = null): ?int
    {
        try {
            $fingerprint ??= hash('sha256', $this->lookupSecret());
        } catch (ValidationException) {
            return null;
        }

        $bytes = (int) (Storage::disk(self::DISK)->size((string) $offer->suppression_path) ?: 0);
        $indexId = DB::table('workspace_offer_suppression_indexes')
            ->where('workspace_id', $offer->workspace_id)
            ->where('offer_id', $offer->getKey())
            ->where('suppression_sha256', $offer->suppression_sha256)
            ->where('suppression_format', $offer->suppression_format)
            ->where('key_fingerprint', $fingerprint)
            ->where('file_bytes', $bytes)
            ->where('status', 'ready')
            ->whereNotNull('ready_at')
            ->value('id');

        return $indexId === null ? null : (int) $indexId;
    }

    private function buildLookupIndex(WorkspaceOffer $offer, string $secret, string $fingerprint, ?Closure $onProgress): int
    {
        if (! $this->hasFreshStoredReference($offer)) {
            throw ValidationException::withMessages(['suppression' => 'The saved offer suppression file is missing, stale, or has invalid metadata. Upload a current DMS-format suppression file.']);
        }

        $index = DB::table('workspace_offer_suppression_indexes')
            ->where('offer_id', $offer->getKey())
            ->where('suppression_sha256', $offer->suppression_sha256)
            ->where('key_fingerprint', $fingerprint)
            ->first();
        $now = now();
        $indexId = $index === null
            ? (int) DB::table('workspace_offer_suppression_indexes')->insertGetId([
                'workspace_id' => $offer->workspace_id,
                'offer_id' => $offer->getKey(),
                'suppression_sha256' => $offer->suppression_sha256,
                'suppression_format' => $offer->suppression_format,
                'key_fingerprint' => $fingerprint,
                'file_bytes' => 0,
                'entry_count' => 0,
                'status' => 'building',
                'ready_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            : (int) $index->id;

        DB::table('workspace_offer_suppression_index_entries')->where('suppression_index_id', $indexId)->delete();
        DB::table('workspace_offer_suppression_indexes')->where('id', $indexId)->update([
            'workspace_id' => $offer->workspace_id,
            'suppression_format' => $offer->suppression_format,
            'file_bytes' => 0,
            'entry_count' => 0,
            'status' => 'building',
            'ready_at' => null,
            'updated_at' => $now,
        ]);

        $disk = Storage::disk(self::DISK);
        $stream = $disk->readStream((string) $offer->suppression_path);
        if (! is_resource($stream)) {
            DB::table('workspace_offer_suppression_index_entries')->where('suppression_index_id', $indexId)->delete();
            DB::table('workspace_offer_suppression_indexes')->where('id', $indexId)->update(['status' => 'failed', 'updated_at' => now()]);
            throw new \RuntimeException('Unable to read the saved suppression file.');
        }

        $batch = [];
        $digest = hash_init('sha256');
        $reportedBytes = 0;
        $totalBytes = (int) ($disk->size((string) $offer->suppression_path) ?: 0);
        $entryCount = 0;
        try {
            while (($line = fgets($stream)) !== false) {
                hash_update($digest, $line);
                $entry = trim($line);
                if ($entry !== '') {
                    $batch[] = [
                        'suppression_index_id' => $indexId,
                        'entry_hash' => hash_hmac('sha256', $entry, $secret),
                    ];
                    $entryCount++;
                    if (count($batch) >= 2_000) {
                        DB::table('workspace_offer_suppression_index_entries')->insertOrIgnore($batch);
                        $batch = [];
                    }
                }

                $readBytes = ftell($stream);
                if ($onProgress !== null && is_int($readBytes) && $readBytes - $reportedBytes >= 8 * 1024 * 1024) {
                    $reportedBytes = $readBytes;
                    $onProgress($readBytes, $totalBytes);
                }
            }
            if (! feof($stream)) {
                throw new \RuntimeException('Unable to finish reading the saved suppression file.');
            }
            if ($batch !== []) {
                DB::table('workspace_offer_suppression_index_entries')->insertOrIgnore($batch);
            }
            if (! hash_equals((string) $offer->suppression_sha256, hash_final($digest))) {
                throw ValidationException::withMessages(['suppression' => 'The saved offer suppression file changed after validation. Upload a current DMS-format suppression file.']);
            }
            if ($onProgress !== null) {
                $onProgress($totalBytes > 0 ? $totalBytes : $reportedBytes, $totalBytes);
            }
            DB::table('workspace_offer_suppression_indexes')->where('id', $indexId)->update([
                'file_bytes' => $totalBytes,
                'entry_count' => $entryCount,
                'status' => 'ready',
                'ready_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            DB::table('workspace_offer_suppression_index_entries')->where('suppression_index_id', $indexId)->delete();
            DB::table('workspace_offer_suppression_indexes')->where('id', $indexId)->update([
                'entry_count' => 0,
                'status' => 'failed',
                'ready_at' => null,
                'updated_at' => now(),
            ]);
            throw $exception;
        } finally {
            fclose($stream);
        }

        return $indexId;
    }

    private function lookupSecret(): string
    {
        if ($this->cachedLookupSecret !== null) {
            return $this->cachedLookupSecret;
        }

        $secret = config('alphalink.contact_lookup_key');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw ValidationException::withMessages([
                'suppression' => 'CONTACT_LOOKUP_KEY must be configured before suppression matching can run.',
            ]);
        }

        return $this->cachedLookupSecret = $secret;
    }

    /**
     * @return array{path: string, sha256: string, format: 'email'|'md5'|'sha512', count: int}
     */
    private function normalizeDownloadedFile(
        Workspace $workspace,
        WorkspaceOffer $offer,
        string $sourcePath,
        ?Closure $onProgress = null,
        ?int $downloadedBytes = null,
    ): array {
        $connection = DB::connection();
        $table = 'tmp_suppression_'.Str::lower(Str::random(18));
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $connection->statement("CREATE TEMPORARY TABLE {$wrapped} (entry_value VARCHAR(320) NOT NULL PRIMARY KEY)");
        $batch = [];
        $format = null;
        $sawEntries = false;
        $discardingLongEntry = false;
        $uniqueCount = 0;
        $disk = Storage::disk(self::DISK);
        $temporaryPath = sprintf(
            'offers/%s/%d/suppression/.%s.normalizing.tmp',
            $workspace->getKey(),
            $offer->getKey(),
            Str::uuid(),
        );
        $sourceBytes = (int) (filesize($sourcePath) ?: 0);
        $lastReportedBytes = 0;
        $lastReportedAt = microtime(true);
        $batchSize = 5_000;

        try {
            $input = fopen($sourcePath, 'rb');
            if (! is_resource($input)) {
                throw new \RuntimeException('Unable to open the downloaded suppression file.');
            }

            try {
                $flushBatch = function () use ($connection, $table, &$batch, &$uniqueCount): void {
                    if ($batch === []) {
                        return;
                    }

                    $uniqueCount += $connection->table($table)->insertOrIgnore($batch);
                    $batch = [];
                };
                $processEntry = function (string $entry) use (&$batch, &$format, &$sawEntries, $batchSize, $flushBatch): void {
                    $entry = mb_strtolower(trim($entry, " \t\n\r\0\x0B\"'"));
                    if ($entry === '') {
                        return;
                    }
                    $sawEntries = true;
                    $length = strlen($entry);
                    $hexCharacters = '0123456789abcdef';
                    $entryFormat = $length === 128 && strspn($entry, $hexCharacters) === 128
                        ? 'sha512'
                        : ($length === 32 && strspn($entry, $hexCharacters) === 32
                            ? 'md5'
                            : (filter_var($entry, FILTER_VALIDATE_EMAIL) !== false ? 'email' : null));
                    if ($entryFormat === null || ($format !== null && $format !== $entryFormat)) {
                        throw ValidationException::withMessages(['suppression_file' => 'Use one format per file: plain email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes.']);
                    }
                    $format = $entryFormat;
                    $batch[] = ['entry_value' => $entry];
                    if (count($batch) >= $batchSize) {
                        $flushBatch();
                    }
                };

                $carry = '';
                $firstChunk = true;
                $readBytes = 0;
                while (! feof($input)) {
                    $chunk = fread($input, 65_536);
                    if (! is_string($chunk)) {
                        throw new \RuntimeException('Unable to read the suppression file.');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $readBytes += strlen($chunk);
                    if ($firstChunk) {
                        $chunk = preg_replace('/^\xEF\xBB\xBF/', '', $chunk) ?? $chunk;
                        $firstChunk = false;
                    }

                    $parts = preg_split('/[\r\n,;]+/', $carry.$chunk) ?: [];
                    $carry = array_pop($parts) ?? '';
                    if ($discardingLongEntry) {
                        if ($parts === []) {
                            $carry = '';

                            continue;
                        }
                        array_shift($parts);
                        $discardingLongEntry = false;
                    }
                    foreach ($parts as $entry) {
                        $processEntry($entry);
                    }
                    if (strlen($carry) > 320) {
                        $carry = '';
                        $discardingLongEntry = true;
                    }
                    $now = microtime(true);
                    if ($onProgress !== null && ($readBytes - $lastReportedBytes >= 4_194_304 || $now - $lastReportedAt >= 2.0)) {
                        $onProgress($readBytes, $sourceBytes, 'validating');
                        $lastReportedBytes = $readBytes;
                        $lastReportedAt = $now;
                    }
                }
                if ($discardingLongEntry) {
                    throw ValidationException::withMessages(['suppression_file' => 'Entries must be valid email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes.']);
                }
                $processEntry($carry);
                if ($onProgress !== null) {
                    $onProgress($sourceBytes, $sourceBytes, 'validating');
                }
            } finally {
                fclose($input);
            }

            $flushBatch();
            $count = $uniqueCount;
            if (! $sawEntries || $count < 1 || ! in_array($format, ['email', 'md5', 'sha512'], true)) {
                throw ValidationException::withMessages(['suppression_file' => 'The suppression file must contain valid email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes.']);
            }

            $disk->makeDirectory(dirname($temporaryPath));
            $output = fopen($disk->path($temporaryPath), 'wb');
            if (! is_resource($output)) {
                throw new \RuntimeException('Unable to prepare the validated suppression file.');
            }
            $hash = hash_init('sha256');
            $writtenEntries = 0;
            $lastSavedProgressAt = microtime(true);
            if ($onProgress !== null) {
                $onProgress($downloadedBytes ?? $sourceBytes, $downloadedBytes ?? $sourceBytes, 'saving', 0, $count);
            }
            try {
                foreach ($connection->table($table)->orderBy('entry_value')->cursor() as $row) {
                    $normalizedLine = $row->entry_value."\n";
                    if (fwrite($output, $normalizedLine) !== strlen($normalizedLine)) {
                        throw new \RuntimeException('Unable to write the validated suppression file.');
                    }
                    hash_update($hash, $normalizedLine);
                    $writtenEntries++;
                    $now = microtime(true);
                    if ($onProgress !== null && ($writtenEntries % 100_000 === 0 || $now - $lastSavedProgressAt >= 2.0)) {
                        $onProgress($downloadedBytes ?? $sourceBytes, $downloadedBytes ?? $sourceBytes, 'saving', $writtenEntries, $count);
                        $lastSavedProgressAt = $now;
                    }
                }
                if ($onProgress !== null) {
                    $onProgress($downloadedBytes ?? $sourceBytes, $downloadedBytes ?? $sourceBytes, 'saving', $writtenEntries, $count);
                }
            } finally {
                fclose($output);
            }

            $sha256 = hash_final($hash);
            $path = sprintf('offers/%s/%d/suppression/%s.txt', $workspace->getKey(), $offer->getKey(), $sha256);
            if (! $disk->move($temporaryPath, $path)) {
                throw new \RuntimeException('Unable to store the validated suppression file.');
            }

            return ['path' => $path, 'sha256' => $sha256, 'format' => $format, 'count' => $count];
        } finally {
            try {
                $connection->statement("DROP TABLE IF EXISTS {$wrapped}");
            } catch (\Throwable) {
                // The temporary table is scoped to this database session and will be discarded at disconnect.
            }
            $disk->delete($temporaryPath);
        }
    }

    private function extractLargestZipEntry(string $archivePath, ?Closure $onProgress = null): ?string
    {
        $archive = new ZipArchive;
        if ($archive->open($archivePath) !== true) {
            return null;
        }

        $input = null;
        $output = null;
        $outputPath = null;
        $createdOutput = false;
        $completed = false;
        try {
            if ($archive->numFiles > 500) {
                return null;
            }

            $candidate = null;
            $largestSize = -1;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
                $size = is_array($entry) ? (int) ($entry['size'] ?? -1) : -1;
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_ends_with($name, '/') || $size < 0) {
                    continue;
                }
                if ($size > $largestSize) {
                    $candidate = $index;
                    $largestSize = $size;
                }
            }

            if ($candidate === null) {
                return null;
            }

            $input = $archive->getStream((string) $archive->getNameIndex($candidate));
            $disk = Storage::disk(self::DISK);
            $relativeOutputPath = 'offers/.downloads/'.Str::uuid().'.extracted.tmp';
            $disk->makeDirectory(dirname($relativeOutputPath));
            $outputPath = $disk->path($relativeOutputPath);
            $output = is_resource($input) ? fopen($outputPath, 'xb') : false;
            $createdOutput = is_resource($output);
            if (! is_resource($input) || ! is_resource($output)) {
                return null;
            }

            $written = 0;
            $lastReportedBytes = 0;
            $lastReportedAt = microtime(true);
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read the sponsor suppression archive.');
                }
                if ($chunk === '') {
                    if (! feof($input)) {
                        throw new \RuntimeException('Unable to finish reading the sponsor suppression archive.');
                    }
                    break;
                }
                $written += strlen($chunk);
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Unable to finish writing the extracted suppression archive.');
                }
                $now = microtime(true);
                if ($onProgress !== null && ($written - $lastReportedBytes >= 4_194_304 || $now - $lastReportedAt >= 2.0)) {
                    $onProgress($written, $largestSize, 'extracting');
                    $lastReportedBytes = $written;
                    $lastReportedAt = $now;
                }
            }
            if ($written < 1) {
                return null;
            }

            if (! @fclose($output)) {
                $output = null;

                throw new \RuntimeException('Unable to finish writing the extracted suppression archive.');
            }
            $output = null;

            if (! @fclose($input)) {
                $input = null;

                throw new \RuntimeException('Unable to finish reading the sponsor suppression archive.');
            }
            $input = null;

            $completed = true;
            if ($onProgress !== null) {
                $onProgress($largestSize, $largestSize, 'extracting');
            }

            return $outputPath;
        } finally {
            if (is_resource($input)) {
                @fclose($input);
            }
            if (is_resource($output)) {
                @fclose($output);
            }
            $archive->close();
            if (! $completed && $createdOutput && is_string($outputPath) && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /** @return array{content: string, format: 'email'|'md5'|'sha512', count: int} */
    public function normalize(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $values = [];
        foreach (preg_split('/[\r\n]+/', $content) ?: [] as $line) {
            foreach (str_getcsv($line) as $cell) {
                foreach (preg_split('/[;,]+/', (string) $cell) ?: [] as $value) {
                    $value = mb_strtolower(trim($value, " \t\n\r\0\x0B\"'"));
                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
            }
        }

        $values = array_values(array_unique($values));
        if ($values === []) {
            throw ValidationException::withMessages(['suppression_file' => 'The suppression file must contain at least one entry.']);
        }

        $isSha512 = array_reduce($values, fn (bool $valid, string $value): bool => $valid && preg_match('/^[a-f0-9]{128}$/', $value) === 1, true);
        $isHash = array_reduce($values, fn (bool $valid, string $value): bool => $valid && preg_match('/^[a-f0-9]{32}$/', $value) === 1, true);
        $isEmail = array_reduce($values, fn (bool $valid, string $value): bool => $valid && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, true);
        if (! $isSha512 && ! $isHash && ! $isEmail) {
            throw ValidationException::withMessages(['suppression_file' => 'Use one format per file: plain email addresses, 32-character MD5 hashes, or 128-character SHA-512 hashes.']);
        }

        sort($values, SORT_STRING);
        $canonical = implode("\n", $values)."\n";

        return ['content' => $canonical, 'format' => $isSha512 ? 'sha512' : ($isHash ? 'md5' : 'email'), 'count' => count($values)];
    }
}
