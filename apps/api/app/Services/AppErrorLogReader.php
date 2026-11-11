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

    /** @return list<array{date:string,level:string,summary:string,file:string,exception_class?:string,exception_file?:string,exception_line?:?int,trace?:?string}> */
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

            $rawEntries = [];
            $current = null;

            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line);
                if (preg_match('/^\[([^\]]+)\]\s+[^.]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s+(.*)$/i', $trimmed, $matches) === 1) {
                    if ($current !== null) {
                        $rawEntries[] = $current;
                    }
                    $current = [
                        'date' => $matches[1],
                        'level' => strtoupper($matches[2]),
                        'raw_message' => $matches[3],
                        'trace_lines' => [],
                    ];
                } elseif ($current !== null) {
                    if (preg_match('/^\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\]/', $trimmed) === 1) {
                        $rawEntries[] = $current;
                        $current = null;
                    } else {
                        if (count($current['trace_lines']) < 80) {
                            $current['trace_lines'][] = $trimmed;
                        }
                    }
                }
            }

            if ($current !== null) {
                $rawEntries[] = $current;
            }

            $entries = [];
            foreach ($rawEntries as $raw) {
                $rawMsg = $raw['raw_message'];
                $summary = $this->redact($rawMsg);

                $exceptionClass = null;
                $exceptionFile = null;
                $exceptionLine = null;

                if (preg_match('/\[object\]\s+\(([A-Za-z0-9_\\\\]+)\(code:\s*\d+\):\s*(.*?)\s+at\s+([^:]+):(\d+)\)/s', $rawMsg, $exMatches)) {
                    $exceptionClass = basename(str_replace('\\', '/', $exMatches[1]));
                    $exceptionFile = basename($exMatches[3]);
                    $exceptionLine = (int) $exMatches[4];
                } elseif (preg_match('/^([A-Za-z0-9_\\\\]+(?:Exception|Error)):\s*(.*)$/', $summary, $classMatches)) {
                    $exceptionClass = basename(str_replace('\\', '/', $classMatches[1]));
                }

                $traceText = '';
                if (! empty($raw['trace_lines'])) {
                    $cleanLines = [];
                    foreach ($raw['trace_lines'] as $tLine) {
                        $tLine = trim($tLine, "\r\n\"}");
                        if ($tLine === '' || $tLine === '[stacktrace]') {
                            continue;
                        }
                        $cleanLines[] = $this->redact($tLine);
                    }
                    $traceText = implode("\n", $cleanLines);
                }

                if (empty($traceText) && $exceptionClass) {
                    $traceText = "{$exceptionClass}: {$summary}\nat " . ($exceptionFile ?? basename($file)) . ($exceptionLine ? ":{$exceptionLine}" : "");
                }

                $entries[] = [
                    'date' => $raw['date'],
                    'level' => $raw['level'],
                    'summary' => $summary,
                    'file' => basename($file),
                    'exception_class' => $exceptionClass ?? 'RuntimeException',
                    'exception_file' => $exceptionFile ?? basename($file),
                    'exception_line' => $exceptionLine,
                    'trace' => $traceText ?: null,
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
