<?php

namespace App\Services\Infrastructure;

/** Reconstructs the source-backed DMS CentOS 9/AlmaLinux 9 service setup phase. */
class DmsCentos9ServiceSetup
{
    public function run(DmsInstallerRemoteCommandTransport $transport): void
    {
        $swap = $transport->exec('free -m | grep Swap | awk \'{print $2}\'');
        if ($swap == 0) {
            // DMS provisions a 2 GiB swapfile only when the reported RAM is below 1 GiB.
            $ram = $transport->exec('free -m | grep Mem | awk \'{print $2}\'');
            if ($ram < 1024) {
                $transport->exec('fallocate -l 2G /swapfile');
                $transport->exec('ls -lh /swapfile');
                $transport->exec('chmod 600 /swapfile');
                $transport->exec('mkswap /swapfile');
                $transport->exec('swapon /swapfile');
                $transport->exec('echo "/swapfile swap swap defaults 0 0" >> /etc/fstab');
            }
        }

        foreach ($this->serviceCommands() as $command) {
            $transport->exec($command);
        }
    }

    /** @return list<string> */
    private function serviceCommands(): array
    {
        return [
            "echo -e \"nameserver 1.1.1.1\nnameserver 8.8.8.8\" > /etc/resolv.conf",
            'setenforce 0',
            "sed -i 's/SELINUX=enforcing/SELINUX=disabled/g' /etc/selinux/config;",
            'dnf install -y ca-certificates --disablerepo=epel || dnf update -y ca-certificates --disablerepo=epel',
            'dnf install -y curl  --disablerepo=epel || dnf update -y curl --disablerepo=epel',
            'dnf install -y http://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm;',
            'dnf install -y gcc make nano lsof wget curl unzip iproute;',
            'dnf install -y http://rpms.remirepo.net/enterprise/remi-release-9.rpm;',
            'ulimit -H -u 80000',
            'ulimit -H -n 80000',
            'dnf install -y openssh;',
            "sed -i 's/#MaxSessions 10/MaxSessions 1200/g' /etc/ssh/sshd_config;",
            'systemctl restart sshd',
            'timedatectl set-timezone Africa/Casablanca',
            'timedatectl set-local-rtc 0',
            'timedatectl set-ntp true',
            'systemctl restart crond.service;',
            'systemctl restart systemd-timedated;',
        ];
    }
}
