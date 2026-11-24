<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolves one eligible bulk-install row and performs only the installer's read-only IP-discovery phase.
 */
class AlphaLinkBulkInstallRowPreparation
{
    public function __construct(
        private readonly AlphaLinkBulkInstallEligibility $eligibility,
        private readonly AlphaLinkBulkInstallRemoteIpDiscovery $ipDiscovery,
        private readonly AlphaLinkBulkInstallIspCatalog $ispCatalog,
        private readonly AlphaLinkInstallerServerCredentialResolver $credentialResolver,
    ) {}

    /**
     * @param  list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>|null  $ipAssignments
     * @return array{workspace: Workspace, server: ManagedServer, domain: SendingDomain, domains?: list<SendingDomain>, ips: list<string>, isps: list<string>, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string, domain: string, is_our: bool}>}
     *
     * @throws HostProbeException
     * @throws ModelNotFoundException
     */
    public function prepare(
        User $user,
        Workspace $workspace,
        string $serverId,
        string $domainId,
        ?array $ipAssignments = null,
    ): array {
        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            throw (new ModelNotFoundException)->setModel(ManagedServer::class, [$serverId]);
        }

        $server = $ipAssignments === null
            ? $this->eligibility->serverForSelection($user, $workspace, $serverId)
            : $this->eligibility->singleInstallServerForSelection($workspace, $serverId);
        $domain = $ipAssignments === null
            ? $this->eligibility->domainForSelection($workspace, $domainId)
            : $this->eligibility->singleInstallDomainForSelection($workspace, $domainId);

        if ($server === null) {
            throw (new ModelNotFoundException)->setModel(ManagedServer::class, [$serverId]);
        }

        if ($domain === null) {
            throw (new ModelNotFoundException)->setModel(SendingDomain::class, [$domainId]);
        }

        if ($server->state !== 'verified' || ! is_string($server->expected_host_fingerprint) || $server->expected_host_fingerprint === '') {
            throw new HostProbeException('Verify the server host before installation.');
        }

        $domainById = $ipAssignments !== null
            ? $this->resolveSelectedDomains($workspace, $ipAssignments)
            : [];
        if ($ipAssignments !== null) {
            $primaryAssignment = collect($ipAssignments)->firstWhere('main', true) ?? $ipAssignments[0];
            if ((string) $primaryAssignment['domain_id'] !== (string) $domain->getKey()) {
                throw new HostProbeException('The selected primary domain does not match the main IP assignment.');
            }
        }

        $credential = $this->credentialResolver->resolve($server);
        $ips = $this->ipDiscovery->discover($server, $credential['secret'], $credential['type']);

        if ($ipAssignments !== null) {
            $preparedAssignments = $this->prepareIpAssignments($workspace, $ips, $ipAssignments);

            foreach ($preparedAssignments as &$assignment) {
                $assignmentDomain = $domainById[$assignment['domain_id']];
                $assignment['domain'] = (string) $assignmentDomain->name;
                $assignment['is_our'] = (bool) $assignmentDomain->is_our;
                if (isset($assignment['domains'])) {
                    foreach ($assignment['domains'] as &$additionalDomain) {
                        $selectedDomain = $domainById[$additionalDomain['domain_id']];
                        $additionalDomain['domain'] = (string) $selectedDomain->name;
                        $additionalDomain['is_our'] = (bool) $selectedDomain->is_our;
                    }
                    unset($additionalDomain);
                }
            }
            unset($assignment);

            $primaryAssignment = collect($preparedAssignments)->firstWhere('main', true) ?? $preparedAssignments[0];
            $primaryDomain = $domainById[$primaryAssignment['domain_id']];

            return [
                'workspace' => $workspace,
                'server' => $server,
                'domain' => $primaryDomain,
                'domains' => array_values($domainById),
                'ips' => $ips,
                'isps' => $this->ispCatalog->vmtaNames($workspace),
                'ip_assignments' => $preparedAssignments,
            ];
        }

        return [
            'workspace' => $workspace,
            'server' => $server,
            'domain' => $domain,
            'ips' => $ips,
            'isps' => $this->ispCatalog->vmtaNames($workspace),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return array<string, SendingDomain>
     */
    private function resolveSelectedDomains(Workspace $workspace, array $assignments): array
    {
        if ($assignments === []) {
            throw new HostProbeException('Load and review at least one server IP before installation.');
        }

        $domainsById = [];
        $seenIps = [];
        $mainCount = 0;
        foreach ($assignments as $assignment) {
            if (! is_array($assignment)
                || ! is_string($assignment['ip'] ?? null)
                || filter_var($assignment['ip'], FILTER_VALIDATE_IP) === false
                || ! in_array($assignment['type'] ?? null, ['ipv4', 'ipv6'], true)
                || ! is_bool($assignment['main'] ?? null)
                || ! is_string($assignment['sub'] ?? null)
                || ! is_string($assignment['domain_id'] ?? null)
                || ! is_array($assignment['domains'] ?? [])) {
                throw new HostProbeException('The IP domain assignments are invalid.');
            }
            if (isset($seenIps[$assignment['ip']])) {
                throw new HostProbeException('Each physical IP may be assigned only once.');
            }
            $expectedType = filter_var($assignment['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6';
            if ($assignment['type'] !== $expectedType) {
                throw new HostProbeException('The IP assignment type does not match its address.');
            }
            $seenIps[$assignment['ip']] = true;
            $mainCount += $assignment['main'] ? 1 : 0;
            $hostnames = [];
            foreach (array_merge([[
                'domain_id' => $assignment['domain_id'],
                'sub' => $assignment['sub'],
            ]], $assignment['domains'] ?? []) as $identity) {
                if (! is_array($identity)
                    || ! is_string($identity['domain_id'] ?? null)
                    || ! is_string($identity['sub'] ?? null)
                    || ($identity['sub'] !== '' && ! preg_match('/^(?=.{1,63}$)(?!-)[A-Za-z0-9-]+(?<!-)$/', $identity['sub']))) {
                    throw new HostProbeException('Subdomains must be valid DNS labels.');
                }
                $selectedDomain = $domainsById[$identity['domain_id']]
                    ?? $this->eligibility->singleInstallDomainForSelection($workspace, $identity['domain_id']);
                if (! $selectedDomain instanceof SendingDomain) {
                    throw new HostProbeException('One of the selected domains is no longer eligible for installation.');
                }
                $domainsById[$identity['domain_id']] = $selectedDomain;
                $hostname = strtolower(($identity['sub'] !== '' ? $identity['sub'].'.' : '').$selectedDomain->name);
                if (isset($hostnames[$hostname])) {
                    throw new HostProbeException('A sending hostname may be assigned only once to each physical IP.');
                }
                $hostnames[$hostname] = true;
            }
        }
        if ($mainCount > 1) {
            throw new HostProbeException('Select only one main IP address.');
        }

        return $domainsById;
    }

    /**
     * @param  list<string>  $discoveredIps
     * @param  list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>  $assignments
     * @return list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>
     *
     * @throws HostProbeException
     */
    private function prepareIpAssignments(Workspace $workspace, array $discoveredIps, array $assignments): array
    {
        if ($assignments === []) {
            throw new HostProbeException('Load and review at least one server IP before installation.');
        }

        if (count(array_filter($assignments, static fn (array $assignment): bool => $assignment['main'])) > 1) {
            throw new HostProbeException('Select only one main IP address.');
        }

        $seenIps = [];
        foreach ($assignments as $assignment) {
            $ip = $assignment['ip'];
            $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $expectedType = $isIpv4 ? 'ipv4' : 'ipv6';
            if (! in_array($ip, $discoveredIps, true) || isset($seenIps[$ip]) || $assignment['type'] !== $expectedType) {
                throw new HostProbeException('The IP assignments no longer match the host addresses. Load IPs again before installation.');
            }
            if ($assignment['sub'] !== ''
                && ! preg_match('/^(?=.{1,63}$)(?!-)[A-Za-z0-9-]+(?<!-)$/', $assignment['sub'])) {
                throw new HostProbeException('Subdomains must be valid DNS labels.');
            }

            $domain = $this->eligibility->singleInstallDomainForSelection($workspace, $assignment['domain_id']);
            if ($domain === null) {
                throw new HostProbeException('One of the selected domains is no longer eligible for installation.');
            }

            $seenIps[$ip] = true;
        }

        return $assignments;
    }
}
