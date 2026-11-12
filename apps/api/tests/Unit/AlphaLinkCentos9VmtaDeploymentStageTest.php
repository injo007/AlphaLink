<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallVmtaPlanner;
use App\Services\Infrastructure\AlphaLinkCentos9VmtaDeploymentStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteFileTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkCentos9VmtaDeploymentStageTest extends TestCase
{
    public function test_external_domain_uploads_vmtas_then_changes_ownership_and_restarts_pmta(): void
    {
        $transport = $this->transport();
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);
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
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);
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

    public function test_failed_vmta_upload_stops_before_restarting_pmta(): void
    {
        $transport = $this->transport();
        $transport->uploadResults['/etc/pmta/conf/vmta'] = false;
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PMTA Virtual MTA configuration could not be uploaded.');

        $stage->run(
            $transport,
            $transport,
            false,
            [['ip' => '203.0.113.20', 'sub' => '', 'domain' => 'sender.example']],
            [],
            'selector-one',
            false,
            '',
        );
    }

    public function test_failed_dkim_key_upload_stops_before_restarting_pmta(): void
    {
        $transport = $this->transport();
        $transport->uploadResults['/etc/pmta/dkim'] = false;
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);

        try {
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
            $this->fail('A failed DKIM key upload must stop the reinstall.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The selected DKIM private key could not be uploaded to PMTA.', $exception->getMessage());
        }

        $commands = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command');
        $this->assertSame([], $commands);
        $destinations = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'upload'), 'destination');
        $this->assertSame(['/etc/pmta/conf/vmta', '/etc/pmta/settings/inbound', '/etc/pmta/dkim'], $destinations);
    }

    public function test_failed_dkim_domains_upload_stops_before_restarting_pmta(): void
    {
        $transport = $this->transport();
        $transport->uploadResults['/etc/pmta/conf/dkim'] = false;
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);

        try {
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
            $this->fail('A failed DKIM domains upload must stop the reinstall.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The selected PMTA DKIM domain configuration could not be uploaded.', $exception->getMessage());
        }

        $commands = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command');
        $this->assertSame([], $commands);
    }

    public function test_invalid_uploaded_dkim_key_or_selector_stops_before_restarting_pmta(): void
    {
        $transport = $this->transport();
        $transport->dkimReady = false;
        $stage = new AlphaLinkCentos9VmtaDeploymentStage(new AlphaLinkBulkInstallVmtaPlanner);

        try {
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
            $this->fail('A failed remote DKIM verification must stop the reinstall.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PMTA did not verify the selected DKIM key and domain configuration.', $exception->getMessage());
        }

        $commands = array_column(array_filter($transport->events, fn (array $event): bool => $event['type'] === 'command'), 'command');
        $this->assertCount(1, $commands);
        $this->assertStringContainsString('openssl pkey', $commands[0]);
    }

    private function transport(): object
    {
        return new class implements AlphaLinkInstallerRemoteCommandTransport, AlphaLinkInstallerRemoteFileTransport
        {
            /** @var list<array<string, string>> */
            public array $events = [];

            /** @var array<string, bool> */
            public array $uploadResults = [];

            public bool $dkimReady = true;

            public function exec(string $command): bool|string
            {
                $this->events[] = ['type' => 'command', 'command' => $command];

                return $this->dkimReady && str_contains($command, 'ALPHALINK_DKIM_READY')
                    ? 'ALPHALINK_DKIM_READY'
                    : '';
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
