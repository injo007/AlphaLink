<?php

namespace App\Services;

class AppErrorLogReader
{
    private const MAX_BYTES_PER_FILE = 2_097_152;

    private const MAX_ENTRIES = 100;

    public function __construct(private readonly ?string $directory = null) {}

    /** @return list<array{date:string,level:string,summary:string,file:string}> */
    public function entries(): array
    {
        $directory = $this->directory ?? storage_path('logs');
        if (! is_dir($directory) || is_link($directory)) {
            return [];
        }

        $files = array_values(array_filter(
            glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laravel*.log') ?: [],
            fn (string $file): bool => is_file($file) && ! is_link($file),
        ));
        usort($files, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        $entries = [];
        foreach ($files as $file) {
            foreach ($this->readFile($file) as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /** @return list<array{date:string,level:string,summary:string,file:string}> */
    private function readFile(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($size > self::MAX_BYTES_PER_FILE) {
                fseek($handle, -self::MAX_BYTES_PER_FILE, SEEK_END);
                fgets($handle);
            }

            $entries = [];
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\[([^\]]+)\]\s+[^.]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s+(.*)$/i', rtrim($line), $matches) !== 1) {
                    continue;
                }

                $entries[] = [
                    'date' => $matches[1],
                    'level' => strtoupper($matches[2]),
                    'summary' => $this->redact($matches[3]),
                    'file' => basename($file),
                ];
            }

            return $entries;
        } finally {
            fclose($handle);
        }
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/\s+\{(?:"exception"|"userId"|"requestId").*$/', '', $message) ?? '';
        $message = preg_replace('/\s+\(Connection:\s*.*$/i', ' [database query redacted]', $message) ?? '';
        $message = preg_replace('/\bSQL:\s*.*$/i', 'SQL: [redacted]', $message) ?? '';
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $message) ?? '';
        $message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message) ?? '';
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $message) ?? '';
        $message = preg_replace('/\b(password|passwd|secret|token|authorization|api[_-]?key)\b(\s*[=:]\s*)([^\s,;]+)/i', '$1$2[redacted]', $message) ?? '';
        $message = preg_replace('/https?:\/\/[^\s"\']+/i', '[url]', $message) ?? '';

        return mb_substr(trim($message), 0, 240);
    }
}
