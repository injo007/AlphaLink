<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use App\Services\Infrastructure\DmsCentos9VmtaDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9VmtaDeploymentStageTest extends TestCase
{
    public function test_external_domain_uploads_vmtas_then_changes_ownership_and_restarts_pmta(): void
    {
        $transport = $this->transport();
        $stage = new DmsCentos9VmtaDeploymentStage(new DmsBulkInstallVmtaPlanner);
        $ips = [['ip' => '203.0.113.20', 'sub' => '', 'domain' => 'sender.example']];

        $stage->run($transport, $transport, false, $ips, ['gmail'], 'selector-one', false, '');

        $this->assertSame([
            ['type' => 'upload', 'destination' => '/etc/pmta/conf/vmta', 'contents' => "<virtual-mta mta-203.0.113.20>\n   smtp-source-ip 203.0.113.20\n   host-name sender.example\n</virtual-mta>\n".
                "<virtual-mta mta-203.0.113.20-gmail>\n   smtp-source-ip 203.0.113.20\n   host-name sender.example\n</virtual-mta>\n"],
            ['type' => 'command', 'command' => 'chown -R pmta:pmta /etc/pmta'],
            ['type' => 'command', 'command' => 'systemctl restart pmta;'],
            ['type' => 'command', 'command' => 'systemctl restart pmtahttp;'],
        ], $transport->events);
    }

    public function test_owned_domain_uploads_inbound_and_dkim_domains_only_after_the_key_upload_succeeds(): void
    {
        $transport = $this->transport();
        $stage = new DmsCentos9VmtaDeploymentStage(new DmsBulkInstallVmtaPlanner);
        $ips = [
            ['ip' => '203.0.113.20', 'sub' => '', 'domain' => 'sender.example'],
            ['ip' => '2001:db8::20', 'sub' => 'relay-two', 'domain' => 'sender.example'],
        ];

        $stage->run($transport, $transport, true, $ips, ['gmail'], 'selector-one', true, 'synthetic key bytes');

        $destinations = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'upload'), 'destination');
        $this->assertSame([
            '/etc/pmta/conf/vmta',
            '/etc/pmta/settings/inbound',
            '/etc/pmta/dkim',
            '/etc/pmta/conf/dkim',
        ], $destinations);

        $dkimUpload = $transport->events[3];
        $this->assertSame(
            "domain-key selector-one,sender.example,/etc/pmta/dkim\n".
            "domain-key selector-one,relay-two.sender.example,/etc/pmta/dkim\n",
            $dkimUpload['contents'],
        );
        $this->assertSame('synthetic key bytes', $transport->events[2]['contents']);
        $this->assertSame('systemctl restart pmtahttp;', $transport->events[array_key_last($transport->events)]['command']);
    }

    public function test_failed_upload_results_do_not_suppress_dms_post_upload_commands(): void
    {
        $transport = $this->transport();
        $transport->uploadResults['/etc/pmta/conf/vmta'] = false;
        $transport->uploadResults['/etc/pmta/dkim'] = false;
        $stage = new DmsCentos9VmtaDeploymentStage(new DmsBulkInstallVmtaPlanner);

        $stage->run(
            $transport,
            $transport,
            true,
            [['ip' => '203.0.113.20', 'sub' => '', 'domain' => 'sender.example']],
            [],
            'selector-one',
            true,
            'synthetic key bytes',
        );

        $destinations = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'upload'), 'destination');
        $this->assertSame(['/etc/pmta/conf/vmta', '/etc/pmta/settings/inbound', '/etc/pmta/dkim'], $destinations);
        $this->assertSame([
            'chown -R pmta:pmta /etc/pmta',
            'systemctl restart pmta;',
            'systemctl restart pmtahttp;',
        ], array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command'));
    }

    private function transport(): object
    {
        return new class implements DmsInstallerRemoteCommandTransport, DmsInstallerRemoteFileTransport
        {
            /** @var list<array<string, string>> */
            public array $events = [];

            /** @var array<string, bool> */
            public array $uploadResults = [];

            public function exec(string $command): bool|string
            {
                $this->events[] = ['type' => 'command', 'command' => $command];

                return '';
            }

            public function upload(string $contents, string $destination): bool
            {
                $this->events[] = ['type' => 'upload', 'destination' => $destination, 'contents' => $contents];

                return $this->uploadResults[$destination] ?? true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $contents = file_get_contents($localPath);
                $this->events[] = ['type' => 'upload_file', 'destination' => $destination, 'contents' => is_string($contents) ? $contents : ''];

                return is_string($contents) && ($this->uploadResults[$destination] ?? true);
            }
        };
    }
}
