<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkCentos9HttpsDeploymentStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteFileTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AlphaLinkCentos9HttpsDeploymentStageTest extends TestCase
{
    public function test_missing_domain_skips_the_https_feature(): void
    {
        $commands = $this->commands();

        (new AlphaLinkCentos9HttpsDeploymentStage)->run($commands, null, ['relay.example.test']);

        $this->assertSame([], $commands->commands);
    }

    public function test_installs_certbot_requests_certificate_for_domain_and_aliases_then_adds_renewal_cron(): void
    {
        $commands = $this->commands();

        $certificateReady = (new AlphaLinkCentos9HttpsDeploymentStage)->run($commands, 'sender.example.test', ['relay.example.test', 'mail.example.test']);

        $this->assertSame([
            '(rpm -q httpd >/dev/null 2>&1 || dnf -y install httpd >/dev/null 2>&1) && (rpm -q mod_ssl >/dev/null 2>&1 || dnf -y install mod_ssl >/dev/null 2>&1) && systemctl enable --now httpd && httpd -t >/dev/null 2>&1 && printf ALPHALINK_APACHE_HTTPS_READY',
            '(rpm -q certbot >/dev/null 2>&1 || dnf -y install certbot >/dev/null 2>&1) && (rpm -q python3-certbot-apache >/dev/null 2>&1 || dnf -y install python3-certbot-apache >/dev/null 2>&1) && printf ALPHALINK_CERTBOT_READY',
            "certbot --apache --no-redirect -d 'sender.example.test,relay.example.test,mail.example.test' -m 'cert@sender.example.test' -n --agree-tos >/dev/null 2>&1 && printf ALPHALINK_CERTIFICATE_REQUESTED",
            "test -s '/etc/letsencrypt/live/sender.example.test/fullchain.pem' && test -s '/etc/letsencrypt/live/sender.example.test/privkey.pem' && printf ALPHALINK_DOMAIN_CERTIFICATE_READY",
            "(crontab -l | grep 'certbot') || { crontab -l; echo '0 0 */15 * * certbot renew'; } | crontab -",
        ], array_slice($commands->commands, 0, 5));
        $this->assertTrue($certificateReady);
        $this->assertCount(6, $commands->commands);
        $this->assertStringContainsString('mod_ssl', $commands->commands[0]);
        $this->assertStringContainsString('ALPHALINK_TRACKING_READY', $commands->commands[5]);
        $this->assertStringContainsString('ALPHALINK_TRACKING_ROUTES', $commands->commands[5]);
        $this->assertStringContainsString('RewriteRule ^/healthz$ /index.php [END,QSA]', $commands->commands[5]);
        $this->assertStringContainsString('RewriteRule ^/([A-Za-z0-9]{10}[roubROUB][A-Za-z0-9_-]{1,4096}(?:/[A-Za-z0-9_-]+)*)$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]', $commands->commands[5]);
        $this->assertStringContainsString('sender.example.test-le-ssl.conf', $commands->commands[5]);
    }

    public function test_invalid_certificate_domains_are_rejected_before_remote_commands(): void
    {
        $commands = $this->commands();
        $domain = 'sender.test;touch';

        try {
            (new AlphaLinkCentos9HttpsDeploymentStage)->run($commands, $domain, []);
            $this->fail('A non-DNS certificate name must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The certificate domain must be a valid DNS hostname.', $exception->getMessage());
        }

        $this->assertSame([], $commands->commands);
    }

    public function test_apache_tls_prerequisite_failure_stops_before_certbot(): void
    {
        $commands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return '';
            }
        };

        $certificateReady = (new AlphaLinkCentos9HttpsDeploymentStage)->run($commands, 'sender.example.test');

        $this->assertFalse($certificateReady);
        $this->assertCount(1, $commands->commands);
        $this->assertStringContainsString('mod_ssl', $commands->commands[0]);
    }

    public function test_certbot_failure_does_not_report_domain_tls_ready(): void
    {
        $commands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;
                foreach (['ALPHALINK_APACHE_HTTPS_READY', 'ALPHALINK_CERTBOT_READY'] as $marker) {
                    if (str_contains($command, $marker)) {
                        return $marker;
                    }
                }

                return '';
            }
        };

        $certificateReady = (new AlphaLinkCentos9HttpsDeploymentStage)->run($commands, 'sender.example.test');

        $this->assertFalse($certificateReady);
        $this->assertCount(3, $commands->commands);
        $this->assertStringContainsString('ALPHALINK_CERTIFICATE_REQUESTED', $commands->commands[2]);
    }

    public function test_pmta_monitor_uses_the_domain_certificate_and_keeps_an_http_rollback(): void
    {
        $commands = $this->commands();
        $files = new class implements AlphaLinkInstallerRemoteFileTransport
        {
            public array $uploads = [];

            public function upload(string $contents, string $destination): bool
            {
                $this->uploads[$destination] = $contents;

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                return false;
            }
        };

        $httpsEnabled = (new AlphaLinkCentos9HttpsDeploymentStage)->configurePmtaMonitor(
            $commands,
            $files,
            'sender.example.test',
            45,
            1213,
            ['192.0.2.10'],
            [],
            false,
            ['198.51.100.44'],
        );

        $this->assertTrue($httpsEnabled);
        $configuration = $files->uploads['/etc/nginx/conf.d/default.conf.alphalink-tls-stage'];
        $this->assertStringContainsString('listen 1213 ssl;', $configuration);
        $this->assertStringContainsString('server_name sender.example.test;', $configuration);
        $this->assertStringContainsString('/etc/letsencrypt/live/sender.example.test/fullchain.pem', $configuration);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:2426;', $configuration);
        $this->assertStringContainsString('allow 198.51.100.44;', $configuration);
        $this->assertStringContainsString("'/etc/nginx/conf.d/default.conf.alphalink-tls-stage'", implode("\n", $commands->commands));
        $this->assertStringContainsString('default.conf.alphalink-http-backup', implode("\n", $commands->commands));
    }

    public function test_pmta_monitor_can_return_to_http_without_installing_a_certificate_hook(): void
    {
        $commands = $this->commands();
        $files = new class implements AlphaLinkInstallerRemoteFileTransport
        {
            public array $uploads = [];

            public function upload(string $contents, string $destination): bool
            {
                $this->uploads[$destination] = $contents;

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                return false;
            }
        };

        $httpsEnabled = (new AlphaLinkCentos9HttpsDeploymentStage)->configurePmtaMonitor(
            $commands,
            $files,
            null,
            45,
            1213,
            [],
            [],
            true,
            ['198.51.100.44'],
        );

        $this->assertFalse($httpsEnabled);
        $configuration = $files->uploads['/etc/nginx/conf.d/default.conf.alphalink-tls-stage'];
        $this->assertStringContainsString('listen 1213;', $configuration);
        $this->assertStringNotContainsString('listen 1213 ssl;', $configuration);
        $this->assertStringContainsString('server_name _;', $configuration);
        $this->assertStringStartsWith("cp -p '/etc/nginx/conf.d/default.conf'", $commands->commands[0]);
        $this->assertCount(1, $commands->commands);
    }

    public function test_ip_fallback_leaves_the_public_port_to_pmta_instead_of_adding_an_nginx_proxy(): void
    {
        $commands = $this->commands();
        $files = new class implements AlphaLinkInstallerRemoteFileTransport
        {
            /** @var list<string> */
            public array $uploads = [];

            public function upload(string $contents, string $destination): bool
            {
                $this->uploads[] = $contents;

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                return false;
            }
        };

        $httpsEnabled = (new AlphaLinkCentos9HttpsDeploymentStage)->configurePmtaMonitor(
            $commands,
            $files,
            null,
            45,
            1213,
            ['192.0.2.10'],
            [],
            false,
            ['198.51.100.44'],
            true,
        );

        $this->assertFalse($httpsEnabled);
        $this->assertSame([], $commands->commands);
        $this->assertSame([], $files->uploads);
    }

    public function test_tls_proxy_failure_falls_back_to_validated_ip_http_proxy(): void
    {
        $commands = new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;
                if (str_contains($command, 'ALPHALINK_PMTA_PROXY_READY')) {
                    return str_contains($command, 'fullchain.pem') ? '' : 'ALPHALINK_PMTA_PROXY_READY';
                }

                return '';
            }
        };
        $files = new class implements AlphaLinkInstallerRemoteFileTransport
        {
            /** @var list<string> */
            public array $uploads = [];

            public function upload(string $contents, string $destination): bool
            {
                $this->uploads[] = $contents;

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                return false;
            }
        };

        $httpsEnabled = (new AlphaLinkCentos9HttpsDeploymentStage)->configurePmtaMonitor(
            $commands,
            $files,
            'sender.example.test',
            45,
            1213,
            [],
            [],
            true,
            ['198.51.100.44'],
        );

        $this->assertFalse($httpsEnabled);
        $this->assertCount(2, $files->uploads);
        $this->assertStringContainsString('listen 1213 ssl;', $files->uploads[0]);
        $this->assertStringContainsString('listen 1213;', $files->uploads[1]);
        $this->assertStringNotContainsString('listen 1213 ssl;', $files->uploads[1]);
        $this->assertCount(3, $commands->commands);
    }

    private function commands(): object
    {
        return new class implements AlphaLinkInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                if (str_contains($command, 'ALPHALINK_APACHE_HTTPS_READY')) {
                    return 'ALPHALINK_APACHE_HTTPS_READY';
                }
                if (str_contains($command, 'ALPHALINK_CERTBOT_READY')) {
                    return 'ALPHALINK_CERTBOT_READY';
                }
                if (str_contains($command, 'ALPHALINK_CERTIFICATE_REQUESTED')) {
                    return 'ALPHALINK_CERTIFICATE_REQUESTED';
                }
                if (str_contains($command, 'ALPHALINK_PMTA_PROXY_READY')) {
                    return 'ALPHALINK_PMTA_PROXY_READY';
                }
                if (str_contains($command, 'ALPHALINK_DOMAIN_CERTIFICATE_READY')) {
                    return 'ALPHALINK_DOMAIN_CERTIFICATE_READY';
                }

                return '';
            }
        };
    }
}
