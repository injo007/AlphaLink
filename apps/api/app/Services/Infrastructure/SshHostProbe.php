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
            $pmta = trim($this->run($ssh, 'if command -v pmta >/dev/null 2>&1 || command -v pmtad >/dev/null 2>&1 || rpm -q PowerMTA >/dev/null 2>&1; then printf present; fi'));
            $pmtaIdentity = $pmta === 'present' ? $this->reviewedPmtaIdentity($ssh) : null;
            $pmtaQueue = $pmta === 'present'
                ? trim($this->run($ssh, 'if test -d /var/spool/pmta && find /var/spool/pmta -type f -size +0c -print -quit | grep -q .; then printf nonempty; fi'))
                : '';

            $facts = [
                'os_id' => $this->osValue($os, 'ID'),
                'os_version' => $this->osValue($os, 'VERSION_ID'),
                'os_pretty_name' => $this->osValue($os, 'PRETTY_NAME'),
                'architecture' => substr($architecture, 0, 48),
                'package_manager' => $packageManager !== '' ? basename(explode("\n", $packageManager)[0]) : null,
                'disk_available_kb' => $this->availableDisk($disk),
                'pmta_binary_present' => $pmta === 'present',
                'pmta_reviewed_identity' => $pmtaIdentity,
                'pmta_queue_nonempty' => $pmtaQueue === 'nonempty',
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
     * Runs DMS's read-only global-IP discovery command after a successful local preflight.
     * The stored first-use host key is checked internally; no fingerprint is requested from an operator.
     */
    public function discoverGlobalServerIps(ManagedServer $server, string $credential, string $credentialType = 'password'): string
    {
        if ($server->state !== 'verified' || ! is_string($server->expected_host_fingerprint) || $server->expected_host_fingerprint === '') {
            throw new HostProbeException('Verify the server host before installation.');
        }

        try {
            $ssh = $this->connect($server);
            $fingerprint = $this->readFingerprint($ssh);
            if (! hash_equals($server->expected_host_fingerprint, $fingerprint)) {
                throw new HostProbeException('The SSH host key changed since the previous preflight.');
            }

            $loginCredential = in_array($credentialType, ['private_key', 'ssh_key'], true)
                ? PublicKeyLoader::load($credential)
                : $credential;
            if (! $ssh->login($server->ssh_username, $loginCredential)) {
                throw new HostProbeException('SSH authentication failed. Check the stored credential.');
            }

            return $this->run($ssh, 'ip -o addr show scope global | awk \'{split($4, a, "/"); print a[1]}\'', 262144);
        } catch (HostProbeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostProbeException('Server IP discovery failed. Check the host and try again.');
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

    public function isPublicAddress(string $ip): bool
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

    private function run(SSH2 $ssh, string $command, int $maxOutputBytes = 8192): string
    {
        $output = $ssh->exec($command);
        if (! is_string($output) || strlen($output) > $maxOutputBytes || $ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
            throw new HostProbeException('A read-only host fact command failed or timed out.');
        }

        return $output;
    }

    private function reviewedPmtaIdentity(SSH2 $ssh): ?string
    {
        $rpm = trim($this->run($ssh, "rpm -q PowerMTA --qf '%{VERSION}-%{RELEASE} %{ARCH}' 2>/dev/null || true"));
        $patchPath = str_starts_with($rpm, '4.0r') ? '/usr/sbin/pmtahttpd' : '/usr/sbin/pmtad';
        $patch = trim($this->run($ssh, 'sha256sum '.escapeshellarg($patchPath)." 2>/dev/null | cut -d' ' -f1"));
        $license = trim($this->run($ssh, "sha256sum /etc/pmta/license 2>/dev/null | cut -d' ' -f1"));
        $reviewed = DmsReviewedAssetDigestVerifier::REVIEWED_DIGESTS;
        $versions = [
            '4.0' => ['rpm' => '4.0r6-201204021809 x86_64', 'patch' => 'httpd-patch'],
            '4.5' => ['rpm' => '4.5r11-201709182152 x86_64', 'patch' => 'pmtad-patch'],
            '5.0' => ['rpm' => '5.0r3-202002061943 x86_64', 'patch' => 'pmtad-patch'],
        ];

        foreach ($versions as $version => $expected) {
            if ($rpm === $expected['rpm']
                && hash_equals($reviewed["pmta/{$version}/{$expected['patch']}"]['sha256'], $patch)
                && hash_equals($reviewed["pmta/{$version}/license.lic"]['sha256'], $license)) {
                return $version;
            }
        }

        return null;
    }

    private function readFingerprint(SSH2 $ssh): string
    {
        return (new SshHostKeyFingerprint)->fromPublicKey($ssh->getServerPublicHostKey());
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
