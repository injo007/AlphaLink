<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Replays reference CentOS 9 VMTA uploads and post-upload commands through isolated transports. */
class AlphaLinkCentos9VmtaDeploymentStage
{
    public function __construct(private readonly AlphaLinkBulkInstallVmtaPlanner $planner) {}

    /**
     * @param  list<array{ip: string, sub: string, domain: string, domains?: list<array{sub: string, domain: string}>}>  $ips
     * @param  list<string>  $isps
     * @param  array<string, array{selector: string, private_key: string}>  $signingIdentities
     */
    public function run(
        AlphaLinkInstallerRemoteCommandTransport $commands,
        AlphaLinkInstallerRemoteFileTransport $files,
        bool $isOwnedDomain,
        array $ips,
        array $isps,
        string $dkimSelector,
        bool $useDkim,
        string $dkimKey,
        array $signingIdentities = [],
    ): void {
        $signingFiles = [];
        foreach ($signingIdentities as $hostname => $identity) {
            $hostname = strtolower(trim($hostname));
            if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || ! preg_match('/\A[A-Za-z0-9.-]+\z/', $hostname)
                || ! isset($identity['selector'], $identity['private_key'])
                || ! is_string($identity['private_key']) || trim($identity['private_key']) === '') {
                throw new RuntimeException('A selected DKIM signing identity is invalid.');
            }
            $signingFiles[$hostname] = [
                'selector' => $identity['selector'],
                'path' => "/etc/pmta/dkim-keys/$hostname.pem",
                'private_key' => $identity['private_key'],
            ];
        }
        $signingReferences = array_map(
            static fn (array $identity): array => ['selector' => $identity['selector'], 'path' => $identity['path']],
            $signingFiles,
        );
        $plannedFiles = $this->planner->files(
            'centos9',
            $isOwnedDomain,
            $ips,
            $isps,
            $dkimSelector,
            $useDkim,
            false,
            $signingReferences,
        );

        if (! $files->upload($plannedFiles['vmtas'], '/etc/pmta/conf/vmta')) {
            throw new RuntimeException('The PMTA Virtual MTA configuration could not be uploaded.');
        }
        if ($plannedFiles['inbound'] !== null) {
            if (! $files->upload($plannedFiles['inbound'], '/etc/pmta/settings/inbound')) {
                throw new RuntimeException('The PMTA inbound configuration could not be uploaded.');
            }
        }

        $dkimKeyUploaded = false;
        if ($useDkim && $signingFiles !== []) {
            $created = $commands->exec('install -d -m 750 -o root -g pmta /etc/pmta/dkim-keys && printf ALPHALINK_DKIM_DIR_READY');
            if (trim((string) $created) !== 'ALPHALINK_DKIM_DIR_READY') {
                throw new RuntimeException('The PMTA DKIM key directory could not be prepared.');
            }
            foreach ($signingFiles as $identity) {
                if (! $files->upload($identity['private_key'], $identity['path'])) {
                    throw new RuntimeException('A selected DKIM private key could not be uploaded to PMTA.');
                }
            }
            $dkimKeyUploaded = true;
        } elseif ($useDkim) {
            $dkimKeyUploaded = $files->upload($dkimKey, '/etc/pmta/dkim');
            if (! $dkimKeyUploaded) {
                throw new RuntimeException('The selected DKIM private key could not be uploaded to PMTA.');
            }
        }

        $dkimFiles = $this->planner->files(
            'centos9',
            $isOwnedDomain,
            $ips,
            $isps,
            $dkimSelector,
            $useDkim,
            $dkimKeyUploaded,
            $signingReferences,
        );
        if ($dkimFiles['dkim_domains'] !== null) {
            if (! $files->upload($dkimFiles['dkim_domains'], '/etc/pmta/conf/dkim')) {
                throw new RuntimeException('The selected PMTA DKIM domain configuration could not be uploaded.');
            }
            $checks = ['test -s /etc/pmta/conf/dkim'];
            if ($signingFiles !== []) {
                foreach ($signingFiles as $hostname => $identity) {
                    $checks[] = 'test -s '.escapeshellarg($identity['path']);
                    $checks[] = 'grep -Fq '.escapeshellarg('domain-key '.$identity['selector'].','.$hostname.','.$identity['path']).' /etc/pmta/conf/dkim';
                    $checks[] = 'openssl pkey -in '.escapeshellarg($identity['path']).' -noout >/dev/null 2>&1';
                }
            } else {
                $checks[] = 'test -s /etc/pmta/dkim';
                $checks[] = 'grep -Fq '.escapeshellarg('domain-key '.$dkimSelector.',').' /etc/pmta/conf/dkim';
                $checks[] = 'openssl pkey -in /etc/pmta/dkim -noout >/dev/null 2>&1';
            }
            $dkimReady = $commands->exec(implode(' && ', $checks).' && printf ALPHALINK_DKIM_READY');
            if (trim((string) $dkimReady) !== 'ALPHALINK_DKIM_READY') {
                throw new RuntimeException('PMTA did not verify the selected DKIM key and domain configuration.');
            }
        } elseif ($useDkim) {
            throw new RuntimeException('The selected PMTA DKIM domain configuration is empty.');
        }

        $commands->exec('chown -R pmta:pmta /etc/pmta');
        if ($useDkim) {
            $commands->exec("test -f /etc/pmta/conf/domains && sed -i 's/#dkim-sign yes/dkim-sign yes/g' /etc/pmta/conf/domains 2>/dev/null || true");
            if ($signingFiles !== []) {
                $permissions = $commands->exec('chmod 640 /etc/pmta/dkim-keys/*.pem && chown root:pmta /etc/pmta/dkim-keys/*.pem && printf ALPHALINK_DKIM_PERMISSIONS_READY');
                if (trim((string) $permissions) !== 'ALPHALINK_DKIM_PERMISSIONS_READY') {
                    throw new RuntimeException('PMTA did not apply DKIM key file permissions.');
                }
            } else {
                $commands->exec('chmod 640 /etc/pmta/dkim 2>/dev/null || true');
                $commands->exec('chown root:pmta /etc/pmta/dkim 2>/dev/null || true');
            }
        }
        $commands->exec('systemctl restart pmta;');
        $commands->exec('systemctl restart pmtahttp;');
    }
}
