<?php

namespace App\Services\Infrastructure;

/** Replays the optional DMS CentOS 9 Dovecot setup through isolated transports. */
class DmsCentos9ImapDeploymentStage
{
    /**
     * DMS skips IMAP entirely when its Dovecot config asset is absent. When present,
     * it installs Dovecot, uploads the config, creates the no-login account and
     * passwd file, then reloads/restarts/enables the service.
     */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
        ?string $configTemplate,
        string $username,
        string $password,
    ): void {
        if ($configTemplate === null) {
            return;
        }

        $commands->exec('rpm -qa | grep -qw dovecot || dnf -y install dovecot;');
        $files->upload($configTemplate, '/etc/dovecot/dovecot.conf');

        $replacement = str_replace(['\\', '/', '&'], ['\\\\', '\\/', '\\&'], $username);
        $commands->exec('sed -i '.escapeshellarg("s/__USER__/{$replacement}/g").' /etc/dovecot/dovecot.conf;');
        $commands->exec('useradd -s /sbin/nologin '.escapeshellarg($username).';');
        $userId = $commands->exec('id -u '.escapeshellarg($username).';');

        // DMS builds this line with `echo -e` in a shell command. Sending the
        // content over the private file transport keeps the password out of logs,
        // command traces and process arguments while preserving the passwd format.
        $passwdFile = $username.':{plain}'.$password.':'.($userId === false ? '' : $userId).':'.($userId === false ? '' : $userId)."::/dev/null:/sbin/nologin\n";
        $files->upload($passwdFile, '/etc/dovecot/imap.passwd');

        $commands->exec("sed -i 's/ProtectSystem=full/ProtectSystem=false/g' /usr/lib/systemd/system/dovecot.service");
        $commands->exec('systemctl daemon-reload');
        $commands->exec('systemctl restart dovecot');
        $commands->exec('systemctl enable dovecot');
    }
}
