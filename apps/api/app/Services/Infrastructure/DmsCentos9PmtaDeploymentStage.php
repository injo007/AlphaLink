<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Executes the source-mapped CentOS 9 PMTA plan only after the readiness gate passes. */
class DmsCentos9PmtaDeploymentStage
{
    public function __construct(
        private readonly DmsCentos9PmtaDeploymentPlanner $planner,
        private readonly DmsPmtaDeploymentGate $deploymentGate,
        private readonly AlphaLinkInstallerAssetCatalog $assetCatalog = new AlphaLinkInstallerAssetCatalog,
        private readonly DmsReviewedAssetDigestVerifier $digestVerifier = new DmsReviewedAssetDigestVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $hostFacts
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @param  list<string>  $developmentIps
     * @param  list<string>  $productionIps
     * @param  list<string>  $pmtaRelayIps
     */
    public function run(
        DmsInstallerRemoteCommandTransport $commands,
        DmsInstallerRemoteFileTransport $files,
        array $hostFacts,
        int $version,
        int $pmtaPort,
        int $adminPort,
        array $developmentIps,
        array $productionIps,
        bool $allowAllMonitor,
        array $options,
        ?string $pmtaDomainsOverride = null,
        array $pmtaRelayIps = [],
    ): void {
        $readiness = $this->deploymentGate->evaluate($hostFacts, $version, $options);
        if (($readiness['installation_authorized'] ?? false) !== true) {
            throw new RuntimeException('PMTA deployment prerequisites are not verified.');
        }
        if ($pmtaRelayIps === []) {
            throw new RuntimeException('Configure PMTA RELAY SOURCE IPS with AlphaLink\'s stable public IPv4 egress address before installing PMTA.');
        }

        $assetRoot = config('infrastructure.install_assets_directory');
        if (! is_string($assetRoot) || trim($assetRoot) === '') {
            throw new RuntimeException('The AlphaLink installation asset directory is unavailable.');
        }

        $rpmPath = "install/pmta_{$version}/pmta.rpm";
        $patchPath = $version === 40
            ? 'install/pmta_40/pmtahttp_patch'
            : "install/pmta_{$version}/pmtad_patch";
        $inventory = $readiness['asset_inventory']['pmta']['assets'] ?? null;
        if (! is_array($inventory)) {
            throw new RuntimeException('The PMTA asset inventory is incomplete.');
        }

        $pmtaSource = $this->readPrivateAsset($assetRoot, 'install/pmta_source');
        $plan = $this->planner->plan(
            $version,
            ($inventory[$rpmPath] ?? null) === 'present_unverified',
            ($inventory[$patchPath] ?? null) === 'present_unverified',
            $pmtaPort,
            $adminPort,
            $developmentIps,
            $productionIps,
            $allowAllMonitor,
            $pmtaSource,
            $pmtaRelayIps,
        );

        // Snapshot and validate every upload before the first remote command.
        // SFTP streams the large RPM and patch from disk rather than loading
        // both into PHP memory at once.
        $snapshotRoot = $this->createSnapshotRoot();
        $uploadContents = [];
        $uploadFiles = [];

        try {
            foreach ($plan['operations'] as $operation) {
                if ($operation['type'] !== 'asset_upload') {
                    continue;
                }

                $source = $operation['source'];
                $this->privateAssetPath($assetRoot, $source);
                if (! $this->digestVerifier->verifiesAsset($assetRoot, $source)) {
                    throw new RuntimeException('A required PMTA deployment asset failed its reviewed digest check.');
                }

                $isDomainsConfig = $source === "install/pmta_{$version}/pmta_domains";
                if ($isDomainsConfig && $pmtaDomainsOverride !== null) {
                    $uploadContents[$source] = $pmtaDomainsOverride;

                    continue;
                }

                $uploadFiles[$source] = $this->snapshotPrivateAsset($assetRoot, $source, $snapshotRoot);
            }

            foreach ($plan['operations'] as $operation) {
                match ($operation['type']) {
                    'command' => $commands->exec($operation['command']),
                    'asset_upload' => array_key_exists($operation['source'], $uploadContents)
                        ? $files->upload($uploadContents[$operation['source']], $operation['destination'])
                        : $files->uploadFile($uploadFiles[$operation['source']], $operation['destination']),
                    'remote_content' => $files->upload($operation['content'], $operation['destination']),
                    default => throw new RuntimeException('The PMTA plan contains an unsupported operation.'),
                };
            }
        } finally {
            $this->removeSnapshotRoot($snapshotRoot);
        }
    }

    private function createSnapshotRoot(): string
    {
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'alphalink-pmta-'.bin2hex(random_bytes(16));
        if (file_exists($root) || is_link($root) || ! mkdir($root, 0700)) {
            throw new RuntimeException('A private PMTA asset snapshot could not be created.');
        }

        return $root;
    }

    private function snapshotPrivateAsset(string $root, string $relativePath, string $snapshotRoot): string
    {
        $sourcePath = $this->privateAssetPath($root, $relativePath);
        $target = $snapshotRoot.DIRECTORY_SEPARATOR.$this->assetCatalog->pathFor($relativePath);
        $targetDirectory = dirname($target);

        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0700, true)) {
            throw new RuntimeException('A private PMTA asset snapshot directory could not be created.');
        }

        $sourceSize = filesize($sourcePath);
        $sourceDigest = hash_file('sha256', $sourcePath);
        if (! is_int($sourceSize) || $sourceSize < 1 || ! is_string($sourceDigest)
            || ! copy($sourcePath, $target) || ! chmod($target, 0600)) {
            throw new RuntimeException('A required PMTA deployment asset could not be snapshotted.');
        }

        $snapshotSize = filesize($target);
        $snapshotDigest = hash_file('sha256', $target);
        if ($snapshotSize !== $sourceSize || ! is_string($snapshotDigest) || ! hash_equals($sourceDigest, $snapshotDigest)
            || ! $this->digestVerifier->verifiesAsset($snapshotRoot, $relativePath)) {
            throw new RuntimeException('A required PMTA deployment asset changed during snapshot.');
        }

        return $target;
    }

    private function removeSnapshotRoot(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }

        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeSnapshotRoot($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($root);
    }

    private function readPrivateAsset(string $root, string $relativePath): string
    {
        $path = $this->privateAssetPath($root, $relativePath);
        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('A required PMTA deployment asset is unavailable.');
        }

        return $contents;
    }

    private function privateAssetPath(string $root, string $relativePath): string
    {
        if (! str_starts_with($root, DIRECTORY_SEPARATOR) || is_link($root) || ! is_dir($root)) {
            throw new RuntimeException('The AlphaLink installation asset directory is unavailable or unsafe.');
        }

        $mode = fileperms($root);
        if (! is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('The AlphaLink installation asset directory must be private.');
        }

        $current = rtrim($root, DIRECTORY_SEPARATOR);
        $segments = explode('/', $this->assetCatalog->pathFor($relativePath));
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The AlphaLink PMTA asset path is unsafe.');
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new RuntimeException('The AlphaLink PMTA asset path contains a symlink.');
            }

            $isFile = $index === array_key_last($segments);
            if ($isFile ? ! is_file($current) : ! is_dir($current)) {
                throw new RuntimeException('A required PMTA deployment asset is unavailable.');
            }
        }

        if ((filesize($current) ?: 0) < 1 || ! is_readable($current)) {
            throw new RuntimeException('A required PMTA deployment asset is empty or unreadable.');
        }

        return $current;
    }
}
