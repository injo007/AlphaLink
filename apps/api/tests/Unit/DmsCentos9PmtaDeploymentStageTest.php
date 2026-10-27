<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkInstallerAssetCatalog;
use App\Services\Infrastructure\DmsCentos9PmtaDeploymentPlanner;
use App\Services\Infrastructure\DmsCentos9PmtaDeploymentStage;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use App\Services\Infrastructure\DmsInstallerRemoteFileTransport;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\DmsReviewedAssetDigestVerifier;
use RuntimeException;
use Tests\TestCase;

class DmsCentos9PmtaDeploymentStageTest extends TestCase
{
    public function test_gate_rejection_prevents_all_remote_operations(): void
    {
        $gate = $this->createMock(DmsPmtaDeploymentGate::class);
        $gate->expects($this->once())
            ->method('evaluate')
            ->willReturn(['installation_authorized' => false]);
        $transport = $this->transport();

        try {
            $this->stage($gate)->run(
                $transport,
                $transport,
                ['os_pretty_name' => 'AlmaLinux 9'],
                40,
                1212,
                8080,
                [],
                [],
                false,
                $this->installOptions(),
                null,
                ['198.51.100.44'],
            );
            $this->fail('A PMTA gate rejection must stop the stage.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PMTA deployment prerequisites are not verified.', $exception->getMessage());
        }

        $this->assertSame([], $transport->events);
    }

    public function test_private_synthetic_assets_are_dispatched_in_pmta_plan_order(): void
    {
        $root = $this->temporaryDirectory();
        config(['infrastructure.install_assets_directory' => $root]);
        $planner = new DmsCentos9PmtaDeploymentPlanner;
        $pmtaSource = "http-access __IP__ admin\n";
        $plan = $planner->plan(40, true, true, 1212, 8080, ['192.0.2.10'], ['198.51.100.20'], false, $pmtaSource, ['198.51.100.44']);
        $assetContents = ['install/pmta_source' => $pmtaSource];
        foreach ($plan['operations'] as $operation) {
            if ($operation['type'] === 'asset_upload') {
                $assetContents[$operation['source']] = 'synthetic bytes for '.$operation['source'];
            }
        }
        foreach ($assetContents as $path => $contents) {
            $this->writeAsset($root, $path, $contents);
        }

        $assetDigests = [];
        foreach ($assetContents as $path => $contents) {
            $physicalPath = (new AlphaLinkInstallerAssetCatalog)->pathFor($path);
            $assetDigests[$physicalPath] = ['size' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $digestVerifier = new DmsReviewedAssetDigestVerifier($assetDigests);

        $assets = [];
        foreach ($plan['operations'] as $operation) {
            if ($operation['type'] === 'asset_upload') {
                $assets[$operation['source']] = 'present_unverified';
            }
        }
        $gate = $this->createMock(DmsPmtaDeploymentGate::class);
        $gate->expects($this->once())
            ->method('evaluate')
            ->with(['os_pretty_name' => 'AlmaLinux 9'], 40, $this->installOptions())
            ->willReturn([
                'installation_authorized' => true,
                'asset_inventory' => ['pmta' => ['assets' => $assets]],
            ]);
        $transport = $this->transport();

        try {
            $this->stage($gate, $planner, $digestVerifier)->run(
                $transport,
                $transport,
                ['os_pretty_name' => 'AlmaLinux 9'],
                40,
                1212,
                8080,
                ['192.0.2.10'],
                ['198.51.100.20'],
                false,
                $this->installOptions(),
                "workspace-specific PMTA domains\n",
                ['198.51.100.44'],
            );

            $expected = [];
            foreach ($plan['operations'] as $operation) {
                $expected[] = match ($operation['type']) {
                    'command' => ['type' => 'command', 'command' => $operation['command']],
                    'asset_upload' => $operation['source'] === 'install/pmta_40/pmta_domains'
                        ? [
                            'type' => 'upload',
                            'destination' => $operation['destination'],
                            'contents' => "workspace-specific PMTA domains\n",
                        ]
                        : [
                            'type' => 'upload_file',
                            'destination' => $operation['destination'],
                            'contents' => $assetContents[$operation['source']],
                        ],
                    'remote_content' => [
                        'type' => 'upload',
                        'destination' => $operation['destination'],
                        'contents' => $operation['content'],
                    ],
                };
            }
            $this->assertSame($expected, $transport->events);
            $this->assertSame('rpm -e PowerMTA', $transport->events[0]['command']);
            $this->assertSame('systemctl restart pmtahttp;', $transport->events[array_key_last($transport->events)]['command']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_missing_upload_asset_fails_before_the_first_remote_command(): void
    {
        $root = $this->temporaryDirectory();
        config(['infrastructure.install_assets_directory' => $root]);
        $this->writeAsset($root, 'install/pmta_source', 'synthetic source');
        $planner = new DmsCentos9PmtaDeploymentPlanner;
        $plan = $planner->plan(40, true, true, 1212, 8080, [], [], false, 'synthetic source', ['198.51.100.44']);
        $assets = [];
        foreach ($plan['operations'] as $operation) {
            if ($operation['type'] === 'asset_upload') {
                $assets[$operation['source']] = 'present_unverified';
            }
        }
        $gate = $this->createMock(DmsPmtaDeploymentGate::class);
        $gate->expects($this->once())
            ->method('evaluate')
            ->willReturn([
                'installation_authorized' => true,
                'asset_inventory' => ['pmta' => ['assets' => $assets]],
            ]);
        $transport = $this->transport();

        try {
            $this->stage($gate, $planner)->run(
                $transport,
                $transport,
                ['os_pretty_name' => 'AlmaLinux 9'],
                40,
                1212,
                8080,
                [],
                [],
                false,
                $this->installOptions(),
                null,
                ['198.51.100.44'],
            );
            $this->fail('An absent planned asset must block before remote execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A required PMTA deployment asset is unavailable.', $exception->getMessage());
        } finally {
            $this->removeDirectory($root);
        }

        $this->assertSame([], $transport->events);
    }

    private function stage(
        ?DmsPmtaDeploymentGate $gate = null,
        ?DmsCentos9PmtaDeploymentPlanner $planner = null,
        ?DmsReviewedAssetDigestVerifier $digestVerifier = null,
    ): DmsCentos9PmtaDeploymentStage {
        return new DmsCentos9PmtaDeploymentStage(
            $planner ?? new DmsCentos9PmtaDeploymentPlanner,
            $gate ?? $this->createMock(DmsPmtaDeploymentGate::class),
            new AlphaLinkInstallerAssetCatalog,
            $digestVerifier ?? new DmsReviewedAssetDigestVerifier,
        );
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function installOptions(): array
    {
        return ['tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false, 'https' => false];
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

                return false;
            }

            public function upload(string $contents, string $destination): bool
            {
                $this->events[] = ['type' => 'upload', 'destination' => $destination, 'contents' => $contents];

                return false;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $contents = file_get_contents($localPath);
                $this->events[] = ['type' => 'upload_file', 'destination' => $destination, 'contents' => $contents];

                return is_string($contents);
            }
        };
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/alphalink-pmta-test-'.bin2hex(random_bytes(8));
        mkdir($path, 0700);

        return $path;
    }

    private function writeAsset(string $root, string $relativePath, string $contents): void
    {
        $physicalPath = (new AlphaLinkInstallerAssetCatalog)->pathFor($relativePath);
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $physicalPath);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
        chmod($path, 0600);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
