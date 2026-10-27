<?php

namespace App\Services\Infrastructure;

class DmsBulkInstallVmtaPlanner
{
    /**
     * @param  list<array{ip: string, sub: string, domain: string}>  $ips
     * @param  list<string>  $isps
     * @return array{vmtas: string, dkim_domains: ?string, inbound: ?string}
     */
    public function files(
        string $platform,
        bool $isOwnedDomain,
        array $ips,
        array $isps,
        string $dkimSelector,
        bool $useDkim,
        bool $dkimKeyUploadSucceeded,
    ): array {
        if (! in_array($platform, ['centos7', 'centos9'], true)) {
            throw new \InvalidArgumentException('The DMS VMTA builder only supports its CentOS 7 and CentOS 9 installers.');
        }

        $vmtas = '';
        $dkimDomains = '';
        $inbound = '';
        $feedbackAddresses = [];

        foreach ($ips as $entry) {
            $entryIsOwnedDomain = (bool) ($entry['is_our'] ?? $isOwnedDomain);
            $domain = ($entryIsOwnedDomain || $platform === 'centos9') && $entry['sub'] !== ''
                ? $entry['sub'].'.'.$entry['domain']
                : $entry['domain'];
            $vmtaName = $this->ipVirtualMtaName($entry['ip']);
            $vmtaIp = substr($vmtaName, strlen('mta-'));

            $dkimDomains .= "domain-key $dkimSelector,$domain,/etc/pmta/dkim\n";
            $vmtas .= "<virtual-mta $vmtaName>\n";
            $vmtas .= "   smtp-source-ip {$entry['ip']}\n";
            $vmtas .= "   host-name $domain\n";
            $vmtas .= "</virtual-mta>\n";

            foreach ($isps as $isp) {
                $vmtas .= "<virtual-mta mta-$vmtaIp-$isp>\n";
                $vmtas .= "   smtp-source-ip {$entry['ip']}\n";
                $vmtas .= "   host-name $domain\n";
                $vmtas .= "</virtual-mta>\n";
            }

            if ($entryIsOwnedDomain) {
                $inbound .= "relay-domain $domain\n";
                $inbound .= "<domain $domain>\n";
                $inbound .= "   type file\n";
                $inbound .= "   file-format append-mbox\n";
                $inbound .= "   file-destination /etc/pmta/inbound/\$domain\n";
                $inbound .= "</domain>\n";

                if ($platform === 'centos9') {
                    $feedbackAddresses[] = "    address /fbl@$domain/";
                }
            }
        }

        if ($feedbackAddresses !== []) {
            $feedbackProcessor = "<feedback-loop-processor>\n";
            $feedbackProcessor .= "<address-list>\n";
            $feedbackProcessor .= implode("\n", $feedbackAddresses)."\n";
            $feedbackProcessor .= "</address-list>\n";
            $feedbackProcessor .= "</feedback-loop-processor>\n";
            $inbound .= "\n".$feedbackProcessor;
        }

        return [
            'vmtas' => $vmtas,
            'dkim_domains' => $useDkim && $dkimKeyUploadSucceeded ? $dkimDomains : null,
            'inbound' => $inbound !== '' ? $inbound : null,
        ];
    }

    public function ipVirtualMtaName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('The PMTA source IP must be a valid IPv4 or IPv6 address.');
        }

        $vmtaIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? str_replace(':', '.', $ip)
            : $ip;

        return 'mta-'.$vmtaIp;
    }
}
