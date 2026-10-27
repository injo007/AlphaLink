<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;
use RuntimeException;

/** Reconstructs DMS Centos9::setup_tracking() behind isolated transports. */
class DmsCentos9TrackingDeploymentStage
{
    /** @param list<string> $subDomains */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport&DmsInstallerRemoteReadableFileTransport $files,
        string $serverIp,
        int|string $serverId,
        ?string $domain,
        array $subDomains,
        ?string $miniAppArchive,
        ?string $cloudflareTrustedIps,
        string $appUrl,
        string $miniAppKey,
        bool $https = false,
    ): void {
        if ($miniAppArchive !== null) {
            $this->verifyCallback($commands, $appUrl);
        }

        $commands->exec('dnf install -y httpd');

        if ($miniAppArchive !== null) {
            $serverName = $domain ?: $serverIp;
            $this->validateServerName($serverName);
            foreach ($subDomains as $alias) {
                $this->validateServerName($alias);
            }

            $commands->exec('mkdir -p /var/mini/');
            $this->uploadOrFail($files, $miniAppArchive, '/var/mini/mini_app.zip');
            $commands->exec('unzip -o /var/mini/mini_app.zip -d /var/mini');
            $commands->exec('rm -rf /var/mini/mini_app.zip');
            $commands->exec('rm -rf /var/mini/.idea /var/mini/storage/framework/sessions/* /var/mini/storage/framework/cache/data/*');
            $appKey = 'base64:'.base64_encode(random_bytes(32));
            $scheme = $https ? 'https' : 'http';
            $this->uploadOrFail($files, "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={$appKey}\nAPP_URL={$scheme}://{$serverName}\n", '/var/mini/.env');
            $commands->exec('chmod 600 /var/mini/.env');
            $commands->exec('find /var/mini -type d -exec chmod 755 {} \;');
            $commands->exec('find /var/mini -type f -exec chmod 644 {} \;');
            $commands->exec('chmod -R 775 /var/mini/storage /var/mini/bootstrap/cache');
            $commands->exec('chown -R apache:apache /var/mini');

            $fileName = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace('.', '_', $serverName)) ?? '');
            $serverAlias = $subDomains === [] ? '' : 'ServerAlias '.implode(' ', $subDomains);
            $virtualHost = <<<CONFIG
<VirtualHost *:80>
    ServerName {$serverName}
    {$serverAlias}
    DocumentRoot "/var/mini/public"
    <Directory /var/mini/public>
        AllowOverride all
        Options Indexes FollowSymLinks ExecCGI
        Require all granted
    </Directory>
    ErrorLog "logs/{$fileName}-error.log"
    CustomLog "logs/{$fileName}-access.log" common
</VirtualHost>
CONFIG;
            $this->uploadOrFail($files, $virtualHost."\n", "/etc/httpd/conf.d/{$fileName}.conf");

            if ($cloudflareTrustedIps !== null) {
                $this->uploadOrFail($files, $cloudflareTrustedIps, '/etc/httpd/conf.d/remoteip.conf');
            }

            $helper = $files->download('/var/mini/app/Helper.php');
            if ($helper === false || ! str_contains($helper, '[IP]') || ! str_contains($helper, '[KEY]') || ! str_contains($helper, '[ID]')) {
                throw new RuntimeException('The tracking app helper is unavailable or does not contain the DMS placeholders.');
            }
            $configuredHelper = str_replace(
                ['[IP]', '[KEY]', '[ID]'],
                [$appUrl, $miniAppKey, (string) $serverId],
                $helper,
            );
            $this->uploadOrFail($files, $configuredHelper, '/var/mini/app/Helper.php');
            $commands->exec('cd /var/mini && php artisan optimize');
            $commands->exec('(crontab -l | grep \'mini\') || { crontab -l; echo "* * * * * cd /var/mini && php artisan schedule:run >> /dev/null 2>&1"; } | crontab -');
        }

        $commands->exec("grep -qxF 'ServerTokens Prod' /etc/httpd/conf/httpd.conf || echo ServerTokens Prod >> /etc/httpd/conf/httpd.conf;");
        $commands->exec("grep -qxF 'ServerSignature Off' /etc/httpd/conf/httpd.conf || echo ServerSignature Off >> /etc/httpd/conf/httpd.conf;");
        $commands->exec("grep -qxF 'TraceEnable off' /etc/httpd/conf/httpd.conf || echo TraceEnable off >> /etc/httpd/conf/httpd.conf;");
        $commands->exec("sed -i 's/AllowOverride None/AllowOverride All/g'  /etc/httpd/conf/httpd.conf;");
        $commands->exec('systemctl restart httpd');
        $commands->exec('systemctl enable httpd');
        if ($miniAppArchive !== null) {
            $localHealth = $commands->exec('curl -fsS --max-time 10 -H '.escapeshellarg('Host: '.$serverName).' http://127.0.0.1/ >/dev/null && printf ALPHALINK_TRACKING_READY');
            if (trim((string) $localHealth) !== 'ALPHALINK_TRACKING_READY') {
                throw new RuntimeException('The installed tracking site did not answer its local HTTP health check.');
            }
        }
    }

    public function verifyCallback(DmsInstallerRemoteCommandTransport $commands, string $appUrl): void
    {
        $callbackHealthUrl = rtrim($appUrl, '/').'/api/v1/health';
        $callbackHealth = $commands->exec('curl -fsS --max-time 10 '.escapeshellarg($callbackHealthUrl).' >/dev/null && printf ALPHALINK_CALLBACK_READY');
        if (trim((string) $callbackHealth) !== 'ALPHALINK_CALLBACK_READY') {
            throw new RuntimeException('The installed server cannot reach the AlphaLink tracking callback over HTTPS.');
        }
    }

    private function validateServerName(string $name): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9.:-]*\z/D', $name)) {
            throw new InvalidArgumentException('Tracking host names must be valid DNS names or IP addresses.');
        }
    }

    private function uploadOrFail(DmsInstallerRemoteFileTransport $files, string $contents, string $destination): void
    {
        if (! $files->upload($contents, $destination)) {
            throw new RuntimeException('The tracking app asset could not be uploaded.');
        }
    }
}
