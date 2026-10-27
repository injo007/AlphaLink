<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;

/** Reproduces the DMS server-console host checks before executing an operator command. */
class DmsServerConsole
{
    public function __construct(
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
    ) {}

    public function execute(ManagedServer $server, string $command): string
    {
        $outputs = $this->executeSequence($server, [$command]);

        return $outputs[0] ?? '';
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    public function executeSequence(ManagedServer $server, array $commands): array
    {
        $credential = $this->credentials->resolve($server);
        $session = $this->sessions->create($server, $credential['secret'], $credential['type']);

        $directoryListing = $session->exec('ls');
        if (str_contains(strtolower((string) $directoryListing), 'password')) {
            throw new HostProbeException('Server Ask For Password Change');
        }

        $architecture = strtolower(trim((string) $session->exec('uname -m')));
        if (! str_contains($architecture, 'x86_64')) {
            throw new HostProbeException('Unsupported Architecture : '.$architecture);
        }

        $release = strtolower(trim((string) $session->exec("awk -F= '/^PRETTY_NAME=/{print $2}' /etc/os-release")));
        if (! str_contains($release, 'centos linux 7')
            && ! str_contains($release, 'centos stream 9')
            && ! str_contains($release, 'rocky linux 9')
            && ! str_contains($release, 'almalinux 9')) {
            throw new HostProbeException('Unsupported Distribution : '.$release);
        }

        $outputs = [];
        foreach ($commands as $command) {
            $output = $session->exec($command);
            $outputs[] = is_string($output) ? $output : '';
        }

        return $outputs;
    }
}
