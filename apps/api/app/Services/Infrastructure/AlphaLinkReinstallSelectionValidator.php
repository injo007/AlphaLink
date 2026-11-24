<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\Workspace;
use InvalidArgumentException;

/** Validates the editable AlphaLink reinstall rows against the server's saved map. */
class AlphaLinkReinstallSelectionValidator
{
    public function __construct(private readonly AlphaLinkMultiDomainInstallPlan $multiDomainPlan) {}

    /**
     * @param  list<array<string, mixed>>  $submittedRows
     * @return array{
     *     rows: list<array<string, mixed>>,
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
        if (is_array($savedRows)) {
            $savedRows = array_values(array_filter($savedRows, static fn (mixed $row): bool => is_array($row)));
        }

        if (! is_array($savedRows) || $savedRows === []) {
            $savedRows = $server->ips()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->with(['domainAssignments' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary')->orderBy('id')])
                ->orderBy('id')
                ->get(['id', 'sending_domain_id', 'ip', 'type', 'dns'])
                ->map(static function (ManagedServerIp $ip): array {
                    $primary = $ip->domainAssignments->firstWhere('is_primary', true);
                    $primaryDomainId = $primary?->sending_domain_id ?? $ip->sending_domain_id;
                    $domainAssignments = $ip->domainAssignments
                        ->reject(static fn ($assignment): bool => (bool) $assignment->is_primary)
                        ->map(static fn ($assignment): array => [
                            'domain_id' => (string) $assignment->sending_domain_id,
                            'hostname' => (string) $assignment->hostname,
                        ])->values()->all();

                    return [
                        'id' => (int) $ip->getKey(),
                        'ip' => (string) $ip->ip,
                        'type' => (string) $ip->type,
                        'domain_id' => (string) $primaryDomainId,
                        'dns' => (string) $ip->dns,
                        'domains' => $domainAssignments,
                    ];
                })
                ->all();
        }

        if (! is_array($savedRows) || $savedRows === []) {
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
            if (isset($savedRow['domains'])) {
                if (! is_array($savedRow['domains']) || ! array_is_list($savedRow['domains'])) {
                    throw new InvalidArgumentException('The saved reinstall configuration contains invalid per-IP domain assignments.');
                }
                foreach ($savedRow['domains'] as $additional) {
                    if (! is_array($additional)
                        || (! is_string($additional['domain_id'] ?? null) && ! is_int($additional['domain_id'] ?? null))) {
                        throw new InvalidArgumentException('The saved reinstall configuration contains an invalid per-IP domain assignment.');
                    }
                    $domainIds[(string) $additional['domain_id']] = (string) $additional['domain_id'];
                }
            }
        }

        $savedDomainIdSet = array_fill_keys(array_keys($domainIds), true);
        $submittedAdditionalDomainIds = [];
        foreach ($submittedRows as $row) {
            if (! is_array($row) || ! array_key_exists('domains', $row)) {
                continue;
            }
            if (! is_array($row['domains']) || ! array_is_list($row['domains']) || count($row['domains']) > 32) {
                throw new InvalidArgumentException('A reinstall IP row contains invalid additional domain assignments.');
            }
            foreach ($row['domains'] as $additional) {
                if (! is_array($additional)
                    || (! is_string($additional['domain_id'] ?? null) && ! is_int($additional['domain_id'] ?? null))) {
                    throw new InvalidArgumentException('A reinstall IP row contains an invalid additional domain assignment.');
                }
                $additionalDomainId = (string) $additional['domain_id'];
                $submittedAdditionalDomainIds[$additionalDomainId] = $additionalDomainId;
                $domainIds[$additionalDomainId] = $additionalDomainId;
            }
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

        $newAdditionalDomainIds = array_values(array_diff(array_values($submittedAdditionalDomainIds), array_keys($savedDomainIdSet)));
        if ($newAdditionalDomainIds !== []) {
            $eligibleAdditionalDomainIds = SendingDomain::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('id', $newAdditionalDomainIds)
                ->where('is_active', true)
                ->whereIn('status', ['free', 'new'])
                ->whereHas('domainProvider', fn ($query) => $query
                    ->where('is_active', true)
                    ->where(fn ($providerQuery) => $providerQuery
                        ->whereNull('workspace_id')
                        ->orWhere('workspace_id', $workspace->getKey())))
                ->pluck('id')
                ->map(static fn (mixed $domainId): string => (string) $domainId)
                ->all();
            $eligibleAdditionalDomainIdSet = array_fill_keys($eligibleAdditionalDomainIds, true);

            foreach ($newAdditionalDomainIds as $domainId) {
                if (! isset($eligibleAdditionalDomainIdSet[$domainId])) {
                    throw new InvalidArgumentException('A new additional domain must be active, available, and have a working DNS provider in this workspace.');
                }
            }
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
            $additionalDomains = [];
            $additionalAssignments = array_key_exists('domains', $row)
                ? $row['domains']
                : ($saved['domains'] ?? []);
            if (! is_array($additionalAssignments) || ! array_is_list($additionalAssignments) || count($additionalAssignments) > 32) {
                throw new InvalidArgumentException('A reinstall IP row contains invalid additional domain assignments.');
            }
            foreach ($additionalAssignments as $additional) {
                if (! is_array($additional)
                    || (! is_string($additional['domain_id'] ?? null) && ! is_int($additional['domain_id'] ?? null))) {
                    throw new InvalidArgumentException('A reinstall IP row contains an invalid additional domain assignment.');
                }
                $additionalDomainId = (string) ($additional['domain_id'] ?? '');
                $additionalDomain = $domains->get($additionalDomainId);
                $additionalSub = $additional['sub'] ?? '';
                if ($additionalSub === null) {
                    $additionalSub = '';
                }
                if (! $additionalDomain instanceof SendingDomain
                    || ! is_string($additionalSub)
                    || ($additionalSub !== '' && ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $additionalSub))) {
                    throw new InvalidArgumentException('A saved per-IP domain assignment is invalid.');
                }
                if (isset($additional['hostname']) && is_string($additional['hostname'])) {
                    $hostname = strtolower(rtrim($additional['hostname'], '.'));
                    $baseDomain = strtolower(rtrim((string) $additionalDomain->name, '.'));
                    if ($hostname !== $baseDomain && str_ends_with($hostname, '.'.$baseDomain)) {
                        $additionalSub = substr($hostname, 0, -strlen('.'.$baseDomain));
                    }
                }
                $usedDomainIds[$additionalDomainId] = true;
                $additionalDomains[] = [
                    'domain_id' => $additionalDomainId,
                    'sub' => $additionalSub,
                    'domain' => (string) $additionalDomain->name,
                    'is_our' => (bool) $additionalDomain->is_our,
                ];
            }
            $rows[] = [
                'main' => $main,
                'ip' => $ip,
                'type' => $type,
                'domain_id' => $domainId,
                'sub' => $sub,
                'domain' => (string) $domain->name,
                'is_our' => (bool) $domain->is_our,
                'interface_ip' => $saved['interface_ip'] ?? null,
                'domains' => $additionalDomains,
            ];
        }

        if ($mainCount > 1) {
            throw new InvalidArgumentException('Only one server IP can be marked MAIN.');
        }

        $additionalCounts = array_values(array_unique(array_map(
            static fn (array $row): int => count($row['domains']),
            $rows,
        )));
        if (count($additionalCounts) > 1) {
            throw new InvalidArgumentException('Multi-Domain per IP requires the same number of additional domain assignments on every IP.');
        }
        if (($additionalCounts[0] ?? 0) > 0) {
            $this->multiDomainPlan->build($rows);
        }

        $mainRow = collect($rows)->firstWhere('main', true) ?? $rows[0];

        return [
            'rows' => $rows,
            'domains' => $domains->only(array_keys($usedDomainIds))->values()->all(),
            'main_domain' => $domains->get($mainRow['domain_id']),
        ];
    }
}
