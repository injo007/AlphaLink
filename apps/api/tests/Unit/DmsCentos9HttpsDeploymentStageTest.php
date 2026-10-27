<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9HttpsDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
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
            $commands->commands[2],
        );
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

                return '';
            }
        };
    }
}
