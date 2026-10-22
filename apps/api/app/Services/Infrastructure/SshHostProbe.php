<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

class SshHostProbe
{
    public function fingerprint(ManagedServer $server): string
    {
        try {
            $ssh = $this->connect($server);

            return $this->readFingerprint($ssh);
        } catch (HostProbeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostProbeException('Could not read the SSH host key. Check the IP, port, and server reachability.');
        }
    }

    public function inspect(ManagedServer $server, string $credential, string $expectedFingerprint, string $credentialType = 'password'): array
    {
        try {
            $ssh = $this->connect($server);
            $actual = $this->readFingerprint($ssh);
            if (! hash_equals($expectedFingerprint, $actual)) {
                throw new HostProbeException('SSH host fingerprint changed or does not match the provider fingerprint.');
            }
            $loginCredential = $credentialType === 'private_key'
                ? PublicKeyLoader::loadPrivateKey($credential)
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

            return ['fingerprint' => $actual, 'facts' => $facts];
        } catch (HostProbeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostProbeException('SSH preflight failed. Check the host and try again.');
        }
    }

    protected function connect(ManagedServer $server): SSH2
    {
        if (! app()->environment('testing') && ! filter_var($server->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new HostProbeException('Preflight is limited to registered public IP addresses.');
        }
        $ssh = new SSH2($server->ip_address, (int) $server->ssh_port, 8);
        $ssh->setTimeout(8);

        return $ssh;
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
            throw new HostProbeException('The server host key fingerprint could not be calculated.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBlob, true)), '=');
    }

    private function run(SSH2 $ssh, string $command): string
    {
        $output = $ssh->exec($command);
        if (! is_string($output) || strlen($output) > 8192 || $ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
            throw new HostProbeException('A read-only host fact command failed or timed out.');
        }

        return $output;
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
