<?php

namespace App\Services;

use Throwable;

class AppLogArchiveService
{
    private const MAX_RETAINED_ARCHIVES = 5;

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * Safely archives and rotates the active laravel.log file with compression and retention limits.
     *
     * @return array{success: bool, archived_file: ?string, size_bytes: int, retained_count: int, error: ?string}
     */
    public function archiveAndRotate(int $retention = self::MAX_RETAINED_ARCHIVES): array
    {
        $directory = $this->directory ?? storage_path('logs');
        if (! is_dir($directory) || is_link($directory)) {
            return [
                'success' => false,
                'archived_file' => null,
                'size_bytes' => 0,
                'retained_count' => 0,
                'error' => 'Logs directory does not exist or is a symlink.',
            ];
        }

        $activeLog = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel.log';
        if (! file_exists($activeLog) || ! is_file($activeLog)) {
            return [
                'success' => true,
                'archived_file' => null,
                'size_bytes' => 0,
                'retained_count' => $this->countRetainedArchives($directory),
                'error' => 'No active laravel.log file found to archive.',
            ];
        }

        $size = filesize($activeLog);
        if ($size === 0) {
            return [
                'success' => true,
                'archived_file' => null,
                'size_bytes' => 0,
                'retained_count' => $this->countRetainedArchives($directory),
                'error' => 'Log file is already empty.',
            ];
        }

        $timestamp = now()->format('Y-m-d_His');
        $archiveBase = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."laravel-archive-{$timestamp}";

        try {
            // Compress with gzip if zlib extension is available
            if (extension_loaded('zlib') && function_exists('gzopen')) {
                $archiveFile = "{$archiveBase}.log.gz";
                $src = fopen($activeLog, 'rb');
                $dest = gzopen($archiveFile, 'wb9');

                if ($src && $dest) {
                    while (! feof($src)) {
                        gzwrite($dest, fread($src, 65536));
                    }
                    fclose($src);
                    gzclose($dest);
                    chmod($archiveFile, 0664);
                } else {
                    if ($src) fclose($src);
                    if ($dest) gzclose($dest);
                    // Fallback to plain rename
                    $archiveFile = "{$archiveBase}.log";
                    copy($activeLog, $archiveFile);
                }
            } else {
                $archiveFile = "{$archiveBase}.log";
                copy($activeLog, $archiveFile);
            }

            // Truncate active log file and keep proper permissions
            $handle = fopen($activeLog, 'w');
            if ($handle) {
                fclose($handle);
                chmod($activeLog, 0664);
            }

            // Enforce retention: keep newest N archives, safely remove older ones
            $retainedCount = $this->enforceRetention($directory, max(1, $retention));

            return [
                'success' => true,
                'archived_file' => basename($archiveFile),
                'size_bytes' => $size,
                'retained_count' => $retainedCount,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'archived_file' => null,
                'size_bytes' => $size,
                'retained_count' => $this->countRetainedArchives($directory),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Keep newest $retention archives, prune older ones.
     */
    private function enforceRetention(string $directory, int $retention): int
    {
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel-archive-*';
        $files = glob($pattern) ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        if (count($files) > $retention) {
            $toDelete = array_slice($files, $retention);
            foreach ($toDelete as $oldFile) {
                if (is_file($oldFile) && ! is_link($oldFile)) {
                    @unlink($oldFile);
                }
            }
        }

        return min(count($files), $retention);
    }

    private function countRetainedArchives(string $directory): int
    {
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel-archive-*';
        $files = glob($pattern) ?: [];
        return count($files);
    }
}
