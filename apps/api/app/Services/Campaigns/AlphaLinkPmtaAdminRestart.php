<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use App\Services\Infrastructure\AlphaLinkServerConsole;
use RuntimeException;

/** Executes the source-mapped reference PMTA restart sequence over the saved AlphaLink SSH session. */
class AlphaLinkPmtaAdminRestart
{
    public function __construct(private readonly AlphaLinkServerConsole $console) {}

    public function run(ManagedServer $server): string
    {
        if (filter_var($server->ip_address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The selected server does not have a valid IP address.');
        }

        $domain = $server->ips()->orderBy('id')->value('dns') ?: 'no-domain.com';
        $hostname = escapeshellarg((string) $domain);
        $hostEntry = escapeshellarg($server->ip_address.' '.$domain);
        $setNetworkHostname = escapeshellarg('s/HOSTNAME=.*/HOSTNAME='.$domain.'/g');
        $commands = [
            'hostnamectl set-hostname '.$hostname,
            'sed -i '.$setNetworkHostname.' /etc/sysconfig/network',
            'grep -qxF '.$hostEntry.' /etc/hosts || echo -e '.$hostEntry.' >> /etc/hosts',
            'kill -9 $(lsof -t -i:25)',
            'pmta clear dns \\*',
            'service pmta restart;service pmtahttp restart;',
        ];
        $results = $this->console->executeSequence($server, $commands);

        return $results[array_key_last($results)] ?? '';
    }
}
