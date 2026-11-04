<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallAssetInventory;
use App\Services\Infrastructure\AlphaLinkBulkInstallInstallerMatrix;
use App\Services\Infrastructure\AlphaLinkInstallerAssetCatalog;
use App\Services\Infrastructure\AlphaLinkInstallPlatform;
use PHPUnit\Framework\TestCase;

class AlphaLinkBulkInstallAssetInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/alphalink-installer-assets-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_it_reports_reference_asset_presence_and_keeps_artifact_validity_unverified(): void
    {
        $options = $this->options();
        $options['tracking'] = true;
        $matrix = new AlphaLinkBulkInstallInstallerMatrix(new AlphaLinkInstallPlatform);
        $plan = $matrix->plan('CentOS Linux 7', 40, $options);
        foreach ([...$plan['pmta']['referenced_assets'], ...$plan['selected_option_requirements']['tracking']['expected_assets']] as $path) {
            $this->writeAsset($path);
        }

        $inventory = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('CentOS Linux 7', 40, $options, $this->root);

        $this->assertSame('available_private', $inventory['asset_root_status']);
        $this->assertTrue($inventory['asset_presence_complete']);
        $this->assertFalse($inventory['pmta']['would_skip_version_setup']);
        $this->assertSame('attempts_version_setup', $inventory['pmta']['version_setup_behavior']);
        $this->assertSame('present_unverified', $inventory['pmta']['assets']['install/pmta_40/pmta.rpm']);
        $this->assertSame('missing', $inventory['selected_options']['tracking']['optional_assets']['install/cf_ips.conf']);
        $this->assertStringContainsString('not artifact validation', $inventory['note']);
    }

    public function test_it_reproduces_reference_rpm_and_patch_skip_truth_table_when_only_rpm_is_missing(): void
    {
        $matrix = new AlphaLinkBulkInstallInstallerMatrix(new AlphaLinkInstallPlatform);
        $plan = $matrix->plan('Rocky Linux 9', 50, $this->options());
        foreach (array_slice($plan['pmta']['referenced_assets'], 1) as $path) {
            $this->writeAsset($path);
        }

        $inventory = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('Rocky Linux 9', 50, $this->options(), $this->root);

        $this->assertFalse($inventory['pmta']['would_skip_version_setup']);
        $this->assertSame('attempts_version_setup', $inventory['pmta']['version_setup_behavior']);
        $this->assertSame(['install/pmta_50/pmta.rpm'], $inventory['pmta']['missing_referenced_assets']);
        $this->assertFalse($inventory['asset_presence_complete']);
    }

    public function test_it_reports_source_skips_for_missing_selected_optional_assets(): void
    {
        $matrix = new AlphaLinkBulkInstallInstallerMatrix(new AlphaLinkInstallPlatform);
        $options = $this->options(true);
        $inventory = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('AlmaLinux 9', 45, $options, $this->root);

        $this->assertSame('skips_when_rpm_and_patch_are_both_absent', $inventory['pmta']['version_setup_behavior']);
        $this->assertSame(['install/squid_centos.conf'], $inventory['selected_options']['proxy']['missing_assets']);
        $this->assertSame(['install/dovecot_centos.conf'], $inventory['selected_options']['imap']['missing_assets']);
        $this->assertSame('Squid is not installed or enabled when its configuration asset is missing.', $inventory['selected_options']['proxy']['effect_if_missing']);
        $this->assertSame('Dovecot is not installed or enabled when its configuration asset is missing.', $inventory['selected_options']['imap']['effect_if_missing']);
        $this->assertFalse($inventory['asset_presence_complete']);
    }

    public function test_a_selected_dkim_install_does_not_require_a_shared_private_key_asset(): void
    {
        $matrix = new AlphaLinkBulkInstallInstallerMatrix(new AlphaLinkInstallPlatform);
        $options = $this->options();
        $options['dkim'] = true;
        foreach ($matrix->plan('AlmaLinux 9', 45, $options)['pmta']['referenced_assets'] as $path) {
            $this->writeAsset($path);
        }

        $inventory = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('AlmaLinux 9', 45, $options, $this->root);

        $this->assertSame([], $inventory['selected_options']['dkim']['assets']);
        $this->assertSame('missing', $inventory['selected_options']['dkim']['optional_assets']['install/dkim.json']);
        $this->assertTrue($inventory['asset_presence_complete']);
    }

    public function test_it_does_not_follow_symlinks_and_requires_a_private_absolute_root(): void
    {
        $matrix = new AlphaLinkBulkInstallInstallerMatrix(new AlphaLinkInstallPlatform);
        $this->writeAsset('install/pmta_40/pmta.rpm');
        symlink('/etc/passwd', $this->root.'/pmta/4.0/httpd-patch');

        $inventory = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('CentOS Linux 7', 40, $this->options(), $this->root);
        $this->assertSame('unsafe_symlink', $inventory['pmta']['assets']['install/pmta_40/pmtahttp_patch']);
        $this->assertFalse($inventory['asset_presence_complete']);

        chmod($this->root, 0755);
        $notPrivate = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('CentOS Linux 7', 40, $this->options(), $this->root);
        $this->assertSame('not_private', $notPrivate['asset_root_status']);

        $notConfigured = (new AlphaLinkBulkInstallAssetInventory($matrix))->inspect('CentOS Linux 7', 40, $this->options(), '');
        $this->assertSame('not_configured', $notConfigured['asset_root_status']);
        $this->assertNull($notConfigured['pmta']['would_skip_version_setup']);
    }

    private function writeAsset(string $relativePath): void
    {
        $physicalPath = (new AlphaLinkInstallerAssetCatalog)->pathFor($relativePath);
        $path = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $physicalPath);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, 'synthetic local fixture');
        chmod($path, 0600);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$name);
        }

        @rmdir($path);
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function options(bool $enabled = false): array
    {
        return [
            'tracking' => $enabled,
            'proxy' => $enabled,
            'imap' => $enabled,
            'spf' => $enabled,
            'dkim' => $enabled,
            'dmarc' => $enabled,
            'https' => $enabled,
        ];
    }
}
