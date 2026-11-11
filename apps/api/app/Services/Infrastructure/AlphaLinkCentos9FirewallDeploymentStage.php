<?php

namespace App\Services\Infrastructure;

/** Executes AlphaLink's CentOS 9 firewall command plan through an isolated transport. */
class AlphaLinkCentos9FirewallDeploymentStage
{
    public function __construct(private readonly AlphaLinkCentos9FirewallPlanner $planner) {}

    /** Opens only the web services needed for HTTP-01 certificate validation. */
    public function prepareDomainCertificatePorts(AlphaLinkInstallerRemoteCommandTransport $commands): bool
    {
        $result = $commands->exec(
            'if systemctl is-active --quiet firewalld; then '
                .'firewall-cmd --permanent --zone=public --add-service=http >/dev/null 2>&1 && '
                .'firewall-cmd --permanent --zone=public --add-service=https >/dev/null 2>&1 && '
                .'firewall-cmd --reload >/dev/null 2>&1 && '
                .'printf ALPHALINK_CERTIFICATE_PORTS_READY; '
                .'else printf ALPHALINK_CERTIFICATE_PORTS_READY; fi',
        );

        return trim((string) $result) === 'ALPHALINK_CERTIFICATE_PORTS_READY';
    }

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
