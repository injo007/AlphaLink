<?php

namespace App\Services\OpenObserve;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class OpenObserveLogHandler extends AbstractProcessingHandler
{
    private OpenObserveClient $client;

    public function __construct(
        ?OpenObserveClient $client = null,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->client = $client ?? OpenObserveClient::fromConfig();
    }

    protected function write(LogRecord $record): void
    {
        if (! config('openobserve.enabled', false) && ! $this->client->isConfigured()) {
            return;
        }

        try {
            $formatted = $this->formatRecord($record);
            $this->client->ingest([$formatted]);
        } catch (Throwable) {
            // Logging must never crash the application or fail silently to user
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRecord(LogRecord $record): array
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        $exceptionData = null;

        if ($exception instanceof Throwable) {
            $exceptionData = [
                'class' => get_class($exception),
                'message' => $this->redact($exception->getMessage()),
                'code' => $exception->getCode(),
                'file' => basename($exception->getFile()),
                'full_file' => $this->redactPath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => $this->redactTrace($exception->getTraceAsString()),
            ];
            unset($context['exception']);
        }

        $requestId = $record->extra['request_id'] ?? $context['request_id'] ?? null;
        $url = null;
        $method = null;
        $userId = null;
        $module = 'system';

        if (app()->bound('request')) {
            $request = request();
            if ($request) {
                $requestId = $requestId ?? $request->attributes->get('request_id') ?? $request->header('X-Request-ID');
                $url = $this->redact($request->fullUrl());
                $method = $request->method();
                $userId = $request->user()?->getKey();

                $path = $request->path();
                if (str_starts_with($path, 'api/')) {
                    $module = 'api';
                } elseif (str_contains($path, 'infrastructure') || str_contains($path, 'servers') || str_contains($path, 'pmta')) {
                    $module = 'infrastructure';
                } elseif (str_contains($path, 'campaign') || str_contains($path, 'mailer') || str_contains($path, 'warmup')) {
                    $module = 'campaign';
                } elseif (str_contains($path, 'tracking') || str_contains($path, 'drops')) {
                    $module = 'tracking';
                } elseif (str_contains($path, 'sign-in') || str_contains($path, 'profile') || str_contains($path, 'auth')) {
                    $module = 'auth';
                } elseif (str_contains($path, 'horizon')) {
                    $module = 'horizon';
                }
            }
        }

        if (app()->runningInConsole()) {
            if ($module === 'system') {
                $commandName = $_SERVER['argv'][1] ?? '';
                if (str_contains($commandName, 'horizon') || str_contains($commandName, 'queue')) {
                    $module = 'horizon';
                } elseif (str_contains($commandName, 'pmta') || str_contains($commandName, 'server')) {
                    $module = 'infrastructure';
                } elseif (str_contains($commandName, 'campaign') || str_contains($commandName, 'send')) {
                    $module = 'campaign';
                }
            }
        }

        $cleanMessage = $this->redact($record->message);

        return [
            '_timestamp' => (int) ($record->datetime->format('Uu')),
            'level' => strtoupper($record->level->getName()),
            'channel' => $record->channel,
            'message' => $cleanMessage,
            'module' => $module,
            'request_id' => $requestId,
            'user_id' => $userId,
            'http_method' => $method,
            'http_url' => $url,
            'file' => $exceptionData['file'] ?? null,
            'line' => $exceptionData['line'] ?? null,
            'exception' => $exceptionData,
            'context' => $this->redactContext($context),
            'environment' => app()->environment(),
        ];
    }

    public function redact(string $message): string
    {
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $message) ?? '';
        $message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message) ?? '';
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $message) ?? '';
        $message = preg_replace('/\b(password|passwd|secret|token|authorization|api[_-]?key)\b(\s*[=:]\s*)([^\s,;]+)/i', '$1$2[redacted]', $message) ?? '';
        $message = preg_replace('/\s+\(Connection:\s*.*$/i', ' [database query redacted]', $message) ?? '';
        $message = preg_replace('/\bSQL:\s*.*$/i', 'SQL: [redacted]', $message) ?? '';

        return trim($message);
    }

    private function redactPath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private function redactTrace(string $trace): string
    {
        $lines = explode("\n", $trace);
        $redacted = [];

        foreach (array_slice($lines, 0, 15) as $line) {
            $redacted[] = $this->redactPath($this->redact($line));
        }

        return implode("\n", $redacted);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = $this->redactContext($value);
            } elseif (is_string($value)) {
                $clean[$key] = $this->redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
