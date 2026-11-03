<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Reconstructs DMS Centos9::setup_tracking() behind isolated transports. */
class AlphaLinkCentos9TrackingDeploymentStage
{
    /** @param list<string> $subDomains */
    public function run(
        AlphaLinkInstallerRemoteCommandTransport $commands,
        AlphaLinkInstallerRemoteFileTransport&AlphaLinkInstallerRemoteReadableFileTransport $files,
        string $serverIp,
        int|string $serverId,
        ?string $domain,
        array $subDomains,
        ?string $miniAppArchive,
        ?string $cloudflareTrustedIps,
        string $appUrl,
        string $miniAppKey,
        bool $https = false,
        string $applicationName = 'AlphaLink',
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
            if (preg_match('/[\r\n]/', $applicationName) === 1 || trim($applicationName) === '') {
                throw new InvalidArgumentException('The tracking application name must be a non-empty single-line value.');
            }
            $encodedApplicationName = json_encode($applicationName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $sessionCookie = Str::slug($applicationName).'-session';
            $this->uploadOrFail(
                $files,
                "APP_NAME={$encodedApplicationName}\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={$appKey}\nAPP_URL={$scheme}://{$serverName}\nSESSION_COOKIE={$sessionCookie}\n",
                '/var/mini/.env',
            );
            $commands->exec('chmod 600 /var/mini/.env');
            $commands->exec('find /var/mini -type d -exec chmod 755 {} \;');
            $commands->exec('find /var/mini -type f -exec chmod 644 {} \;');
            $commands->exec('chmod -R 775 /var/mini/storage /var/mini/bootstrap/cache');
            $commands->exec('chown -R apache:apache /var/mini');

            $fileName = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace('.', '_', $serverName)) ?? '');
            $serverAliases = array_values(array_unique([$serverIp, ...$subDomains]));
            $serverAlias = 'ServerAlias '.implode(' ', $serverAliases);
            $virtualHost = <<<CONFIG
<VirtualHost *:80>
    ServerName {$serverName}
    {$serverAlias}
    RewriteEngine On
    RewriteRule ^/(.{255,})$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]
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
            if (
                $helper === false
                || ! str_contains($helper, '[IP]')
                || ! str_contains($helper, '[KEY]')
                || ! str_contains($helper, '[ID]')
                || ! str_contains($helper, 'base64_decode($token, true)')
            ) {
                throw new RuntimeException('The tracking app helper is unavailable or does not contain the required placeholders.');
            }
            $helper = str_replace(
                'base64_decode($token, true)',
                "base64_decode(strtr(\$token, '-_', '+/'), true)",
                $helper,
            );
            $configuredHelper = str_replace(
                ['[IP]', '[KEY]', '[ID]'],
                [$appUrl, $miniAppKey, (string) $serverId],
                $helper,
            );
            $this->uploadOrFail($files, $configuredHelper, '/var/mini/app/Helper.php');
            foreach (['RedirectController.php', 'RedirectDropController.php'] as $controllerFile) {
                $controllerPath = '/var/mini/app/Http/Controllers/'.$controllerFile;
                $controller = $files->download($controllerPath);
                if ($controller === false) {
                    throw new RuntimeException('The tracking redirect controller is unavailable.');
                }

                $controller = $this->appendOptionalTokenSegments($controller);
                $this->uploadOrFail($files, $controller, $controllerPath);
            }
            $frontController = $files->download('/var/mini/public/index.php');
            if ($frontController === false) {
                throw new RuntimeException('The tracking front controller is unavailable.');
            }
            $frontController = $this->preserveLongTrackingRequestUri($frontController);
            $this->uploadOrFail($files, $frontController, '/var/mini/public/index.php');
            $commands->exec('cd /var/mini && php artisan optimize');
            $commands->exec('(crontab -l | grep \'mini\') || { crontab -l; echo "* * * * * cd /var/mini && php artisan schedule:run >> /dev/null 2>&1"; } | crontab -');

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
        $commands->exec("sed -i 's/AllowOverride None/AllowOverride All/g'  /etc/httpd/conf/httpd.conf;");
        $commands->exec('systemctl restart httpd');
        $commands->exec('systemctl enable httpd');
        if ($miniAppArchive !== null) {
            $localHealth = $commands->exec('curl -fsS --max-time 10 -H '.escapeshellarg('Host: '.$serverName).' http://127.0.0.1/ >/dev/null && printf ALPHALINK_TRACKING_READY');
            if (trim((string) $localHealth) !== 'ALPHALINK_TRACKING_READY') {
                throw new RuntimeException('The installed tracking site did not answer its local HTTP health check.');
            }

            $routeHealth = $commands->exec(
                'test -s /var/mini/public/.htaccess && grep -Eq \'RewriteRule.*index\\.php\' /var/mini/public/.htaccess && '
                    .'response=$(curl -sS --max-time 10 -D - -o /dev/null -w \'\\nALPHALINK_STATUS:%{http_code}\\n\' -H '.escapeshellarg('Host: '.$serverName).' '.escapeshellarg('http://127.0.0.1/ALPHALINKTRACK10rprobe').') && '
                    .'status=$(printf \'%s\\n\' "$response" | sed -n \'s/^ALPHALINK_STATUS://p\') && '
                    .'case "$status" in 2??|3??|4??) ;; *) exit 1 ;; esac && '
                    .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('Set-Cookie: '.$sessionCookie.'=').' && printf ALPHALINK_TRACKING_ROUTE_READY',
            );
            if (trim((string) $routeHealth) !== 'ALPHALINK_TRACKING_ROUTE_READY') {
                throw new RuntimeException('Apache did not route the tracking probe through the Laravel app.');
            }

            $ipRouteHealth = $commands->exec(
                'response=$(curl -sS --max-time 10 -D - -o /dev/null -w \'\\nALPHALINK_STATUS:%{http_code}\\n\' -H '.escapeshellarg('Host: '.$serverIp).' '.escapeshellarg('http://127.0.0.1/ALPHALINKTRACK10rprobe').') && '
                    .'status=$(printf \'%s\\n\' "$response" | sed -n \'s/^ALPHALINK_STATUS://p\') && '
                    .'case "$status" in 2??|3??|4??) ;; *) exit 1 ;; esac && '
                    .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('Set-Cookie: '.$sessionCookie.'=').' && printf ALPHALINK_TRACKING_IP_ROUTE_READY',
            );
            if (trim((string) $ipRouteHealth) !== 'ALPHALINK_TRACKING_IP_ROUTE_READY') {
                throw new RuntimeException('Apache did not route the public-IP tracking fallback through the Laravel app.');
            }

            $longRouteUrl = 'http://127.0.0.1/ALPHALINKTRACK10r'.str_repeat('A', 300).'?t=1';
            $longRouteHealth = $commands->exec(
                'response=$(curl -sS --max-time 10 -D - -o /dev/null -w \'\\nALPHALINK_STATUS:%{http_code}\\n\' -H '.escapeshellarg('Host: '.$serverName).' '.escapeshellarg($longRouteUrl).') && '
                    .'status=$(printf \'%s\\n\' "$response" | sed -n \'s/^ALPHALINK_STATUS://p\') && '
                    .'test "$status" = 302 && '
                    .'printf \'%s\\n\' "$response" | grep -Fqi '.escapeshellarg('Location: https://google.com/?q=PE').' && printf ALPHALINK_TRACKING_LONG_ROUTE_READY',
            );
            if (trim((string) $longRouteHealth) !== 'ALPHALINK_TRACKING_LONG_ROUTE_READY') {
                throw new RuntimeException('Apache did not route a long tracking URL to the Laravel app.');
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
            throw new RuntimeException('The tracking app asset could not be uploaded.');
        }
    }

    private function appendOptionalTokenSegments(string $controller): string
    {
        $marker = "\$token .= str_replace('/', '', (string) \$option);";
        $existingCount = substr_count($controller, $marker);
        if ($existingCount === 4) {
            return $controller;
        }
        if ($existingCount !== 0) {
            throw new RuntimeException('The tracking redirect controller has a partial token-route patch.');
        }

        $pattern = '/public function (?:redirect|unsubscribe|open|bot)\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{/s';
        $updated = preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match[0]."\n        \$token .= str_replace('/', '', (string) \$option);",
            $controller,
            -1,
            $count,
        );
        if (! is_string($updated) || $count !== 4) {
            throw new RuntimeException('The tracking redirect controller does not expose all token routes.');
        }

        return $updated;
    }

    private function preserveLongTrackingRequestUri(string $frontController): string
    {
        $patch = "if (isset(\$_GET['__alphalink_long_uri']) && is_string(\$_GET['__alphalink_long_uri'])) {\n"
            ."    \$_SERVER['REQUEST_URI'] = '/'.ltrim(\$_GET['__alphalink_long_uri'], '/');\n"
            ."    unset(\$_GET['__alphalink_long_uri']);\n"
            ."}\n";
        $marker = "\$_SERVER['REQUEST_URI'] = '/'.ltrim(\$_GET['__alphalink_long_uri'], '/');";
        $existingCount = substr_count($frontController, $marker);
        if ($existingCount === 1) {
            return $frontController;
        }
        if ($existingCount !== 0) {
            throw new RuntimeException('The tracking front controller has a partial long-token patch.');
        }

        $needle = "define('LARAVEL_START', microtime(true));";
        if (! str_contains($frontController, $needle)) {
            throw new RuntimeException('The tracking front controller does not match the supported Laravel entry point.');
        }

        return str_replace($needle, $needle."\n\n".$patch, $frontController);
    }
}
