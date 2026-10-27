<?php

namespace App\Services\Infrastructure;

use App\Models\DomainProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cloudflare zone and DNS Record API calls used by domain management and install workflows. */
class CloudflareDnsApi
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4/';

    private const ZONE_PAGE_SIZE = 50;

    private const RECORD_PAGE_SIZE = 100;

    public function testReadAccess(DomainProvider $provider): void
    {
        $verification = $this->request($provider)->get('user/tokens/verify');
        $verificationBody = $verification->json();
        if (! $verification->successful()
            || ! is_array($verificationBody)
            || ($verificationBody['success'] ?? false) !== true
            || data_get($verificationBody, 'result.status') !== 'active') {
            throw new CloudflareDnsApiException('Cloudflare did not verify an active API token. Check the token and try again.');
        }

        $this->successfulResult($this->request($provider)->get('zones', ['page' => 1, 'per_page' => 1]));
    }

    /** @return list<array<string, mixed>> */
    public function listZones(DomainProvider $provider): array
    {
        $zones = [];
        $page = 1;

        do {
            $response = $this->request($provider)->get('zones', [
                'page' => $page,
                'per_page' => self::ZONE_PAGE_SIZE,
                'order' => 'name',
                'direction' => 'asc',
            ]);
            $resultInfo = $response->json('result_info', []);
            $result = $this->successfulResult($response);
            $zones = array_merge($zones, $result);

            $totalPages = (int) (is_array($resultInfo) ? ($resultInfo['total_pages'] ?? 0) : 0);
            $hasNextPage = $totalPages > 0
                ? $page < $totalPages
                : count($result) === self::ZONE_PAGE_SIZE;
            $page++;

            if ($page > 10001) {
                throw new CloudflareDnsApiException('Cloudflare returned too many pages to import safely.');
            }
        } while ($hasNextPage);

        return $zones;
    }

    public function zoneIdForExactDomain(DomainProvider $provider, string $domainName, ?string $savedZoneId = null): string
    {
        $matching = array_values(array_filter($this->listZones($provider), static fn (array $zone): bool => strcasecmp((string) ($zone['name'] ?? ''), $domainName) === 0));
        if (count($matching) !== 1) {
            throw new CloudflareDnsApiException('The selected domain does not have exactly one matching Cloudflare zone.');
        }

        $zoneId = (string) ($matching[0]['id'] ?? '');
        $this->assertZoneId($zoneId);
        if (filled($savedZoneId) && $savedZoneId !== $zoneId) {
            throw new CloudflareDnsApiException('The selected domain Cloudflare zone does not match its saved zone.');
        }

        return $zoneId;
    }

    /** @return list<array<string, mixed>> */
    public function listDnsRecords(DomainProvider $provider, string $zoneId): array
    {
        $this->assertZoneId($zoneId);
        $records = [];
        $page = 1;

        do {
            $response = $this->request($provider)->get(
                'zones/'.$zoneId.'/dns_records',
                ['page' => $page, 'per_page' => self::RECORD_PAGE_SIZE],
            );
            $resultInfo = $response->json('result_info', []);
            $result = $this->successfulResult($response);
            $records = array_merge($records, $result);

            $totalPages = (int) (is_array($resultInfo) ? ($resultInfo['total_pages'] ?? 0) : 0);
            $hasNextPage = $totalPages > 0
                ? $page < $totalPages
                : count($result) === self::RECORD_PAGE_SIZE;
            $page++;

            if ($page > 10001) {
                throw new CloudflareDnsApiException('Cloudflare returned too many DNS record pages to load safely.');
            }
        } while ($hasNextPage);

        return $records;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createDnsRecord(DomainProvider $provider, string $zoneId, array $record): array
    {
        $this->assertZoneId($zoneId);

        return $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records', $record));
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function updateDnsRecord(DomainProvider $provider, string $zoneId, string $recordId, array $record): array
    {
        $this->assertRecordId($recordId);
        $this->assertZoneId($zoneId);

        return $this->successfulObject($this->request($provider)->patch(
            'zones/'.$zoneId.'/dns_records/'.$recordId,
            $record,
        ));
    }

    public function deleteDnsRecord(DomainProvider $provider, string $zoneId, string $recordId): void
    {
        $this->assertRecordId($recordId);
        $this->assertZoneId($zoneId);
        $this->successfulObject($this->request($provider)->delete('zones/'.$zoneId.'/dns_records/'.$recordId));
    }

    /**
     * Reconciles only the names and types owned by the installer, retaining every unrelated record.
     * Cloudflare's batch route validates the change set together before applying its operations.
     *
     * @param  list<array{host: string, address: string, type: string, ttl: string}>  $records
     */
    public function synchronizeInstallerRecords(
        DomainProvider $provider,
        string $zoneId,
        string $zoneName,
        array $records,
    ): bool {
        $this->assertZoneId($zoneId);

        if ($records === []) {
            return true;
        }

        $desiredGroups = [];
        foreach ($records as $record) {
            $type = strtoupper($record['type']);
            if (! in_array($type, ['A', 'AAAA', 'MX', 'TXT'], true)) {
                continue;
            }

            $host = strtolower(rtrim(trim($record['host']), '.'));
            $normalizedZone = strtolower(rtrim($zoneName, '.'));
            $recordName = $host === '@'
                ? $normalizedZone
                : (($host === $normalizedZone || str_ends_with($host, '.'.$normalizedZone))
                    ? $host
                    : $host.'.'.$normalizedZone);
            $body = [
                'type' => $type,
                'name' => $recordName,
                'content' => $record['address'],
                'ttl' => max(1, (int) $record['ttl']),
            ];
            if ($type === 'MX') {
                $body['priority'] = 10;
            }

            $key = strtolower($recordName).'|'.$type;
            $desiredGroups[$key][json_encode($body, JSON_THROW_ON_ERROR)] = $body;
        }

        if ($desiredGroups === []) {
            return true;
        }

        $current = $this->listDnsRecords($provider, $zoneId);
        $patches = [];
        $posts = [];
        $deletes = [];

        foreach ($desiredGroups as $key => $desiredBodies) {
            $matching = array_values(array_filter($current, static function (array $item) use ($key): bool {
                $type = strtoupper((string) ($item['type'] ?? ''));
                $name = strtolower(rtrim((string) ($item['name'] ?? ''), '.'));

                return $type !== '' && $name.'|'.$type === $key;
            }));
            $unusedIds = array_values(array_filter(array_map(
                static fn (array $item): string => (string) ($item['id'] ?? ''),
                $matching,
            ), static fn (string $id): bool => preg_match('/\A[a-f0-9]{32}\z/i', $id) === 1));

            foreach (array_values($desiredBodies) as $body) {
                $same = null;
                foreach ($matching as $index => $existing) {
                    if (! isset($existing['id']) || ! in_array((string) $existing['id'], $unusedIds, true)) {
                        continue;
                    }

                    if (($existing['content'] ?? null) === $body['content']
                        && (int) ($existing['ttl'] ?? 0) === $body['ttl']
                        && ($body['type'] !== 'MX' || (int) ($existing['priority'] ?? 10) === $body['priority'])) {
                        $same = (string) $existing['id'];
                        break;
                    }
                }

                if ($same !== null) {
                    $unusedIds = array_values(array_diff($unusedIds, [$same]));

                    continue;
                }

                $replaceId = array_shift($unusedIds);
                if ($replaceId !== null) {
                    $patches[] = ['id' => $replaceId, ...$body];
                } else {
                    $posts[] = $body;
                }
            }

            foreach ($unusedIds as $id) {
                $deletes[] = ['id' => $id];
            }
        }

        if ($patches === [] && $posts === [] && $deletes === []) {
            $this->assertInstallerRecordsMatch($current, $desiredGroups);

            return true;
        }

        $payload = array_filter([
            'deletes' => $deletes,
            'patches' => $patches,
            'posts' => $posts,
        ], static fn (array $operations): bool => $operations !== []);

        $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records/batch', $payload));
        $this->assertInstallerRecordsMatch($this->listDnsRecords($provider, $zoneId), $desiredGroups);

        return true;
    }

    /** @param list<array<string, mixed>> $current
     * @param  array<string, array<string, array<string, mixed>>>  $desiredGroups
     */
    private function assertInstallerRecordsMatch(array $current, array $desiredGroups): void
    {
        foreach ($desiredGroups as $key => $desiredBodies) {
            $actualSignatures = [];
            foreach ($current as $item) {
                $type = strtoupper((string) ($item['type'] ?? ''));
                $name = strtolower(rtrim((string) ($item['name'] ?? ''), '.'));
                if ($name.'|'.$type !== $key) {
                    continue;
                }

                $body = [
                    'type' => $type,
                    'name' => $name,
                    'content' => (string) ($item['content'] ?? ''),
                    'ttl' => (int) ($item['ttl'] ?? 0),
                ];
                if ($type === 'MX') {
                    $body['priority'] = (int) ($item['priority'] ?? 10);
                }
                $actualSignatures[] = json_encode($body, JSON_THROW_ON_ERROR);
            }

            $expectedSignatures = array_keys($desiredBodies);
            sort($actualSignatures);
            sort($expectedSignatures);
            if ($actualSignatures !== $expectedSignatures) {
                throw new CloudflareDnsApiException('Cloudflare accepted the DNS update but the selected zone does not contain the requested installer records.');
            }
        }
    }

    private function request(DomainProvider $provider): PendingRequest
    {
        if ($provider->type !== 'cloudflare' || blank($provider->api_key)) {
            throw new CloudflareDnsApiException('A Cloudflare API token is required for this action.');
        }

        return Http::baseUrl(self::API_BASE)
            ->acceptJson()
            ->withToken($provider->api_key)
            ->timeout(30);
    }

    /** @return list<array<string, mixed>> */
    private function successfulResult(Response $response): array
    {
        $body = $this->successfulBody($response);
        $result = $body['result'] ?? null;
        if (! is_array($result) || ! array_is_list($result)) {
            throw new CloudflareDnsApiException('Cloudflare returned an invalid list response.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function successfulObject(Response $response): array
    {
        $body = $this->successfulBody($response);
        $result = $body['result'] ?? null;
        if (! is_array($result)) {
            throw new CloudflareDnsApiException('Cloudflare returned an invalid record response.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function successfulBody(Response $response): array
    {
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            throw new CloudflareDnsApiException('Cloudflare did not complete the DNS request. Check the token permissions and selected zone.');
        }

        return $body;
    }

    private function assertZoneId(string $zoneId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/i', $zoneId) !== 1) {
            throw new CloudflareDnsApiException('The saved Cloudflare zone identifier is invalid. Import the zone again.');
        }
    }

    private function assertRecordId(string $recordId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/i', $recordId) !== 1) {
            throw new CloudflareDnsApiException('The Cloudflare DNS record identifier is invalid.');
        }
    }
}
