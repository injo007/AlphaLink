<?php

namespace App\Services\Infrastructure;

/** Emits DMS CentOS 9 firewall commands as data; it never connects to a host. */
class DmsCentos9FirewallPlanner
{
    /**
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $rdpIps
     * @param  list<string>  $pmtaRelayIps
     * @return list<array{type: 'command', command: string}>
     */
    public function plan(
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
    ): array {
        if (! $applyFirewall) {
            return array_map(
                fn (string $command): array => ['type' => 'command', 'command' => $command],
                [
                    'systemctl stop firewalld;',
                    'systemctl disable firewalld;',
                    'systemctl stop iptables;',
                    'systemctl disable iptables;',
                ],
            );
        }

        $commands = [
            'dnf install -y firewalld;',
            'systemctl is-active -q dbus || systemctl restart dbus;',
            'systemctl unmask --now firewalld',
            'systemctl start firewalld;',
            'systemctl enable firewalld;',
            'firewall-cmd --permanent --zone=public --add-service={http,https,dns,smtp,ntp,dns};',
            'firewall-cmd --permanent --new-zone=dev;',
            "firewall-cmd --permanent --zone=dev --add-port={$serverSshPort}/tcp;",
            'firewall-cmd --permanent --zone=dev --add-port=143/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=993/tcp;',
            "firewall-cmd --permanent --zone=dev --add-port={$pmtaPort}/tcp;",
            "firewall-cmd --permanent --zone=dev --add-port={$pmtaAdminPort}/tcp;",
            'firewall-cmd --permanent --zone=dev --add-port='.($pmtaAdminPort * 2).'/tcp;',
            "firewall-cmd --permanent --zone=dev --add-port={$proxyPort}/tcp;",
        ];

        if ($allowAllMonitor) {
            $commands[] = "firewall-cmd --permanent --zone=public --add-port={$pmtaAdminPort}/tcp;";
        }

        $alreadyAllowedAdminSources = array_values(array_unique([...$developmentIps, ...$productionIps]));
        $additionalAdminSources = array_values(array_diff(array_unique($pmtaRelayIps), $alreadyAllowedAdminSources));
        if ($additionalAdminSources !== []) {
            $commands[] = "firewall-cmd --permanent --get-zones | tr ' ' '\\n' | grep -qx pmta-admin || firewall-cmd --permanent --new-zone=pmta-admin;";
            $commands[] = "firewall-cmd --permanent --zone=pmta-admin --add-port={$pmtaAdminPort}/tcp;";
            foreach ($additionalAdminSources as $ip) {
                $commands[] = "firewall-cmd --permanent --zone=pmta-admin --add-source={$ip};";
            }
        }

        foreach ($developmentIps as $ip) {
            $commands[] = "firewall-cmd --permanent --zone=dev --add-source={$ip};";
        }

        array_push(
            $commands,
            'firewall-cmd --permanent --new-zone=production;',
            "firewall-cmd --permanent --zone=production --add-port={$pmtaAdminPort}/tcp;",
            "firewall-cmd --permanent --zone=production --add-port={$proxyPort}/tcp;",
        );

        foreach ($productionIps as $ip) {
            $commands[] = "firewall-cmd --permanent --zone=production --add-source={$ip};";
        }

        array_push(
            $commands,
            'firewall-cmd --permanent --new-zone=rdp;',
            "firewall-cmd --permanent --zone=rdp --add-port={$proxyPort}/tcp;",
        );

        foreach ($rdpIps as $ip) {
            $commands[] = "firewall-cmd --permanent --zone=rdp --add-source={$ip};";
        }

        array_push(
            $commands,
            'firewall-cmd --permanent --zone=public --remove-service=ssh;',
            'firewall-cmd --reload;',
        );

        return array_map(fn (string $command): array => ['type' => 'command', 'command' => $command], $commands);
    }
}
