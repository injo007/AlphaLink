<?php

namespace App\Services\Infrastructure;

/** Replays DMS CentOS 9's optional Squid proxy setup through isolated transports. */
class DmsCentos9ProxyDeploymentStage
{
    /**
     * DMS skips this feature when the Squid config asset is absent. Otherwise it
     * installs Squid, uploads its template and generated source/access lists,
     * optionally enables basic auth, then restarts and enables Squid.
     *
     * @param  array<array-key, string>  $ips
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
        ?string $configTemplate,
        int $proxyPort,
        array $ips,
        array $developmentIps,
        array $productionIps,
        string $username,
        string $password,
    ): void {
        if ($configTemplate === null) {
            return;
        }

        $commands->exec('dnf -y install squid;');
        $files->upload($configTemplate, '/etc/squid/squid.conf');
        $commands->exec("sed -i 's/__PORT__/{$proxyPort}/g' /etc/squid/squid.conf;");

        $sourceIps = '';
        foreach ($ips as $key => $ip) {
            $sourceIps .= "acl ip_{$key} myip {$ip}\n";
            $sourceIps .= "tcp_outgoing_address {$ip} ip_{$key}\n";
        }
        $files->upload($sourceIps, '/etc/squid/ips.conf');

        $allowedIps = [...$developmentIps, ...$productionIps];
        if (count($allowedIps) <= 1) {
            // Preserve DMS's PHP array-union behavior: with a single list entry
            // at key 0, the wildcard occupies key 0 and that entry is dropped.
            $allowedIps = ['0.0.0.0/0'] + $allowedIps;
        }
        $files->upload(implode("\n", $allowedIps), '/etc/squid/allowed_ips.conf');

        if (! empty($username) && ! empty($password)) {
            $commands->exec('touch /etc/squid/passwd && chown squid /etc/squid/passwd');

            $passwordHash = crypt($password, base64_encode($password));
            $files->upload($username.':'.$passwordHash, '/etc/squid/passwd');
            $files->upload(
                "auth_param basic program /usr/lib64/squid/basic_ncsa_auth /etc/squid/passwd\n".
                "acl auth_users proxy_auth REQUIRED\n".
                'http_access allow auth_users',
                '/etc/squid/auth_settings.conf',
            );
            $commands->exec('chown squid /etc/squid/auth_settings.conf;');
        } else {
            $commands->exec('touch /etc/squid/auth_settings.conf && chown squid /etc/squid/auth_settings.conf');
        }

        $commands->exec('systemctl restart squid;');
        $commands->exec('systemctl enable squid;');
    }
}
