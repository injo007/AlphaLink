<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9HttpsDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9HttpsDeploymentStageTest extends TestCase
{
    public function test_missing_domain_skips_the_https_feature(): void
    {
        $commands = $this->commands();

        (new DmsCentos9HttpsDeploymentStage)->run($commands, null, ['relay.example.test']);

        $this->assertSame([], $commands->commands);
    }

    public function test_installs_certbot_requests_certificate_for_domain_and_aliases_then_adds_renewal_cron(): void
    {
        $commands = $this->commands();

        (new DmsCentos9HttpsDeploymentStage)->run($commands, 'sender.example.test', ['relay.example.test', 'mail.example.test']);

        $this->assertSame([
            'rpm -qa | grep -qw httpd || dnf -y install httpd;',
            'systemctl enable --now httpd',
            'rpm -qa | grep -qw certbot || dnf -y install certbot;',
            'rpm -qa | grep -qw python3-certbot-apache || dnf -y install python3-certbot-apache;',
            "certbot --apache --no-redirect -d 'sender.example.test,relay.example.test,mail.example.test' -m 'cert@sender.example.test' -n --agree-tos",
            "(crontab -l | grep 'certbot') || { crontab -l; echo '0 0 */15 * * certbot renew'; } | crontab -",
        ], $commands->commands);
    }

    public function test_domain_values_are_shell_quoted_in_the_certbot_request(): void
    {
        $commands = $this->commands();
        $domain = 'sender.test; touch /tmp/synthetic';

        (new DmsCentos9HttpsDeploymentStage)->run($commands, $domain, []);

        $this->assertSame(
            'certbot --apache --no-redirect -d '.escapeshellarg($domain).' -m '.escapeshellarg('cert@'.$domain).' -n --agree-tos',
            $commands->commands[4],
        );
    }

    public function test_pmta_monitor_uses_the_domain_certificate_and_keeps_an_http_rollback(): void
    {
        $commands = $this->commands();
        $files = new class implements DmsInstallerRemoteFileTransport
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

        $httpsEnabled = (new DmsCentos9HttpsDeploymentStage)->configurePmtaMonitor(
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
        $files = new class implements DmsInstallerRemoteFileTransport
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

        $httpsEnabled = (new DmsCentos9HttpsDeploymentStage)->configurePmtaMonitor(
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

    public function test_tls_proxy_failure_falls_back_to_validated_ip_http_proxy(): void
    {
        $commands = new class implements DmsInstallerRemoteCommandTransport
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
        $files = new class implements DmsInstallerRemoteFileTransport
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

        $httpsEnabled = (new DmsCentos9HttpsDeploymentStage)->configurePmtaMonitor(
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
        return new class implements DmsInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return str_contains($command, 'ALPHALINK_PMTA_PROXY_READY') ? 'ALPHALINK_PMTA_PROXY_READY' : '';
            }
        };
    }
}
