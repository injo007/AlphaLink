<?php

namespace Tests\Unit;

use App\Services\Infrastructure\PmtaBundleIntegrityVerifier;
use App\Services\Infrastructure\PmtaCatalog;
use Tests\TestCase;

class PmtaBundleIntegrityTest extends TestCase
{
    private string $testDirectory;

    private string $bundleRoot;

    /** @var array{directory: string, manifest: array<string, mixed>, files: array<string, array{size: int, sha256: string}>} */
    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'alphalink-pmta-'.bin2hex(random_bytes(8));
        mkdir($this->testDirectory, 0700, true);
        $this->bundleRoot = $this->testDirectory.DIRECTORY_SEPARATOR.'bundles';
        mkdir($this->bundleRoot, 0700);

        $bundleDirectoryName = hash('sha256', 'synthetic-pmta-bundle');
        $bundleDirectory = $this->bundleRoot.DIRECTORY_SEPARATOR.$bundleDirectoryName;
        mkdir($bundleDirectory, 0700);

        $contents = [
            'pmta.rpm' => 'synthetic RPM fixture',
            'license' => 'synthetic license fixture',
            'reference-config' => 'synthetic reference config fixture',
        ];
        $files = [];
        $manifestFiles = [];
        foreach ($contents as $name => $content) {
            $path = $bundleDirectory.DIRECTORY_SEPARATOR.$name;
            file_put_contents($path, $content);
            chmod($path, 0600);
            $files[$name] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
            $manifestFiles[] = ['name' => $name, ...$files[$name]];
        }

        $this->contract = [
            'directory' => $bundleDirectoryName,
            'manifest' => [
                'archive_sha256' => $bundleDirectoryName,
                'package' => 'PowerMTA',
                'version' => 'fixture',
                'release' => 'fixture',
                'architecture' => 'x86_64',
                'files' => $manifestFiles,
                'reference_config_deployable' => false,
                'host_compatibility_verified' => false,
                'license_validity_verified' => false,
            ],
            'files' => $files,
        ];

        $manifestPath = $bundleDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        file_put_contents($manifestPath, json_encode($this->contract['manifest'], JSON_THROW_ON_ERROR));
        chmod($manifestPath, 0600);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDirectory);

        parent::tearDown();
    }

    public function test_verified_bundle_requires_exact_manifest_and_private_file_hashes(): void
    {
        $verifier = new PmtaBundleIntegrityVerifier($this->contract);

        $this->assertTrue($verifier->isVerified($this->bundleRoot));
        $this->assertFalse($verifier->isVerified(null));
        $this->assertFalse($verifier->isVerified('relative/path'));
    }

    public function test_changed_bundle_bytes_fail_integrity_check(): void
    {
        $rpm = $this->bundlePath('pmta.rpm');
        file_put_contents($rpm, 'changed RPM fixture');
        chmod($rpm, 0600);

        $this->assertFalse((new PmtaBundleIntegrityVerifier($this->contract))->isVerified($this->bundleRoot));
    }

    public function test_non_private_bundle_files_fail_integrity_check(): void
    {
        chmod($this->bundlePath('license'), 0644);

        $this->assertFalse((new PmtaBundleIntegrityVerifier($this->contract))->isVerified($this->bundleRoot));
    }

    public function test_changed_manifest_claim_fails_integrity_check(): void
    {
        $manifestPath = $this->bundlePath('manifest.json');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['license_validity_verified'] = true;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        chmod($manifestPath, 0600);

        $this->assertFalse((new PmtaBundleIntegrityVerifier($this->contract))->isVerified($this->bundleRoot));
    }

    public function test_bundle_rejects_symlink_and_unexpected_files(): void
    {
        $unexpectedFile = $this->bundlePath('extra.bin');
        file_put_contents($unexpectedFile, 'unexpected');
        chmod($unexpectedFile, 0600);
        $verifier = new PmtaBundleIntegrityVerifier($this->contract);
        $this->assertFalse($verifier->isVerified($this->bundleRoot));
        unlink($unexpectedFile);

        unlink($this->bundlePath('license'));
        symlink('/dev/null', $this->bundlePath('license'));
        $this->assertFalse($verifier->isVerified($this->bundleRoot));
    }

    public function test_catalog_keeps_power_mta_uninstallable_even_when_bundle_integrity_passes(): void
    {
        config(['pmta.bundle_directory' => $this->bundleRoot]);
        $this->app->instance(PmtaBundleIntegrityVerifier::class, new PmtaBundleIntegrityVerifier($this->contract));

        $version = app(PmtaCatalog::class)->versions()['4.0'];

        $this->assertSame('Staged privately', $version['artifact_status']);
        $this->assertFalse($version['installable']);
        $this->assertSame('Legacy evaluation only', $version['installation_status']);
    }

    public function test_catalog_does_not_report_a_bundle_without_an_app_storage_path(): void
    {
        config(['pmta.bundle_directory' => null]);

        $version = app(PmtaCatalog::class)->versions()['4.0'];

        $this->assertSame('Not staged on this app host', $version['artifact_status']);
        $this->assertFalse($version['installable']);
    }

    private function bundlePath(string $filename): string
    {
        return $this->bundleRoot.DIRECTORY_SEPARATOR.$this->contract['directory'].DIRECTORY_SEPARATOR.$filename;
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            unlink($directory);

            return;
        }

        if (! is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $this->removeDirectory($directory.DIRECTORY_SEPARATOR.$entry);
        }

        rmdir($directory);
    }
}
