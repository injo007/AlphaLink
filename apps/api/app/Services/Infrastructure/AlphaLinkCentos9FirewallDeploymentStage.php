<?php

namespace App\Services\Infrastructure;

/** Executes AlphaLink's CentOS 9 firewall command plan through an isolated transport. */
class AlphaLinkCentos9FirewallDeploymentStage
{
    public function __construct(private readonly AlphaLinkCentos9FirewallPlanner $planner) {}

    /**
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $rdpIps
     * @param  list<string>  $pmtaRelayIps
     */
    public function run(
        AlphaLinkInstallerRemoteCommandTransport $commands,
        bool $applyFirewall,
        int $serverSshPort,
        int $pmtaPort,
        int $pmtaAdminPort,
        int $proxyPort,
        array $developmentIps,
        array $productionIps,
        array $rdpIps,
        bool $allowAllMonitor = false,
        array $pmtaRelayIps = [],
    ): void {
        foreach ($this->planner->plan(
            $applyFirewall,
            $serverSshPort,
            $pmtaPort,
            $pmtaAdminPort,
            $proxyPort,
            $developmentIps,
            $productionIps,
            $rdpIps,
            $allowAllMonitor,
            $pmtaRelayIps,
        ) as $operation) {
            // AlphaLink submits each command and does not stop the phase on a false
            // result, so this stage intentionally attempts the full list.
            $commands->exec($operation['command']);
        }
    }
}
