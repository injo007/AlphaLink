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

    /** @return array{id: string, name: string} */
    public function zoneForManagedHostname(DomainProvider $provider, string $hostname, ?string $savedZoneId = null): array
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new CloudflareDnsApiException('The selected sending hostname is invalid.');
        }

        $matching = array_values(array_filter($this->listZones($provider), static function (array $zone) use ($hostname): bool {
            $name = strtolower(rtrim((string) ($zone['name'] ?? ''), '.'));

            return $name !== '' && ($hostname === $name || str_ends_with($hostname, '.'.$name));
        }));
        usort($matching, static fn (array $left, array $right): int => strlen((string) ($right['name'] ?? '')) <=> strlen((string) ($left['name'] ?? '')));
        $zone = $matching[0] ?? null;
        if (! is_array($zone) || (isset($matching[1]) && strcasecmp((string) $matching[1]['name'], (string) $zone['name']) === 0)) {
            throw new CloudflareDnsApiException('The selected sending hostname does not have one matching Cloudflare zone.');
        }

        $zoneId = (string) ($zone['id'] ?? '');
        $this->assertZoneId($zoneId);
        if (filled($savedZoneId) && $savedZoneId !== $zoneId) {
            throw new CloudflareDnsApiException('The selected sending hostname Cloudflare zone does not match its saved zone.');
        }

        return ['id' => $zoneId, 'name' => strtolower(rtrim((string) $zone['name'], '.'))];
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
     * Purges all user-managed DNS records from a Cloudflare zone, skipping apex NS and SOA records.
     */
    public function purgeZoneDnsRecords(DomainProvider $provider, string $zoneId, string $zoneName): int
    {
        $this->assertZoneId($zoneId);
        $normalizedZone = strtolower(rtrim($zoneName, '.'));
        $current = $this->listDnsRecords($provider, $zoneId);
        $deletes = [];

        foreach ($current as $item) {
            $type = strtoupper((string) ($item['type'] ?? ''));
            $name = strtolower(rtrim((string) ($item['name'] ?? ''), '.'));

            if ($type === 'SOA') {
                continue;
            }
            if ($type === 'NS' && ($name === $normalizedZone || $name === '@' || $name === '')) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            if (preg_match('/\A[a-f0-9]{32}\z/i', $id) === 1) {
                $deletes[] = ['id' => $id];
            }
        }

        if ($deletes === []) {
            return 0;
        }

        foreach (array_chunk($deletes, 100) as $deleteBatch) {
            try {
                $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records/batch', [
                    'deletes' => $deleteBatch,
                ]));
            } catch (\Throwable) {
                foreach ($deleteBatch as $entry) {
                    try {
                        $this->deleteDnsRecord($provider, $zoneId, $entry['id']);
                    } catch (\Throwable) {
                        // ignore if already deleted
                    }
                }
            }
        }

        return count($deletes);
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
                'content' => $type === 'TXT'
                    ? $this->cloudflareTxtContent((string) $record['address'])
                    : $record['address'],
                'ttl' => max(1, (int) $record['ttl']),
            ];
            if (in_array($type, ['A', 'AAAA'], true)) {
                // PMTA's monitor uses a configurable port that Cloudflare's
                // HTTP proxy does not support. Installer addresses must resolve
                // directly to the server.
                $body['proxied'] = false;
            }
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
        $deletes = [];
        foreach ($current as $item) {
            $type = strtoupper((string) ($item['type'] ?? ''));
            $name = strtolower(rtrim((string) ($item['name'] ?? ''), '.'));
            $key = $name.'|'.$type;
            if (! isset($desiredGroups[$key])) {
                continue;
            }
            if ($type === 'TXT' && ! $this->isManagedEmailAuthTxt((string) ($item['content'] ?? ''))) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if (preg_match('/\A[a-f0-9]{32}\z/i', $id) === 1) {
                $deletes[] = ['id' => $id];
            }
        }

        $posts = [];
        foreach ($desiredGroups as $desiredBodies) {
            foreach ($desiredBodies as $body) {
                $posts[] = $body;
            }
        }

        foreach (array_chunk($deletes, 100) as $deleteBatch) {
            $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records/batch', [
                'deletes' => $deleteBatch,
            ]));
        }

        foreach (array_chunk($posts, 100) as $postBatch) {
            $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records/batch', [
                'posts' => $postBatch,
            ]));
        }

        $this->assertInstallerRecordsMatch($this->listDnsRecords($provider, $zoneId), $desiredGroups);

        return true;
    }

    /**
     * Add selected assignment records without deleting or replacing existing DNS data.
     * A different record in a selected name/type group is a conflict, not permission
     * to overwrite it. Retries accept already-present records.
     *
     * @param  list<array{host: string, address: string, type: string, ttl: string}>  $records
     */
    public function ensureAssignmentRecords(DomainProvider $provider, string $zoneId, string $zoneName, array $records): bool
    {
        $this->assertZoneId($zoneId);
        $zoneName = strtolower(rtrim(trim($zoneName), '.'));
        $desired = [];
        foreach ($records as $record) {
            $type = strtoupper($record['type']);
            if (! in_array($type, ['A', 'AAAA', 'MX', 'TXT'], true)) {
                throw new CloudflareDnsApiException('An assignment DNS record has an unsupported type.');
            }
            $host = strtolower(rtrim(trim($record['host']), '.'));
            $name = $host === '@'
                ? $zoneName
                : (($host === $zoneName || str_ends_with($host, '.'.$zoneName)) ? $host : $host.'.'.$zoneName);
            if (! $this->isValidAssignmentRecordName($name)) {
                throw new CloudflareDnsApiException('An assignment DNS hostname is invalid.');
            }
            $content = $type === 'TXT'
                ? $this->normalizeCloudflareTxtContent($record['address'])
                : $record['address'];
            $body = [
                'type' => $type,
                'name' => $name,
                'content' => $type === 'TXT' ? $this->cloudflareTxtContent($content) : $content,
                'ttl' => max(1, (int) $record['ttl']),
            ];
            if (in_array($type, ['A', 'AAAA'], true)) {
                $body['proxied'] = false;
            }
            if ($type === 'MX') {
                $body['priority'] = 10;
            }
            $desired[$name.'|'.$type][$content] = $body;
        }
        if ($desired === []) {
            return true;
        }

        $current = $this->listDnsRecords($provider, $zoneId);
        $toCreate = [];
        $toUpdate = [];
        $expected = $desired;
        foreach ($desired as $key => $contents) {
            [$name, $type] = explode('|', $key, 2);
            $present = [];
            $existingSpf = [];
            $existingDmarc = [];
            foreach ($current as $existing) {
                $existingName = strtolower(rtrim((string) ($existing['name'] ?? ''), '.'));
                if ($existingName !== $name) {
                    continue;
                }
                $existingType = strtoupper((string) ($existing['type'] ?? ''));
                if ($existingType === 'CNAME') {
                    throw new CloudflareDnsApiException('Assignment DNS record '.$type.' '.$name.' conflicts with an existing CNAME. Existing DNS was preserved; remove the CNAME or choose another sending hostname, then retry.');
                }
                if ($existingType !== $type) {
                    continue;
                }
                $existingContent = (string) ($existing['content'] ?? '');
                if ($type === 'TXT' && ! $this->isManagedEmailAuthTxt($existingContent)) {
                    continue;
                }
                if ($type === 'TXT') {
                    $existingContent = $this->normalizeCloudflareTxtContent($existingContent);
                }
                if ($type === 'TXT' && preg_match('/\Av=spf1(?:\s|$)/i', trim($existingContent)) === 1) {
                    $existingSpf[] = $existing;

                    continue;
                }
                if ($type === 'TXT' && preg_match('/\Av=dmarc1(?:\s*;|$)/i', trim($existingContent)) === 1) {
                    $existingDmarc[] = $existing;

                    continue;
                }
                if (! isset($contents[$existingContent])) {
                    throw new CloudflareDnsApiException('Assignment DNS record '.$type.' '.$name.' conflicts with an existing value. Existing DNS was preserved; point this record to the selected server or choose another hostname, then retry.');
                }
                if (in_array($type, ['A', 'AAAA'], true) && (bool) ($existing['proxied'] ?? false)) {
                    throw new CloudflareDnsApiException('Assignment DNS address '.$type.' '.$name.' is proxied and cannot serve the selected mail or tracking host directly.');
                }
                if ($type === 'MX' && (int) ($existing['priority'] ?? 10) !== 10) {
                    throw new CloudflareDnsApiException('An assignment MX record has a conflicting priority.');
                }
                $present[$existingContent] = true;
            }

            if ($type === 'TXT' && array_filter(array_keys($contents), static fn (string $content): bool => preg_match('/\Av=spf1(?:\s|$)/i', trim($content)) === 1) !== []) {
                if (count($existingSpf) > 1) {
                    throw new CloudflareDnsApiException('The selected DNS hostname already has multiple SPF records; consolidate them before installing.');
                }
                if ($existingSpf !== []) {
                    $record = $existingSpf[0];
                    $recordId = (string) ($record['id'] ?? '');
                    if (preg_match('/\A[a-f0-9]{32}\z/i', $recordId) !== 1) {
                        throw new CloudflareDnsApiException('The existing SPF record cannot be safely updated through Cloudflare.');
                    }
                    $existingContent = $this->normalizeCloudflareTxtContent((string) $record['content']);
                    $mergedSpf = $this->mergeAssignmentSpf($existingContent, array_keys($contents));
                    $expected[$key] = [$mergedSpf => $contents[array_key_first($contents)]];
                    if ($mergedSpf !== $existingContent) {
                        $toUpdate[] = [
                            'id' => $recordId,
                            'body' => [
                                'type' => 'TXT',
                                'name' => $name,
                                'content' => $this->cloudflareTxtContent($mergedSpf),
                                'ttl' => max(1, (int) ($record['ttl'] ?? 1)),
                            ],
                        ];
                    }

                    continue;
                }
            }

            if ($type === 'TXT' && array_filter(array_keys($contents), static fn (string $content): bool => preg_match('/\Av=dmarc1(?:\s*;|$)/i', trim($content)) === 1) !== []) {
                if (count($existingDmarc) > 1) {
                    throw new CloudflareDnsApiException('The selected DNS hostname already has multiple DMARC records; consolidate them before installing.');
                }
                if ($existingDmarc !== []) {
                    $existingContent = $this->normalizeCloudflareTxtContent((string) $existingDmarc[0]['content']);
                    // Keep the owner's policy intact; the verifier only needs one valid policy.
                    $expected[$key] = [$existingContent => $contents[array_key_first($contents)]];

                    continue;
                }
            }

            foreach ($contents as $content => $body) {
                if (! isset($present[$content])) {
                    $toCreate[] = $body;
                }
            }
        }

        foreach ($toUpdate as $update) {
            $this->successfulObject($this->request($provider)->patch(
                'zones/'.$zoneId.'/dns_records/'.$update['id'],
                $update['body'],
            ));
        }

        foreach (array_chunk($toCreate, 100) as $chunk) {
            $this->successfulObject($this->request($provider)->post('zones/'.$zoneId.'/dns_records/batch', ['posts' => $chunk]));
        }

        $after = $this->listDnsRecords($provider, $zoneId);
        foreach ($expected as $key => $contents) {
            [$name, $type] = explode('|', $key, 2);
            $actual = [];
            foreach ($after as $existing) {
                if (strtolower(rtrim((string) ($existing['name'] ?? ''), '.')) !== $name) {
                    continue;
                }
                $existingType = strtoupper((string) ($existing['type'] ?? ''));
                if ($existingType === 'CNAME') {
                    throw new CloudflareDnsApiException('Assignment DNS record '.$type.' '.$name.' conflicts with an existing CNAME. Existing DNS was preserved; remove the CNAME or choose another sending hostname, then retry.');
                }
                if ($existingType === $type) {
                    $content = (string) ($existing['content'] ?? '');
                    if ($type === 'TXT' && ! $this->isManagedEmailAuthTxt($content)) {
                        continue;
                    }
                    if ($type === 'TXT') {
                        $content = $this->normalizeCloudflareTxtContent($content);
                    }
                    if (! isset($contents[$content])) {
                        throw new CloudflareDnsApiException('Assignment DNS record '.$type.' '.$name.' conflicts with an existing value. Existing DNS was preserved; point this record to the selected server or choose another hostname, then retry.');
                    }
                    if (in_array($type, ['A', 'AAAA'], true) && (bool) ($existing['proxied'] ?? false)) {
                        throw new CloudflareDnsApiException('An assignment DNS address is proxied and cannot serve the selected mail or tracking host directly.');
                    }
                    if ($type === 'MX' && (int) ($existing['priority'] ?? 10) !== 10) {
                        throw new CloudflareDnsApiException('An assignment MX record has a conflicting priority.');
                    }
                    $actual[$content] = true;
                }
            }
            foreach ($contents as $content => $body) {
                if (! isset($actual[$content])) {
                    throw new CloudflareDnsApiException('Cloudflare did not publish a selected assignment DNS record.');
                }
            }
        }

        return true;
    }

    /**
     * Add only the installer's IP mechanisms to one existing SPF policy. Existing
     * includes, qualifiers, redirects and all-policy mechanisms remain untouched.
     *
     * @param  list<string>  $requestedRecords
     */
    private function mergeAssignmentSpf(string $existing, array $requestedRecords): string
    {
        $tokens = preg_split('/\s+/', trim($existing)) ?: [];
        if ($tokens === [] || strcasecmp($tokens[0], 'v=spf1') !== 0) {
            throw new CloudflareDnsApiException('The existing SPF record is invalid and cannot be safely extended.');
        }

        $requestedMechanisms = [];
        foreach ($requestedRecords as $requested) {
            foreach (array_slice(preg_split('/\s+/', trim($requested)) ?: [], 1) as $mechanism) {
                if (preg_match('/\A[+?~-]?(?:ip4|ip6):[^\s]+\z/i', $mechanism) === 1) {
                    $requestedMechanisms[strtolower($mechanism)] = $mechanism;
                }
            }
        }
        $existingTokens = array_fill_keys(array_map('strtolower', $tokens), true);
        $missing = array_values(array_filter($requestedMechanisms, static fn (string $mechanism): bool => ! isset($existingTokens[strtolower($mechanism)])));
        if ($missing === []) {
            return $existing;
        }

        $insertAt = count($tokens);
        foreach (array_slice($tokens, 1) as $offset => $token) {
            if (preg_match('/\A[+?~-]?all\z/i', $token) === 1 || str_contains($token, '=')) {
                $insertAt = $offset + 1;
                break;
            }
        }
        array_splice($tokens, $insertAt, 0, $missing);

        return implode(' ', $tokens);
    }

    private function normalizeCloudflareTxtContent(string $content): string
    {
        $content = trim($content);
        if (! str_contains($content, '"')) {
            return $content;
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches);
        if (($matches[1] ?? []) === []) {
            return $content;
        }

        return implode('', array_map(static fn (string $part): string => stripcslashes($part), $matches[1]));
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
                if ($type === 'TXT' && ! $this->isManagedEmailAuthTxt((string) ($item['content'] ?? ''))) {
                    continue;
                }

                $body = [
                    'type' => $type,
                    'name' => $name,
                    'content' => (string) ($item['content'] ?? ''),
                    'ttl' => (int) ($item['ttl'] ?? 0),
                ];
                if (in_array($type, ['A', 'AAAA'], true)) {
                    $body['proxied'] = (bool) ($item['proxied'] ?? false);
                }
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

    private function cloudflareTxtContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new CloudflareDnsApiException('Cloudflare TXT records cannot be empty.');
        }
        if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
            $content = preg_replace('/"\s*"/', '', substr($content, 1, -1)) ?? $content;
            $content = trim($content);
        }

        $chunks = [];
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $chunk = function_exists('mb_strcut')
                ? mb_strcut($content, $offset, 255, 'UTF-8')
                : substr($content, $offset, 255);
            if ($chunk === '') {
                throw new CloudflareDnsApiException('The Cloudflare TXT record could not be split into valid character strings.');
            }
            $chunks[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $chunk).'"';
            $offset += strlen($chunk);
        }

        return implode(' ', $chunks);
    }

    private function isManagedEmailAuthTxt(string $content): bool
    {
        $content = trim(str_replace('"', '', $content));

        return preg_match('/\Av=(?:spf1|dkim1|dmarc1)(?:\s|;)/i', $content) === 1;
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

    private function isValidAssignmentRecordName(string $name): bool
    {
        if ($name === '' || strlen($name) > 253) {
            return false;
        }

        foreach (explode('.', $name) as $label) {
            if (strlen($label) < 1 || strlen($label) > 63
                || preg_match('/\A_?[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/D', $label) !== 1) {
                return false;
            }
        }

        return true;
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
