<?php

namespace App\Services;

use InvalidArgumentException;

class PmtaVirtualMtaConfigBuilder
{
    /**
     * @param  list<array{id: int, ip: string, dns: string, domain: string, route_host: string|null, duplicate_index: int}>  $rows
     */
    public function build(array $rows, bool $route, int $pmtaVersion): string
    {
        $data = '';
        foreach ($rows as $row) {
            $ip = $row['ip'];
            $domain = $row['domain'] !== '' ? $row['domain'] : $row['dns'];
            if (! $this->hostIsSafe($ip) || ! $this->hostIsSafe($domain)) {
                throw new InvalidArgumentException('Use a valid IP address and domain name.');
            }

            $data .= '<virtual-mta '.$this->virtualMtaName($row['id'], $ip, $row['duplicate_index']).">\n";
            $data .= "    smtp-source-ip {$ip}\n";
            $data .= "    host-name {$domain}\n";
            if ($route) {
                $routeHost = (string) ($row['route_host'] ?? '');
                if ($routeHost !== '' && ! $this->hostIsSafe($routeHost)) {
                    throw new InvalidArgumentException('The route host contains unsupported characters.');
                }
                $data .= "    <domain *>\n";
                $data .= "      max-smtp-out 1200\n";
                $data .= "      max-rcpt-per-message 100\n";
                $data .= "      bounce-after 4d12h\n";
                $data .= "      retry-after 5m\n";
                $data .= "      log-connections no\n";
                $data .= "      log-commands no\n";
                $data .= "      log-transfer-failures no\n";
                $data .= $pmtaVersion !== 40
                    ? "      smtp-hosts lookup-mx:{$routeHost}\n"
                    : "      route {$routeHost}\n";
                $data .= "    </domain>\n";
            }
            $data .= "</virtual-mta>\n";
        }

        return $data;
    }

    public function virtualMtaName(int $id, string $ip, int $duplicateIndex): string
    {
        return 'vmta-'.str_replace(':', '.', $ip).'-'.$id.'-v_'.$duplicateIndex;
    }

    private function hostIsSafe(string $host): bool
    {
        return strlen($host) <= 253 && preg_match('/\A[A-Za-z0-9*.:_-]+\z/', $host) === 1;
    }
}
