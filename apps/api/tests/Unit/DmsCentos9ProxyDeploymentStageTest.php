<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9ProxyDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9ProxyDeploymentStageTest extends TestCase
{
    public function test_missing_squid_asset_skips_the_optional_stage(): void
    {
        $transport = $this->transport();

        $this->stage()->run($transport, $transport, null, 3128, [], [], [], 'proxy-user', 'synthetic-password');

        $this->assertSame([], $transport->events);
    }

    public function test_configures_proxy_lists_auth_and_service_in_dms_order(): void
    {
        $transport = $this->transport();

        $this->stage()->run(
            $transport,
            $transport,
            "http_port __PORT__\n",
            3128,
            ['203.0.113.10', '2001:db8::10'],
            ['198.51.100.20'],
            ['198.51.100.30'],
            'proxy-user',
            'synthetic-password',
        );

        $this->assertSame([
            ['type' => 'command', 'command' => 'dnf -y install squid;'],
            ['type' => 'upload', 'destination' => '/etc/squid/squid.conf', 'contents' => "http_port __PORT__\n"],
            ['type' => 'command', 'command' => "sed -i 's/__PORT__/3128/g' /etc/squid/squid.conf;"],
            ['type' => 'upload', 'destination' => '/etc/squid/ips.conf', 'contents' => "acl ip_0 myip 203.0.113.10\ntcp_outgoing_address 203.0.113.10 ip_0\nacl ip_1 myip 2001:db8::10\ntcp_outgoing_address 2001:db8::10 ip_1\n"],
            ['type' => 'upload', 'destination' => '/etc/squid/allowed_ips.conf', 'contents' => "198.51.100.20\n198.51.100.30"],
            ['type' => 'command', 'command' => 'touch /etc/squid/passwd && chown squid /etc/squid/passwd'],
            ['type' => 'upload', 'destination' => '/etc/squid/passwd', 'contents' => 'proxy-user:'.crypt('synthetic-password', base64_encode('synthetic-password'))],
            ['type' => 'upload', 'destination' => '/etc/squid/auth_settings.conf', 'contents' => "auth_param basic program /usr/lib64/squid/basic_ncsa_auth /etc/squid/passwd\nacl auth_users proxy_auth REQUIRED\nhttp_access allow auth_users"],
            ['type' => 'command', 'command' => 'chown squid /etc/squid/auth_settings.conf;'],
            ['type' => 'command', 'command' => 'systemctl restart squid;'],
            ['type' => 'command', 'command' => 'systemctl enable squid;'],
        ], $transport->events);

        $commands = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command');
        $this->assertStringNotContainsString('synthetic-password', implode("\n", $commands));
        $this->assertStringNotContainsString(crypt('synthetic-password', base64_encode('synthetic-password')), implode("\n", $commands));
    }

    public function test_auth_is_skipped_without_both_credentials_and_dms_wildcard_union_is_preserved(): void
    {
        $transport = $this->transport();

        $this->stage()->run(
            $transport,
            $transport,
            'squid config',
            8080,
            [],
            ['198.51.100.20'],
            [],
            'proxy-user',
            '',
        );

        $this->assertSame('0.0.0.0/0', $transport->events[4]['contents']);
        $this->assertSame(['type' => 'command', 'command' => 'touch /etc/squid/auth_settings.conf && chown squid /etc/squid/auth_settings.conf'], $transport->events[5]);
        $this->assertSame(['systemctl restart squid;', 'systemctl enable squid;'], array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command' && str_starts_with($event['command'], 'systemctl ')), 'command'));
        $this->assertNotContains('/etc/squid/passwd', array_column($transport->events, 'destination'));
    }

    private function stage(): DmsCentos9ProxyDeploymentStage
    {
        return new DmsCentos9ProxyDeploymentStage;
    }

    private function transport(): object
    {
        return new class implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport
        {
            /** @var list<array<string, string>> */
            public array $events = [];

            public function exec(string $command): bool|string
            {
                $this->events[] = ['type' => 'command', 'command' => $command];

                return '';
            }

            public function upload(string $contents, string $destination): bool
            {
                $this->events[] = ['type' => 'upload', 'destination' => $destination, 'contents' => $contents];

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $contents = file_get_contents($localPath);
                $this->events[] = ['type' => 'upload_file', 'destination' => $destination, 'contents' => is_string($contents) ? $contents : ''];

                return is_string($contents);
            }
        };
    }
}
