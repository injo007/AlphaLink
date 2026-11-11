<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;
use RuntimeException;

/** Deploys AlphaLink's standalone tracking endpoint and Apache routes to a CentOS 9-family host. */
class AlphaLinkCentos9TrackingDeploymentStage
{
    /** @param list<string> $subDomains
     * @param  array{index: string, handler: string}|null  $trackingAppFiles
     */
    public function run(
        AlphaLinkInstallerRemoteCommandTransport $commands,
        AlphaLinkInstallerRemoteFileTransport&AlphaLinkInstallerRemoteReadableFileTransport $files,
        string $serverIp,
        int|string $serverId,
        ?string $domain,
        array $subDomains,
        ?array $trackingAppFiles,
        ?string $cloudflareTrustedIps,
        string $appUrl,
        string $trackingIngestKey,
        bool $https = false,
        string $applicationName = 'AlphaLink',
    ): void {
        if ($trackingAppFiles !== null) {
            $this->verifyCallback($commands, $appUrl);
        }

        $commands->exec('dnf install -y httpd');

        if ($trackingAppFiles !== null) {
            if (! isset($trackingAppFiles['index'], $trackingAppFiles['handler'])) {
                throw new InvalidArgumentException('The AlphaLink tracking source files are incomplete.');
            }

            $serverName = $domain ?: $serverIp;
            $this->validateServerName($serverName);
            foreach ($subDomains as $alias) {
                $this->validateServerName($alias);
            }
            if (preg_match('/[\r\n]/', $applicationName) === 1 || trim($applicationName) === '') {
                throw new InvalidArgumentException('The tracking application name must be a non-empty single-line value.');
            }
            if ($trackingIngestKey === '') {
                throw new InvalidArgumentException('The AlphaLink tracking ingest key is required.');
            }

            $commands->exec(
                'test ! -L /var/alphalink-tracker && mkdir -p /var/alphalink-tracker '
                    .'&& test ! -L /var/alphalink-tracker/TrackerRequestHandler.php '
                    .'&& test ! -L /var/mini && mkdir -p /var/mini/public '
                    .'&& test ! -L /var/mini/public '
                    .'&& test ! -L /var/mini/public/index.php '
                    .'&& test ! -L /etc/alphalink-tracker.php',
            );
            $this->uploadOrFail($files, $trackingAppFiles['handler'], '/var/alphalink-tracker/TrackerRequestHandler.php');
            $this->uploadOrFail($files, $trackingAppFiles['index'], '/var/mini/public/index.php');

            $config = "<?php\nreturn ".var_export([
                'api_url' => rtrim($appUrl, '/'),
                'ingest_key' => $trackingIngestKey,
                'application_name' => $applicationName,
                'server_id' => (string) $serverId,
            ], true).";\n";
            $this->uploadOrFail($files, $config, '/etc/alphalink-tracker.php');
            $publicAccessPolicy = <<<'APACHE'
<Directory /var/mini/public>
    AllowOverride None
    Require all granted
    <FilesMatch "(?i)^(?!index\.php$).*\.(php[0-9]?|phtml|phar)$">
        Require all denied
    </FilesMatch>
</Directory>
APACHE;
            $this->uploadOrFail($files, $publicAccessPolicy, '/etc/httpd/conf.d/alphalink-tracking-access.conf');
            $commands->exec('chown root:root /var/alphalink-tracker/TrackerRequestHandler.php /var/mini/public/index.php');
            $commands->exec('chmod 755 /var/alphalink-tracker');
            $commands->exec('chmod 644 /var/alphalink-tracker/TrackerRequestHandler.php /var/mini/public/index.php');
            $commands->exec('chown root:apache /etc/alphalink-tracker.php');
            $commands->exec('chmod 640 /etc/alphalink-tracker.php');

            $fileName = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace('.', '_', $serverName)) ?? '');
            $serverAliases = array_values(array_unique([$serverIp, ...$subDomains]));
            $serverAlias = 'ServerAlias '.implode(' ', $serverAliases);
            $virtualHost = <<<CONFIG
<VirtualHost *:80>
    ServerName {$serverName}
    {$serverAlias}
    RewriteEngine On
    RewriteRule ^/healthz$ /index.php [END,QSA]
    RewriteRule ^/([A-Za-z0-9]{10}[roub][A-Za-z0-9_-]{1,4096}(?:/[A-Za-z0-9_-]+)*)$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]
    DocumentRoot "/var/mini/public"
    <Directory /var/mini/public>
        AllowOverride None
        Options -Indexes -ExecCGI
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

            $rewriteModule = $commands->exec(
                'httpd -M 2>/dev/null | grep -q rewrite_module || { test -f /etc/httpd/modules/mod_rewrite.so && printf \'%s\\n\' \'LoadModule rewrite_module modules/mod_rewrite.so\' > /etc/httpd/conf.modules.d/00-alphalink-rewrite.conf; } && httpd -t >/dev/null 2>&1 && printf ALPHALINK_REWRITE_READY',
            );
            if (trim((string) $rewriteModule) !== 'ALPHALINK_REWRITE_READY') {
                throw new RuntimeException('Apache mod_rewrite could not be enabled and validated for tracking links.');
            }
        }

        $commands->exec("grep -qxF 'ServerTokens Prod' /etc/httpd/conf/httpd.conf || echo ServerTokens Prod >> /etc/httpd/conf/httpd.conf;");
        $commands->exec("grep -qxF 'ServerSignature Off' /etc/httpd/conf/httpd.conf || echo ServerSignature Off >> /etc/httpd/conf/httpd.conf;");
        $commands->exec("grep -qxF 'TraceEnable off' /etc/httpd/conf/httpd.conf || echo TraceEnable off >> /etc/httpd/conf/httpd.conf;");
        $commands->exec('systemctl restart httpd');
        $commands->exec('systemctl enable httpd');

        if ($trackingAppFiles !== null) {
            $localHealth = $commands->exec(
                'curl -fsS --max-time 10 -H '.escapeshellarg('Host: '.$serverName)
                    .' '.escapeshellarg('http://127.0.0.1/healthz').' | grep -Fxq ALPHALINK_TRACKING_READY && printf ALPHALINK_TRACKING_READY',
            );
            if (trim((string) $localHealth) !== 'ALPHALINK_TRACKING_READY') {
                throw new RuntimeException('The installed AlphaLink tracking site did not answer its local health check.');
            }

            $this->verifyRoute($commands, $serverName, 'ALPHALINK_TRACKING_ROUTE_READY');
            $this->verifyRoute($commands, $serverIp, 'ALPHALINK_TRACKING_IP_ROUTE_READY');

            $longRouteUrl = 'http://127.0.0.1/ALPHALINKTRACK10r'.str_repeat('A', 300).'?t=1';
            $longRouteHealth = $commands->exec(
                'response=$(curl -sS --max-time 10 -D - -o /dev/null -w \'\\nALPHALINK_STATUS:%{http_code}\\n\' -H '
                    .escapeshellarg('Host: '.$serverName).' '.escapeshellarg($longRouteUrl).') && '
                    .'status=$(printf \'%s\\n\' "$response" | sed -n \'s/^ALPHALINK_STATUS://p\') && '
                    .'test "$status" = 302 && '
                    .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('Location: https://www.google.com').' && '
                    .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('X-AlphaLink-Tracking: active').' && '
                    .'printf ALPHALINK_TRACKING_LONG_ROUTE_READY',
            );
            if (trim((string) $longRouteHealth) !== 'ALPHALINK_TRACKING_LONG_ROUTE_READY') {
                throw new RuntimeException('Apache did not route a long tracking URL to the AlphaLink application.');
            }
        }
    }

    public function verifyCallback(AlphaLinkInstallerRemoteCommandTransport $commands, string $appUrl): void
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

    private function uploadOrFail(AlphaLinkInstallerRemoteFileTransport $files, string $contents, string $destination): void
    {
        if (! $files->upload($contents, $destination)) {
            throw new RuntimeException('The AlphaLink tracking asset could not be uploaded.');
        }
    }

    private function verifyRoute(AlphaLinkInstallerRemoteCommandTransport $commands, string $host, string $successMarker): void
    {
        $routeHealth = $commands->exec(
            'response=$(curl -sS --max-time 10 -D - -o /dev/null -w \'\\nALPHALINK_STATUS:%{http_code}\\n\' -H '
                .escapeshellarg('Host: '.$host).' '.escapeshellarg('http://127.0.0.1/ALPHALINKTRACK10rprobe').') && '
                .'status=$(printf \'%s\\n\' "$response" | sed -n \'s/^ALPHALINK_STATUS://p\') && '
                .'test "$status" = 302 && '
                .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('Location: https://www.google.com').' && '
                .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('X-AlphaLink-Tracking: active').' && '
                .'printf '.escapeshellarg($successMarker),
        );
        if (trim((string) $routeHealth) !== $successMarker) {
            throw new RuntimeException('Apache did not route the tracking probe through the AlphaLink application.');
        }
    }
}
