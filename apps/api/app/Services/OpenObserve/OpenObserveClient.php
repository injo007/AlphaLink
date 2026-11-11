<?php

namespace App\Services\OpenObserve;

use Illuminate\Support\Facades\Http;
use Throwable;

class OpenObserveClient
{
    private ?string $lastError = null;

    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $org = null,
        private readonly ?string $stream = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?string $token = null,
        private readonly float $timeout = 3.0,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            url: config('openobserve.url'),
            org: config('openobserve.org', 'default'),
            stream: config('openobserve.stream', 'default'),
            username: config('openobserve.username'),
            password: config('openobserve.password'),
            token: config('openobserve.token'),
            timeout: (float) config('openobserve.timeout', 3.0),
        );
    }

    public function getUrl(): string
    {
        return rtrim($this->url ?? (string) config('openobserve.url', 'http://127.0.0.1:5080'), '/');
    }

    public function getOrg(): string
    {
        return (string) ($this->org ?? config('openobserve.org', 'default'));
    }

    public function getStream(): string
    {
        return (string) ($this->stream ?? config('openobserve.stream', 'default'));
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getUrl()) && (! empty($this->token) || (! empty($this->username) && ! empty($this->password)));
    }

    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(max(1.0, min(2.0, $this->timeout)))
                ->get($this->getUrl().'/healthz');

            if ($response->successful() && ($response->json('status') === 'ok' || $response->status() === 200)) {
                $this->lastError = null;
                return true;
            }

            $this->lastError = 'Health check returned HTTP '.$response->status();
            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Ingest an array of structured log records into OpenObserve.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{success: bool, count: int, error: ?string}
     */
    public function ingest(array $records): array
    {
        if (empty($records)) {
            return ['success' => true, 'count' => 0, 'error' => null];
        }

        try {
            $url = sprintf('%s/api/%s/%s/_json', $this->getUrl(), $this->getOrg(), $this->getStream());
            $request = Http::timeout($this->timeout)->asJson();

            if (! empty($this->token)) {
                $request = $request->withToken($this->token);
            } elseif (! empty($this->username)) {
                $request = $request->withBasicAuth($this->username, (string) $this->password);
            }

            $response = $request->post($url, $records);

            if ($response->successful()) {
                $this->lastError = null;
                return [
                    'success' => true,
                    'count' => count($records),
                    'error' => null,
                ];
            }

            $errorMsg = $response->json('message') ?? 'HTTP '.$response->status();
            $this->lastError = $errorMsg;

            return [
                'success' => false,
                'count' => 0,
                'error' => $errorMsg,
            ];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();

            return [
                'success' => false,
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search OpenObserve using an SQL query.
     *
     * @return array{success: bool, hits: list<array<string, mixed>>, total: int, took_ms: int, error: ?string}
     */
    public function search(string $sql, ?int $startUs = null, ?int $endUs = null, int $from = 0, int $size = 50): array
    {
        $nowUs = (int) (microtime(true) * 1_000_000);
        $startUs ??= $nowUs - (24 * 3600 * 1_000_000);
        $endUs ??= $nowUs;

        try {
            $url = sprintf('%s/api/%s/_search', $this->getUrl(), $this->getOrg());
            $request = Http::timeout($this->timeout)->asJson();

            if (! empty($this->token)) {
                $request = $request->withToken($this->token);
            } elseif (! empty($this->username)) {
                $request = $request->withBasicAuth($this->username, (string) $this->password);
            }

            $payload = [
                'query' => [
                    'sql' => $sql,
                    'start_time' => $startUs,
                    'end_time' => $endUs,
                    'from' => $from,
                    'size' => $size,
                ],
            ];

            $response = $request->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $this->lastError = null;

                return [
                    'success' => true,
                    'hits' => (array) ($data['hits'] ?? []),
                    'total' => (int) ($data['total'] ?? count($data['hits'] ?? [])),
                    'took_ms' => (int) ($data['took'] ?? 0),
                    'error' => null,
                ];
            }

            $errorMsg = $response->json('message') ?? 'HTTP '.$response->status();
            $this->lastError = $errorMsg;

            return [
                'success' => false,
                'hits' => [],
                'total' => 0,
                'took_ms' => 0,
                'error' => $errorMsg,
            ];
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();

            return [
                'success' => false,
                'hits' => [],
                'total' => 0,
                'took_ms' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
}
