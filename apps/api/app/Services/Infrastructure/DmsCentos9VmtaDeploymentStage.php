<?php

namespace App\Services\Infrastructure;

/** Replays DMS CentOS 9 VMTA uploads and post-upload commands through isolated transports. */
class DmsCentos9VmtaDeploymentStage
{
    public function __construct(private readonly DmsBulkInstallVmtaPlanner $planner) {}

    /**
     * @param  list<array{ip: string, sub: string, domain: string}>  $ips
     * @param  list<string>  $isps
     */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
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

        $files->upload($plannedFiles['vmtas'], '/etc/pmta/conf/vmta');
        if ($plannedFiles['inbound'] !== null) {
            $files->upload($plannedFiles['inbound'], '/etc/pmta/settings/inbound');
        }

        $dkimKeyUploaded = false;
        if ($useDkim) {
            $dkimKeyUploaded = $files->upload($dkimKey, '/etc/pmta/dkim');
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
            $files->upload($dkimFiles['dkim_domains'], '/etc/pmta/conf/dkim');
        }

        $commands->exec('chown -R pmta:pmta /etc/pmta');
        $commands->exec('systemctl restart pmta;');
        $commands->exec('systemctl restart pmtahttp;');
    }
}
