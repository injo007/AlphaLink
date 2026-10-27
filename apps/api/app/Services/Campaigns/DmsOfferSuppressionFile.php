<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class DmsOfferSuppressionFile
{
    private const DISK = 'local';

    /** @return array{path: string, sha256: string, format: 'email'|'md5', count: int} */
    public function store(Workspace $workspace, WorkspaceOffer $offer, UploadedFile $upload): array
    {
        $content = file_get_contents($upload->getRealPath());
        if (! is_string($content) || $content === '' || strlen($content) > 10_000_000) {
            throw ValidationException::withMessages(['suppression_file' => 'The suppression file is empty or exceeds 10 MB.']);
        }

        return $this->storeContent($workspace, $offer, $content);
    }

    /** @return array{path: string, sha256: string, format: 'email'|'md5', count: int} */
    public function storeContent(Workspace $workspace, WorkspaceOffer $offer, string $content): array
    {
        if ($content === '' || strlen($content) > 10_000_000) {
            throw ValidationException::withMessages(['suppression_file' => 'The suppression file is empty or exceeds 10 MB.']);
        }

        $parsed = $this->normalize($content);
        $sha256 = hash('sha256', $parsed['content']);
        $path = sprintf('offers/%s/%d/suppression/%s.txt', $workspace->getKey(), $offer->getKey(), $sha256);
        Storage::disk(self::DISK)->put($path, $parsed['content']);

        return ['path' => $path, 'sha256' => $sha256, 'format' => $parsed['format'], 'count' => $parsed['count']];
    }

    /**
     * Store a sponsor download without loading the file into PHP memory.
     *
     * @return array{path: string, sha256: string, format: 'email'|'md5', count: int}
     */
    public function storeDownloadedFile(Workspace $workspace, WorkspaceOffer $offer, string $sourcePath): array
    {
        $source = $sourcePath;
        $extractedPath = null;

        try {
            if (! is_file($source) || filesize($source) < 1 || filesize($source) > 1_073_741_824) {
                throw ValidationException::withMessages(['suppression_file' => 'The downloaded suppression file is empty or exceeds 1 GB.']);
            }

            $signature = file_get_contents($source, false, null, 0, 4);
            if ($signature === "PK\x03\x04") {
                $extractedPath = $this->extractLargestZipEntry($source);
                if ($extractedPath === null) {
                    throw ValidationException::withMessages(['suppression_file' => 'The sponsor archive does not contain a valid suppression list.']);
                }
                $source = $extractedPath;
            }

            return $this->normalizeDownloadedFile($workspace, $offer, $source);
        } finally {
            if (is_string($extractedPath) && is_file($extractedPath)) {
                @unlink($extractedPath);
            }
        }
    }

    /**
     * Check that the stored artifact is present, fresh, and still matches its persisted digest.
     */
    public function isEligible(WorkspaceOffer $offer): bool
    {
        if (! filled($offer->suppression_path)
            || ! filled($offer->suppression_sha256)
            || ! in_array($offer->suppression_format, ['email', 'md5'], true)
            || $offer->suppression_updated_at === null
            || $offer->suppression_updated_at->diffInDays(now()) > 7) {
            return false;
        }

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($offer->suppression_path)) {
            return false;
        }

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

    /** Build a session-scoped lookup table from the validated, canonical file. */
    public function createLookupTable(WorkspaceOffer $offer): string
    {
        $connection = DB::connection();
        $table = 'tmp_suppression_match_'.Str::lower(Str::random(18));
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $connection->statement("CREATE TEMPORARY TABLE {$wrapped} (entry_value VARCHAR(320) NOT NULL PRIMARY KEY)");
        $stream = Storage::disk(self::DISK)->readStream((string) $offer->suppression_path);
        if (! is_resource($stream)) {
            $connection->statement("DROP TABLE IF EXISTS {$wrapped}");
            throw new \RuntimeException('Unable to read the saved suppression file.');
        }

        $batch = [];
        try {
            while (($line = fgets($stream)) !== false) {
                $entry = trim($line);
                if ($entry === '') {
                    continue;
                }
                $batch[] = ['entry_value' => $entry];
                if (count($batch) >= 500) {
                    $connection->table($table)->insertOrIgnore($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $connection->table($table)->insertOrIgnore($batch);
            }
        } catch (\Throwable $exception) {
            $connection->statement("DROP TABLE IF EXISTS {$wrapped}");
            throw $exception;
        } finally {
            fclose($stream);
        }

        return $table;
    }

    public function dropLookupTable(string $table): void
    {
        if (preg_match('/^tmp_suppression_(?:match_)?[a-z0-9]{18}$/', $table) !== 1) {
            return;
        }

        try {
            $connection = DB::connection();
            $wrapped = $connection->getQueryGrammar()->wrapTable($table);
            $connection->statement("DROP TABLE IF EXISTS {$wrapped}");
        } catch (\Throwable) {
            // Session-scoped temporary tables are discarded when the connection closes.
        }
    }

    /**
     * @return array{path: string, sha256: string, format: 'email'|'md5', count: int}
     */
    private function normalizeDownloadedFile(Workspace $workspace, WorkspaceOffer $offer, string $sourcePath): array
    {
        $connection = DB::connection();
        $table = 'tmp_suppression_'.Str::lower(Str::random(18));
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $connection->statement("CREATE TEMPORARY TABLE {$wrapped} (entry_value VARCHAR(320) NOT NULL PRIMARY KEY)");
        $batch = [];
        $format = null;
        $sawEntries = false;
        $outputPath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-normalized-');

        try {
            $input = fopen($sourcePath, 'rb');
            if (! is_resource($input) || ! is_string($outputPath)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new \RuntimeException('Unable to open the downloaded suppression file.');
            }

            try {
                while (($line = fgets($input)) !== false) {
                    if (strlen($line) > 1_048_576) {
                        throw ValidationException::withMessages(['suppression_file' => 'The suppression file contains an oversized row.']);
                    }
                    if (! $sawEntries) {
                        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                    }
                    foreach (str_getcsv($line) as $cell) {
                        foreach (preg_split('/[;,]+/', (string) $cell) ?: [] as $entry) {
                            $entry = mb_strtolower(trim($entry, " \t\n\r\0\x0B\"'"));
                            if ($entry === '') {
                                continue;
                            }
                            $sawEntries = true;
                            $entryFormat = preg_match('/^[a-f0-9]{32}$/', $entry) === 1
                                ? 'md5'
                                : (filter_var($entry, FILTER_VALIDATE_EMAIL) !== false ? 'email' : null);
                            if ($entryFormat === null || ($format !== null && $format !== $entryFormat)) {
                                throw ValidationException::withMessages(['suppression_file' => 'Use one format per file: plain email addresses or 32-character MD5 hashes.']);
                            }
                            $format = $entryFormat;
                            $batch[] = ['entry_value' => $entry];
                            if (count($batch) >= 500) {
                                $connection->table($table)->insertOrIgnore($batch);
                                $batch = [];
                            }
                        }
                    }
                }
            } finally {
                fclose($input);
            }

            if ($batch !== []) {
                $connection->table($table)->insertOrIgnore($batch);
            }
            $count = (int) $connection->table($table)->count();
            if (! $sawEntries || $count < 1 || $count > 100_000_000 || ! in_array($format, ['email', 'md5'], true)) {
                throw ValidationException::withMessages(['suppression_file' => 'The suppression file must contain valid email addresses or 32-character MD5 hashes.']);
            }

            $output = fopen($outputPath, 'wb');
            if (! is_resource($output)) {
                throw new \RuntimeException('Unable to prepare the validated suppression file.');
            }
            $hash = hash_init('sha256');
            try {
                foreach ($connection->table($table)->orderBy('entry_value')->cursor() as $row) {
                    $normalizedLine = $row->entry_value."\n";
                    if (fwrite($output, $normalizedLine) !== strlen($normalizedLine)) {
                        throw new \RuntimeException('Unable to write the validated suppression file.');
                    }
                    hash_update($hash, $normalizedLine);
                }
            } finally {
                fclose($output);
            }

            $sha256 = hash_final($hash);
            $path = sprintf('offers/%s/%d/suppression/%s.txt', $workspace->getKey(), $offer->getKey(), $sha256);
            $stream = fopen($outputPath, 'rb');
            if (! is_resource($stream) || ! Storage::disk(self::DISK)->writeStream($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new \RuntimeException('Unable to store the validated suppression file.');
            }
            fclose($stream);

            return ['path' => $path, 'sha256' => $sha256, 'format' => $format, 'count' => $count];
        } finally {
            try {
                $connection->statement("DROP TABLE IF EXISTS {$wrapped}");
            } catch (\Throwable) {
                // The temporary table is scoped to this database session and will be discarded at disconnect.
            }
            if (is_string($outputPath) && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function extractLargestZipEntry(string $archivePath): ?string
    {
        $archive = new ZipArchive;
        if ($archive->open($archivePath) !== true || $archive->numFiles > 500) {
            return null;
        }

        $candidate = null;
        $largestSize = -1;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            $size = is_array($entry) ? (int) ($entry['size'] ?? -1) : -1;
            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_ends_with($name, '/') || $size < 0 || $size > 1_073_741_824) {
                continue;
            }
            if ($size > $largestSize) {
                $candidate = $index;
                $largestSize = $size;
            }
        }

        if ($candidate === null) {
            $archive->close();

            return null;
        }

        $input = $archive->getStream((string) $archive->getNameIndex($candidate));
        $outputPath = tempnam(sys_get_temp_dir(), 'alphalink-suppression-zip-');
        $output = is_string($outputPath) ? fopen($outputPath, 'wb') : false;
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_string($outputPath)) {
                @unlink($outputPath);
            }
            $archive->close();

            return null;
        }

        $written = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    @unlink($outputPath);

                    return null;
                }
                if ($chunk === '') {
                    if (! feof($input)) {
                        @unlink($outputPath);

                        return null;
                    }
                    break;
                }
                $written += strlen($chunk);
                if ($written > 1_073_741_824 || fwrite($output, $chunk) !== strlen($chunk)) {
                    @unlink($outputPath);

                    return null;
                }
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $archive->close();
        }

        if ($written < 1) {
            @unlink($outputPath);

            return null;
        }

        return $outputPath;
    }

    /** @return array{content: string, format: 'email'|'md5', count: int} */
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
        if ($values === [] || count($values) > 1_000_000) {
            throw ValidationException::withMessages(['suppression_file' => 'The suppression file must contain between 1 and 1,000,000 entries.']);
        }

        $isHash = array_reduce($values, fn (bool $valid, string $value): bool => $valid && preg_match('/^[a-f0-9]{32}$/', $value) === 1, true);
        $isEmail = array_reduce($values, fn (bool $valid, string $value): bool => $valid && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, true);
        if (! $isHash && ! $isEmail) {
            throw ValidationException::withMessages(['suppression_file' => 'Use one format per file: plain email addresses or 32-character MD5 hashes.']);
        }

        sort($values, SORT_STRING);
        $canonical = implode("\n", $values)."\n";

        return ['content' => $canonical, 'format' => $isHash ? 'md5' : 'email', 'count' => count($values)];
    }
}
