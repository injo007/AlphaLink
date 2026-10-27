<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;

/** Applies the DMS Install and Reinstall CONFIG IPS behavior through AlphaLink's saved SSH identity. */
class DmsReinstallIpConfigurator
{
    private const MAX_EXPANDED_IPS = 4096;

    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly DmsInstallPlatform $platform,
    ) {}

    /**
     * @param  list<string>  $requestedIps
     *
     * @throws HostProbeException
     */
    public function configure(
        Workspace $workspace,
        ManagedServer $server,
        array $requestedIps,
        bool $permanent,
        bool $requireInstalled = true,
    ): void {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || ! $server->is_active
            || ($requireInstalled && ! $server->is_installed)) {
            throw new InvalidArgumentException('Only eligible active servers in this workspace can configure IPs.');
        }

        $blockers = $this->platform->blockers(is_array($server->host_facts) ? $server->host_facts : []);
        if ($blockers !== []) {
            throw new InvalidArgumentException($blockers[0]);
        }

        $ips = $this->expandIps($requestedIps);
        $ips = array_values(array_filter(
            array_unique($ips),
            static fn (string $ip): bool => $ip !== (string) $server->ip_address,
        ));

        if ($ips === []) {
            throw new InvalidArgumentException('Enter at least one secondary IPv4 address or CIDR.');
        }

        $credential = $this->credentials->resolve($server);
        $session = $this->sessions->create($server, $credential['secret'], $credential['type']);
        $interface = trim((string) $session->exec("ip route | grep 'default' | awk -F ' ' '{print $5}'"));
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $interface)) {
            throw new RuntimeException('The target host did not report a supported network interface.');
        }

        if ($permanent) {
            $this->configurePermanentIps($session, $interface, $ips);

            return;
        }

        foreach ($ips as $ip) {
            $result = $session->exec(sprintf(
                'ip address add %s/32 dev %s && printf ALPHALINK_IP_CONFIGURED',
                escapeshellarg($ip),
                escapeshellarg($interface),
            ));

            if (! is_string($result) || ! str_contains($result, 'ALPHALINK_IP_CONFIGURED')) {
                throw new RuntimeException('The target host did not confirm an IP address change.');
            }
        }
    }

    /**
     * @param  list<string>  $requestedIps
     * @return list<string>
     */
    private function expandIps(array $requestedIps): array
    {
        $result = [];

        foreach ($requestedIps as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (! str_contains($entry, '/')) {
                if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('CONFIG IPS accepts IPv4 addresses and IPv4 CIDR ranges.');
                }
                $result[] = $entry;

                continue;
            }

            [$address, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            if (! is_string($mask)
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! ctype_digit($mask)
                || (int) $mask < 0
                || (int) $mask > 32) {
                throw new InvalidArgumentException('Enter a valid IPv4 CIDR range.');
            }

            $prefix = (int) $mask;
            $hostCount = (2 ** (32 - $prefix)) - 2;
            if ($hostCount <= 0) {
                continue;
            }
            if ($hostCount > self::MAX_EXPANDED_IPS || count($result) + $hostCount > self::MAX_EXPANDED_IPS) {
                throw new InvalidArgumentException('A CIDR range may expand to at most 4096 IP addresses.');
            }

            $packedAddress = inet_pton($address);
            if ($packedAddress === false) {
                throw new InvalidArgumentException('Enter a valid IPv4 CIDR range.');
            }
            $addressNumber = unpack('N', $packedAddress)[1];
            $maskNumber = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
            $network = $addressNumber & $maskNumber;
            $start = $network + 1;
            $end = $network + (2 ** (32 - $prefix)) - 2;

            for ($number = $start; $number <= $end; $number++) {
                $result[] = long2ip($number);
            }
        }

        if (count($result) > self::MAX_EXPANDED_IPS) {
            throw new InvalidArgumentException('A CIDR range may expand to at most 4096 IP addresses.');
        }

        return $result;
    }

    /**
     * @param  list<string>  $ips
     */
    private function configurePermanentIps(
        DmsInstallerSshSession $session,
        string $interface,
        array $ips,
    ): void {
        $gateway = trim((string) $session->exec("ip route | grep 'default' | awk -F ' ' '{print $3}'"));
        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('The target host did not report a supported IPv4 gateway.');
        }

        $clearResult = $session->exec(sprintf(
            'rm -f -- /etc/sysconfig/network-scripts/ifcfg-%s:* && printf ALPHALINK_ALIAS_FILES_CLEARED',
            $interface,
        ));
        if (! is_string($clearResult) || ! str_contains($clearResult, 'ALPHALINK_ALIAS_FILES_CLEARED')) {
            throw new RuntimeException('The target host did not confirm the existing alias files were cleared.');
        }

        foreach ($ips as $index => $ip) {
            $alias = $index + 1;
            $contents = implode("\n", [
                'BOOTPROTO=static',
                'DEFROUTE=yes',
                "DEVICE={$interface}:{$alias}",
                "GATEWAY={$gateway}",
                "IPADDR={$ip}",
                'NETMASK=255.255.255.0',
                'NM_CONTROLLED=no',
                'ONBOOT=yes',
                'STARTMODE=auto',
                'TYPE=Ethernet',
                'USERCTL=no',
                '',
            ]);
            $path = sprintf('/etc/sysconfig/network-scripts/ifcfg-%s:%s', $interface, $alias);
            if (! $session->upload($contents, $path)) {
                throw new RuntimeException('The target host did not accept the permanent IP configuration file.');
            }
        }

        $restartResult = $session->exec('systemctl restart network && printf ALPHALINK_NETWORK_RESTARTED');
        if (! is_string($restartResult) || ! str_contains($restartResult, 'ALPHALINK_NETWORK_RESTARTED')) {
            throw new RuntimeException('The target host did not confirm the network service restart.');
        }
    }
}
