<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\Workspace;
use RuntimeException;

/** Loads the saved per-IP domain map used by DMS ServerChangeDomain. */
class DmsChangeDomainSelectionLoader
{
    /**
     * @return array{
     *     spf: bool,
     *     dkim: bool,
     *     dmarc: bool,
     *     data: list<array{ip_id: int, ip: string, type: string, old_domain_id: string, domain_id: string, sub: string, main: bool}>
     * }
     */
    public function load(Workspace $workspace, ManagedServer $server): array
    {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || ! $server->is_active || ! $server->is_installed) {
            throw new RuntimeException('Choose an active, installed server from this workspace.');
        }

        $parameters = is_array($server->install_parameters) ? $server->install_parameters : [];
        $savedRows = $parameters['data'] ?? [];
        if (! is_array($savedRows)) {
            throw new RuntimeException('The server installation domain map is not available.');
        }

        $savedIps = $server->ips()
            ->where('workspace_id', $workspace->getKey())
            ->get(['id', 'ip', 'type', 'sending_domain_id'])
            ->keyBy('ip');

        $data = [];
        foreach ($savedRows as $savedRow) {
            if (! is_array($savedRow) || ! isset($savedRow['ip']) || ! is_string($savedRow['ip'])) {
                throw new RuntimeException('The server installation domain map contains an invalid IP row.');
            }

            $savedIp = $savedIps->get($savedRow['ip']);
            if ($savedIp === null) {
                throw new RuntimeException('The saved IP-to-domain assignments are incomplete for this server.');
            }

            $type = $savedRow['type'] ?? $savedIp->type;
            if (! is_string($type) || ! is_string($savedRow['sub'] ?? '')) {
                throw new RuntimeException('The server installation domain map contains invalid IP settings.');
            }

            $data[] = [
                'ip_id' => (int) $savedIp->getKey(),
                'ip' => (string) $savedIp->ip,
                'type' => $type,
                'old_domain_id' => (string) $savedIp->sending_domain_id,
                'domain_id' => (string) $savedIp->sending_domain_id,
                'sub' => (string) ($savedRow['sub'] ?? ''),
                'main' => (bool) ($savedRow['main'] ?? false),
            ];
        }

        return [
            'spf' => (bool) ($parameters['spf'] ?? false),
            'dkim' => (bool) ($parameters['dkim'] ?? false),
            'dmarc' => (bool) ($parameters['dmarc'] ?? false),
            'data' => $data,
        ];
    }
}
