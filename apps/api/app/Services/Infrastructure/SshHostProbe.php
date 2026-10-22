<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

class SshHostProbe
{
    public function inspect(ManagedServer $server, string $credential, string $credentialType = 'password'): array
    {
        try {
            $ssh = $this->connect($server);
            $fingerprint = $this->readFingerprint($ssh);
            if ($server->expected_host_fingerprint !== null
                && ! hash_equals($server->expected_host_fingerprint, $fingerprint)) {
                throw new HostProbeException('The SSH host key changed since the previous preflight.');
            }
            $loginCredential = in_array($credentialType, ['private_key', 'ssh_key'], true)
                ? PublicKeyLoader::load($credential)
                : $credential;
            if (! $ssh->login($server->ssh_username, $loginCredential)) {
                throw new HostProbeException('SSH authentication failed. Check the stored credential.');
            }

            $os = $this->run($ssh, 'cat /etc/os-release');
            $architecture = trim($this->run($ssh, 'uname -m'));
            $disk = trim($this->run($ssh, 'df -Pk /'));
            $packageManager = trim($this->run($ssh, 'command -v dnf || command -v yum || command -v apt-get || true'));
            $pmta = trim($this->run($ssh, 'command -v pmta || true'));

            $facts = [
                'os_id' => $this->osValue($os, 'ID'),
                'os_version' => $this->osValue($os, 'VERSION_ID'),
                'architecture' => substr($architecture, 0, 48),
                'package_manager' => $packageManager !== '' ? basename(explode("\n", $packageManager)[0]) : null,
                'disk_available_kb' => $this->availableDisk($disk),
                'pmta_binary_present' => $pmta !== '',
            ];
            if (! $facts['os_id'] || ! $facts['os_version'] || ! $facts['architecture']) {
                throw new HostProbeException('The host did not return complete OS and architecture facts.');
            }

            return ['fingerprint' => $fingerprint, 'facts' => $facts];
        } catch (HostProbeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostProbeException('SSH preflight failed. Check the host and try again.');
        }
    }

    /**
     * Tests DMS's SSH/SFTP login outcome without running a command or persisting state.
     *
     * @return array{ip:string,status:string,message:string}
     */
    public function testConnection(string $ip, int $port, string $username, string $loginType, string $credential): array
    {
        if (! $this->isPublicAddress($ip)) {
            return [
                'ip' => $ip,
                'status' => 'blocked',
                'message' => sprintf('CONNECTION BLOCKED ON IP: %s PORT : %s', $ip, $port),
            ];
        }

        try {
            $sftp = new SFTP($ip, $port, 8);
            $sftp->setTimeout(8);
            $publicKey = $sftp->getServerPublicHostKey();
            if (! is_string($publicKey)) {
                return [
                    'ip' => $ip,
                    'status' => 'down',
                    'message' => sprintf('IP DOWN: %s PORT : %s', $ip, $port),
                ];
            }

            $loginCredential = $loginType === 'ssh_key' ? PublicKeyLoader::load($credential) : $credential;
            if ($sftp->login($username, $loginCredential)) {
                return [
                    'ip' => $ip,
                    'status' => 'connected',
                    'message' => sprintf('CONNECTED ON IP: %s PORT : %s', $ip, $port),
                ];
            }

            return [
                'ip' => $ip,
                'status' => 'authentication_failed',
                'message' => sprintf('COULD NOT CONNECT ON IP: %s PORT : %s', $ip, $port),
            ];
        } catch (Throwable) {
            return [
                'ip' => $ip,
                'status' => 'connection_failed',
                'message' => sprintf('COULD NOT CONNECT ON IP: %s PORT : %s', $ip, $port),
            ];
        }
    }

    protected function connect(ManagedServer $server): SSH2
    {
        if (! app()->environment('testing') && ! $this->isPublicAddress($server->ip_address)) {
            throw new HostProbeException('Preflight is limited to registered public IP addresses.');
        }
        $ssh = new SSH2($server->ip_address, (int) $server->ssh_port, 8);
        $ssh->setTimeout(8);

        return $ssh;
    }

    private function isPublicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packedAddress = inet_pton($ip);
        if ($packedAddress === false) {
            return false;
        }

        $blockedRanges = strlen($packedAddress) === 4
            ? [
                '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15',
                '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ]
            : [
                '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
                '100::/64', '100:0:0:1::/64', '2001:2::/48', '2001:10::/28',
                '2001:db8::/32', '2001:20::/28', '2002::/16', '3fff::/20', '5f00::/16',
            ];

        foreach ($blockedRanges as $range) {
            if ($this->addressMatchesCidr($packedAddress, $range)) {
                return false;
            }
        }

        return true;
    }

    private function addressMatchesCidr(string $packedAddress, string $cidr): bool
    {
        [$network, $prefixLength] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $fullBytes = intdiv($prefixLength, 8);
        $partialBits = $prefixLength % 8;

        if (substr($packedAddress, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }
        if ($partialBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $partialBits)) & 0xFF;

        return (ord($packedAddress[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }

    private function run(SSH2 $ssh, string $command): string
    {
        $output = $ssh->exec($command);
        if (! is_string($output) || strlen($output) > 8192 || $ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
            throw new HostProbeException('A read-only host fact command failed or timed out.');
        }

        return $output;
    }

    private function readFingerprint(SSH2 $ssh): string
    {
        $publicKey = $ssh->getServerPublicHostKey();
        if (! is_string($publicKey)) {
            throw new HostProbeException('The server host key signature could not be verified.');
        }

        $parts = preg_split('/\s+/', trim($publicKey), 3);
        $keyBlob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if (! is_string($keyBlob) || $keyBlob === '') {
            throw new HostProbeException('The server host key could not be validated.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBlob, true)), '=');
    }

    private function osValue(string $text, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(?:"([^"\n]+)"|([^\n]+))$/m', $text, $matches)) {
            return null;
        }

        return substr(trim($matches[1] ?: $matches[2]), 0, 64);
    }

    private function availableDisk(string $text): ?int
    {
        $lines = preg_split('/\r?\n/', trim($text));
        if (count($lines) < 2 || ! preg_match('/^\S+\s+\d+\s+\d+\s+(\d+)\s+/', $lines[1], $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
