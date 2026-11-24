<?php

namespace App\Services\Infrastructure;

class AlphaLinkBulkInstallVmtaPlanner
{
    /**
     * @param  list<array{ip: string, sub: string, domain: string, ehlo_hostname?: string, domains?: list<array{sub: string, domain: string, is_our?: bool}>}>  $ips
     * @param  list<string>  $isps
     * @param  array<string, array{selector: string, path: string}>  $signingIdentities
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
        array $signingIdentities = [],
    ): array {
        if (! in_array($platform, ['centos7', 'centos9'], true)) {
            throw new \InvalidArgumentException('The VMTA builder only supports the CentOS 7 and CentOS 9 installers.');
        }

        $vmtas = '';
        $dkimDomains = '';
        $inbound = '';
        $feedbackAddresses = [];
        $seenIps = [];
        $seenDkimDomains = [];
        $seenInboundDomains = [];

        foreach ($ips as $entry) {
            if (isset($seenIps[$entry['ip']])) {
                throw new \InvalidArgumentException('A physical IP may appear only once in PMTA VMTA configuration.');
            }
            $seenIps[$entry['ip']] = true;
            $entryIsOwnedDomain = (bool) ($entry['is_our'] ?? $isOwnedDomain);
            $domain = ($entryIsOwnedDomain || $platform === 'centos9') && $entry['sub'] !== ''
                ? $entry['sub'].'.'.$entry['domain']
                : $entry['domain'];
            $identities = [['hostname' => $domain, 'is_our' => $entryIsOwnedDomain]];
            foreach ($entry['domains'] ?? [] as $additionalDomain) {
                $additionalOwned = (bool) ($additionalDomain['is_our'] ?? $isOwnedDomain);
                $additionalHostname = $additionalDomain['sub'] !== ''
                    ? $additionalDomain['sub'].'.'.$additionalDomain['domain']
                    : $additionalDomain['domain'];
                $identities[] = ['hostname' => $additionalHostname, 'is_our' => $additionalOwned];
            }
            if (count($identities) > 1 && (! $useDkim || $signingIdentities === [])) {
                throw new \InvalidArgumentException('Multi-Domain per IP requires independent DKIM signing identities.');
            }
            $vmtaName = $this->ipVirtualMtaName($entry['ip']);
            $vmtaIp = substr($vmtaName, strlen('mta-'));

            $sourceIp = ! empty($entry['interface_ip']) ? $entry['interface_ip'] : $entry['ip'];
            $ehloHostname = trim((string) ($entry['ehlo_hostname'] ?? $domain));
            if (filter_var($ehloHostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new \InvalidArgumentException('The PMTA EHLO hostname must be a valid domain name.');
            }

            foreach ($identities as $identity) {
                $hostname = strtolower($identity['hostname']);
                if (! isset($seenDkimDomains[$hostname])) {
                    $signing = $signingIdentities[$hostname] ?? null;
                    if ($signingIdentities !== [] && $signing === null) {
                        throw new \InvalidArgumentException('A selected sending domain has no independent DKIM signing identity.');
                    }
                    $selector = $signing['selector'] ?? $dkimSelector;
                    $path = $signing['path'] ?? '/etc/pmta/dkim';
                    if (($useDkim && ! preg_match('/\A[A-Za-z0-9_-]{1,63}\z/', $selector))
                        || ($signing !== null && ! preg_match('#\A/etc/pmta/dkim-keys/[A-Za-z0-9._-]+\.pem\z#', $path))) {
                        throw new \InvalidArgumentException('A selected DKIM selector or private-key path is invalid.');
                    }
                    $dkimDomains .= "domain-key $selector,$hostname,$path\n";
                    $seenDkimDomains[$hostname] = true;
                }
                if ($identity['is_our'] && ! isset($seenInboundDomains[$hostname])) {
                    $inbound .= "relay-domain $hostname\n";
                    $inbound .= "<domain $hostname>\n";
                    $inbound .= "   type file\n";
                    $inbound .= "   file-format append-mbox\n";
                    $inbound .= "   file-destination /etc/pmta/inbound/\$domain\n";
                    $inbound .= "</domain>\n";
                    $seenInboundDomains[$hostname] = true;
                    if ($platform === 'centos9') {
                        $feedbackAddresses[] = "    address /fbl@$hostname/";
                    }
                }
            }
            $vmtas .= "<virtual-mta $vmtaName>\n";
            $vmtas .= "   smtp-source-ip {$sourceIp}\n";
            $vmtas .= "   host-name $ehloHostname\n";
            $vmtas .= "</virtual-mta>\n";

            foreach ($isps as $isp) {
                $vmtas .= "<virtual-mta mta-$vmtaIp-$isp>\n";
                $vmtas .= "   smtp-source-ip {$sourceIp}\n";
                $vmtas .= "   host-name $ehloHostname\n";
                $vmtas .= "</virtual-mta>\n";
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
