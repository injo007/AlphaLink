<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/** Builds a credentialed installer session only after matching the internally saved host-key fingerprint. */
class DmsInstallerSshSessionFactory
{
    public function __construct(
        private readonly DmsInstallerSshConnector $connector,
        private readonly SshHostProbe $hostProbe,
        private readonly SshHostKeyFingerprint $fingerprints,
    ) {}

    /**
     * @throws HostProbeException
     */
    public function create(ManagedServer $server, string $credential, string $credentialType = 'password'): DmsInstallerSshSession
    {
        if ($server->state !== 'verified'
            || ! is_string($server->expected_host_fingerprint)
            || $server->expected_host_fingerprint === '') {
            throw new HostProbeException('Verify the server host before installation.');
        }

        if (! app()->environment('testing') && ! $this->hostProbe->isPublicAddress((string) $server->ip_address)) {
            throw new HostProbeException('Installation transport is limited to registered public IP addresses.');
        }

        $port = filter_var($server->ssh_port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new HostProbeException('The registered SSH port is invalid.');
        }

        try {
            $loginCredential = match ($credentialType) {
                'password' => $credential,
                'private_key', 'ssh_key' => PublicKeyLoader::load($credential),
                default => throw new HostProbeException('The stored SSH credential type is unsupported.'),
            };

            $timeoutSeconds = max(30, min(1800, (int) config('infrastructure.install_ssh_timeout_seconds', 300)));
            $connection = $this->connector->connect((string) $server->ip_address, $port, $timeoutSeconds);
            $fingerprint = $this->fingerprints->fromPublicKey($connection->getServerPublicHostKey());
            if (! hash_equals($server->expected_host_fingerprint, $fingerprint)) {
                throw new HostProbeException('The SSH host key changed since the previous preflight.');
            }

            if (! $connection->login((string) $server->ssh_username, $loginCredential)) {
                throw new HostProbeException('SSH authentication failed. Check the stored credential.');
            }

            return new DmsInstallerSshSession($connection);
        } catch (HostProbeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new HostProbeException('SSH installation session could not be established.');
        }
    }
}
