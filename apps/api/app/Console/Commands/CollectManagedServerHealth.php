<?php

namespace App\Console\Commands;

use App\Models\ManagedServer;
use App\Services\Infrastructure\ServerCountryResolver;
use App\Services\Infrastructure\TcpPortProbe;
use Illuminate\Console\Command;

class CollectManagedServerHealth extends Command
{
    protected $signature = 'servers:collect-health {serverIds?* : Optional server UUIDs to check}';

    protected $description = 'Record TCP reachability history for active, installed servers';

    public function handle(TcpPortProbe $probe, ServerCountryResolver $countryResolver): int
    {
        $serverIds = collect((array) $this->argument('serverIds'))->filter()->values();
        $timeout = max(1, (int) config('infrastructure.probe_timeout_seconds', 10));
        $defaultPmtaPort = (int) config('infrastructure.pmta_port', 1212);

        ManagedServer::query()
            ->with(['workspace.appSettings', 'workspace.infrastructureSettings'])
            ->where('is_active', true)
            ->where('is_installed', true)
            ->when($serverIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $serverIds))
            ->orderBy('id')
            ->chunkById(100, function ($servers) use ($probe, $countryResolver, $timeout, $defaultPmtaPort): void {
                foreach ($servers as $server) {
                    if (blank($server->country)) {
                        $workspaceSettings = $server->workspace?->appSettings?->settings ?? [];
                        $workspaceApiKey = is_array($workspaceSettings) && array_key_exists('ip_api_key', $workspaceSettings)
                            ? (string) $workspaceSettings['ip_api_key']
                            : null;
                        $countryCode = $countryResolver->countryCode($server->ip_address, $workspaceApiKey);
                        if ($countryCode !== null) {
                            $server->country = $countryCode;
                            $server->save();
                        }
                    }

                    $configuredPmtaPort = $server->workspace?->infrastructureSettings?->pmta_port;
                    $pmtaPort = is_numeric($configuredPmtaPort) && (int) $configuredPmtaPort >= 1 && (int) $configuredPmtaPort <= 65535
                        ? (int) $configuredPmtaPort
                        : $defaultPmtaPort;

                    $reachable = $probe->checkMany(
                        $server->ip_address,
                        [(int) $server->ssh_port, 80, $pmtaPort],
                        $timeout,
                    );
                    $sshReachable = $reachable[(int) $server->ssh_port] ?? false;
                    $apacheReachable = $reachable[80] ?? false;
                    $pmtaReachable = $reachable[$pmtaPort] ?? false;

                    $server->histories()->create([
                        'workspace_id' => $server->workspace_id,
                        'is_ssh_down' => ! $sshReachable,
                        'is_apache_down' => ! $apacheReachable,
                        'is_pmta_down' => ! $pmtaReachable,
                    ]);

                    $this->line(sprintf(
                        'SERVER ID: %s SSH: %s - Apache: %s - PMTA: %s',
                        $server->getKey(),
                        $sshReachable ? 'UP' : 'DOWN',
                        $apacheReachable ? 'UP' : 'DOWN',
                        $pmtaReachable ? 'UP' : 'DOWN',
                    ));
                }
            });

        return self::SUCCESS;
    }
}
