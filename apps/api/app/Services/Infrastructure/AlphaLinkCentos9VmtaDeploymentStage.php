<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Replays reference CentOS 9 VMTA uploads and post-upload commands through isolated transports. */
class AlphaLinkCentos9VmtaDeploymentStage
{
    public function __construct(private readonly AlphaLinkBulkInstallVmtaPlanner $planner) {}

    /**
     * @param  list<array{ip: string, sub: string, domain: string}>  $ips
     * @param  list<string>  $isps
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
    ): void {
        $plannedFiles = $this->planner->files(
            'centos9',
            $isOwnedDomain,
            $ips,
            $isps,
            $dkimSelector,
            $useDkim,
            false,
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
        if ($useDkim) {
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
        );
        if ($dkimFiles['dkim_domains'] !== null) {
            if (! $files->upload($dkimFiles['dkim_domains'], '/etc/pmta/conf/dkim')) {
                throw new RuntimeException('The selected PMTA DKIM domain configuration could not be uploaded.');
            }
            $selectorPrefix = 'domain-key '.$dkimSelector.',';
            $dkimReady = $commands->exec(
                'test -s /etc/pmta/dkim && test -s /etc/pmta/conf/dkim && '
                    .'grep -Fq '.escapeshellarg($selectorPrefix).' /etc/pmta/conf/dkim && '
                    .'openssl pkey -in /etc/pmta/dkim -noout >/dev/null 2>&1 && '
                    .'printf ALPHALINK_DKIM_READY',
            );
            if (trim((string) $dkimReady) !== 'ALPHALINK_DKIM_READY') {
                throw new RuntimeException('PMTA did not verify the selected DKIM key and domain configuration.');
            }
        } elseif ($useDkim) {
            throw new RuntimeException('The selected PMTA DKIM domain configuration is empty.');
        }

        $commands->exec('chown -R pmta:pmta /etc/pmta');
        $commands->exec('systemctl restart pmta;');
        $commands->exec('systemctl restart pmtahttp;');
    }
}
