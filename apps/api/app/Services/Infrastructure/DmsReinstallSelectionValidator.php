<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use InvalidArgumentException;

/** Validates the editable DMS reinstall rows against the server's saved map. */
class DmsReinstallSelectionValidator
{
    /**
     * @param  list<array<string, mixed>>  $submittedRows
     * @return array{
     *     rows: list<array{main: bool, ip: string, type: string, domain_id: string, sub: string, domain: string, is_our: bool}>,
     *     domains: list<SendingDomain>,
     *     main_domain: SendingDomain
     * }
     */
    public function prepare(Workspace $workspace, ManagedServer $server, array $submittedRows): array
    {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || ! $server->is_active
            || ! $server->is_installed) {
            throw new InvalidArgumentException('Only active installed servers in this workspace can be reinstalled.');
        }

        if ($submittedRows === [] || count($submittedRows) > 128) {
            throw new InvalidArgumentException('Reinstall requires between 1 and 128 saved IP rows.');
        }

        $savedRows = $server->install_parameters['data'] ?? null;
        if (! is_array($savedRows)) {
            throw new InvalidArgumentException('The saved reinstall configuration has no valid IP assignments.');
        }

        $savedByIp = [];
        $domainIds = [];
        foreach ($savedRows as $savedRow) {
            if (! is_array($savedRow)
                || ! is_string($savedRow['ip'] ?? null)
                || ! is_string($savedRow['type'] ?? null)
                || ! (is_string($savedRow['domain_id'] ?? null) || is_int($savedRow['domain_id'] ?? null))) {
                throw new InvalidArgumentException('The saved reinstall configuration contains an invalid IP row.');
            }

            $savedByIp[$savedRow['ip']] = $savedRow;
            $domainIds[(string) $savedRow['domain_id']] = (string) $savedRow['domain_id'];
        }

        if (count($savedByIp) !== count($savedRows)) {
            throw new InvalidArgumentException('The saved reinstall configuration contains duplicate IP addresses.');
        }

        $domains = SendingDomain::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', array_values($domainIds))
            ->get()
            ->keyBy(static fn (SendingDomain $domain): string => (string) $domain->getKey());

        if ($domains->count() !== count($domainIds)) {
            throw new InvalidArgumentException('The saved reinstall configuration references a domain outside this workspace.');
        }

        $seenIps = [];
        $rows = [];
        $mainCount = 0;
        $usedDomainIds = [];
        foreach ($submittedRows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('A reinstall IP row is invalid.');
            }

            $ip = $row['ip'] ?? null;
            $type = $row['type'] ?? null;
            $domainId = $row['domain_id'] ?? null;
            $sub = $row['sub'] ?? '';
            $main = filter_var($row['main'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (! is_string($ip)
                || ! is_string($type)
                || ! is_string($domainId)
                || ! is_string($sub)
                || $main === null
                || filter_var($ip, FILTER_VALIDATE_IP) === false
                || isset($seenIps[$ip])
                || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $sub) && $sub !== '') {
                throw new InvalidArgumentException('A reinstall IP, subdomain, or main-IP value is invalid.');
            }

            $saved = $savedByIp[$ip] ?? null;
            if (! is_array($saved)
                || $type !== $saved['type']
                || $domainId !== (string) $saved['domain_id']) {
                throw new InvalidArgumentException('Reinstall can only use the server IPs and domain assignments from its saved configuration.');
            }

            $expectedType = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6';
            if ($type !== $expectedType) {
                throw new InvalidArgumentException('The selected IP type does not match the saved IP address.');
            }

            $domain = $domains->get($domainId);
            if (! $domain instanceof SendingDomain) {
                throw new InvalidArgumentException('A reinstall row references an unavailable domain.');
            }

            $seenIps[$ip] = true;
            $mainCount += $main ? 1 : 0;
            $usedDomainIds[$domainId] = true;
            $rows[] = [
                'main' => $main,
                'ip' => $ip,
                'type' => $type,
                'domain_id' => $domainId,
                'sub' => $sub,
                'domain' => (string) $domain->name,
                'is_our' => (bool) $domain->is_our,
            ];
        }

        if ($mainCount > 1) {
            throw new InvalidArgumentException('Only one server IP can be marked MAIN.');
        }

        $mainRow = collect($rows)->firstWhere('main', true) ?? $rows[0];

        return [
            'rows' => $rows,
            'domains' => $domains->only(array_keys($usedDomainIds))->values()->all(),
            'main_domain' => $domains->get($mainRow['domain_id']),
        ];
    }
}
