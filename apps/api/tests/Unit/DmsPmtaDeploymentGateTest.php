<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkInstallerAssetCatalog;
use App\Services\Infrastructure\DmsBulkInstallAssetInventory;
use App\Services\Infrastructure\DmsBulkInstallInstallerMatrix;
use App\Services\Infrastructure\DmsInstallPlatform;
use App\Services\Infrastructure\DmsPmtaDeploymentGate;
use App\Services\Infrastructure\DmsReviewedAssetDigestVerifier;
use Tests\TestCase;

class DmsPmtaDeploymentGateTest extends TestCase
{
    private string $root;

    private string $assetRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/alphalink-pmta-gate-'.bin2hex(random_bytes(8));
        $this->assetRoot = $this->root.'/alphalink-install-assets';
        mkdir($this->assetRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_unsupported_saved_host_facts_block_before_asset_inspection(): void
    {
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);

        $result = $this->gate()->evaluate(
            ['architecture' => 'x86_64', 'os_pretty_name' => 'Ubuntu 24.04'],
            40,
            $this->installOptions(),
        );

        $this->assertFalse($result['installation_authorized']);
        $this->assertNull($result['asset_inventory']);
        $this->assertFalse($result['checks']['supported_host']);
        $this->assertStringContainsString('supports CentOS Linux 7', $result['blockers'][0]);
    }

    public function test_missing_private_asset_root_blocks_without_creating_install_authority(): void
    {
        config([
            'infrastructure.install_assets_directory' => null,
        ]);

        $result = $this->gate()->evaluate($this->almaLinuxFacts(), 50, $this->installOptions());

        $this->assertFalse($result['installation_authorized']);
        $this->assertFalse($result['checks']['private_alphalink_asset_root']);
        $this->assertSame('not_configured', $result['asset_inventory']['asset_root_status']);
        $this->assertContains('The AlphaLink installation asset directory is not configured as a private directory.', $result['blockers']);
    }

    public function test_existing_pmta_requires_reviewed_identity_for_replacement(): void
    {
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);
        $facts = [...$this->almaLinuxFacts(), 'pmta_binary_present' => true];

        $install = $this->gate()->evaluate($facts, 45, $this->installOptions());
        $reinstall = $this->gate()->evaluate($facts, 45, $this->installOptions(), reinstall: true);

        $reviewed = $this->gate()->evaluate([...$facts, 'pmta_reviewed_identity' => '4.5'], 45, $this->installOptions());

        $this->assertFalse($install['checks']['host_state_ready_for_install']);
        $this->assertContains('The existing PMTA installation does not match a reviewed package, patch, and license identity; replacement is blocked.', $install['blockers']);
        $this->assertTrue($reviewed['checks']['host_state_ready_for_install']);
        $this->assertTrue($reinstall['checks']['host_state_ready_for_install']);
    }

    public function test_existing_queued_mail_blocks_replacement(): void
    {
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);
        $facts = [...$this->almaLinuxFacts(), 'pmta_binary_present' => true,
            'pmta_reviewed_identity' => '4.5', 'pmta_queue_nonempty' => true];

        $result = $this->gate()->evaluate($facts, 45, $this->installOptions());

        $this->assertFalse($result['checks']['pmta_queue_empty_for_replacement']);
        $this->assertContains('The existing PMTA queue contains mail; drain it before replacing the installation.', $result['blockers']);
    }

    public function test_gate_requires_both_versioned_files_even_when_the_dms_and_condition_would_attempt_setup(): void
    {
        $this->writeDmsAssets(45, $this->installOptions(), ['install/pmta_45/pmta.rpm']);
        config([
            'infrastructure.install_assets_directory' => $this->assetRoot,
        ]);

        $result = $this->gate()->evaluate($this->almaLinuxFacts(), 45, $this->installOptions());

        $this->assertFalse($result['installation_authorized']);
        $this->assertFalse($result['checks']['versioned_rpm_and_patch_present']);
        $this->assertTrue($result['checks']['dms_version_setup_would_not_skip']);
        $this->assertSame('dms_attempts_version_setup', $result['asset_inventory']['pmta']['version_setup_behavior']);
        $this->assertContains(
            'The selected PMTA package and matching daemon patch must both be present before installation can continue.',
            $result['blockers'],
        );
    }

    public function test_complete_dms_assets_pass_identity_and_config_checks_but_fail_unverified_target_gates(): void
    {
        $this->writeDmsAssets(40, $this->installOptions());
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);

        $result = $this->gate($this->syntheticAssetDigestVerifier())->evaluate($this->almaLinuxFacts(), 40, $this->installOptions());

        $this->assertTrue($result['checks']['private_alphalink_asset_root']);
        $this->assertTrue($result['checks']['complete_alphalink_asset_presence']);
        $this->assertTrue($result['checks']['versioned_rpm_and_patch_present']);
        $this->assertTrue($result['checks']['dms_version_setup_would_not_skip']);
        $this->assertTrue($result['checks']['versioned_rpm_matches_reviewed_dms_asset']);
        $this->assertTrue($result['checks']['versioned_license_matches_reviewed_dms_asset']);
        $this->assertTrue($result['checks']['full_alphalink_asset_set_has_reviewed_digests']);
        $this->assertTrue($result['checks']['dms_configuration_template_is_deployable']);
        $this->assertFalse($result['checks']['pmta_rpm_validation_verified']);
        $this->assertFalse($result['checks']['target_host_compatibility_verified']);
        $this->assertFalse($result['installation_authorized']);
        $this->assertContains(
            'The selected PMTA RPM has no successful metadata, digest, and target DNF dependency-plan validation.',
            $result['blockers'],
        );
        $this->assertContains('PMTA compatibility with the selected target host has not been verified.', $result['blockers']);
    }

    public function test_pmta_source_template_without_relay_authorization_is_not_deployable(): void
    {
        $this->writeDmsAssets(40, $this->installOptions());
        $sourcePath = $this->assetRoot.'/'.(new AlphaLinkInstallerAssetCatalog)->pathFor('install/pmta_source');
        file_put_contents($sourcePath, "<source __IP__>\n    smtp-service yes\n</source>\n");
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);

        $result = $this->gate($this->syntheticAssetDigestVerifier())->evaluate($this->almaLinuxFacts(), 40, $this->installOptions());

        $this->assertFalse($result['checks']['dms_configuration_template_is_deployable']);
        $this->assertContains('The DMS PMTA template is missing required port substitutions or SMTP relay authorization.', $result['blockers']);
        $this->assertFalse($result['installation_authorized']);
    }

    public function test_dms_matched_license_is_deployed_without_an_unmapped_entitlement_preflight(): void
    {
        $this->writeDmsAssets(40, $this->installOptions());
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);
        $digests = $this->syntheticAssetDigests();
        $rpmDigest = $digests['pmta/4.0/package.rpm']['sha256'];
        $verifier = new DmsReviewedAssetDigestVerifier(
            $digests,
            new AlphaLinkInstallerAssetCatalog,
            [
                $rpmDigest => [
                    'pmta_version' => 40,
                    'target_os' => 'AlmaLinux 9.8',
                    'architecture' => 'x86_64',
                    'rpm_metadata_verified' => true,
                    'rpm_digests_verified' => true,
                    'dnf_plan_resolved' => true,
                ],
            ],
        );

        $result = $this->gate($verifier)->evaluate($this->almaLinuxFacts(), 40, $this->installOptions());

        $this->assertTrue($result['checks']['versioned_license_matches_reviewed_dms_asset']);
        $this->assertArrayNotHasKey('license_entitlement_verified', $result['checks']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['installation_authorized']);
    }

    public function test_rpm_validation_evidence_must_match_selected_version_os_architecture_and_pass_all_checks(): void
    {
        $this->writeDmsAssets(40, $this->installOptions());
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);
        $digests = $this->syntheticAssetDigests();
        $rpmDigest = $digests['pmta/4.0/package.rpm']['sha256'];
        $evidence = [
            $rpmDigest => [
                'pmta_version' => 40,
                'target_os' => 'AlmaLinux 9.8',
                'architecture' => 'x86_64',
                'rpm_metadata_verified' => true,
                'rpm_digests_verified' => true,
                'dnf_plan_resolved' => true,
            ],
        ];
        $verifier = new DmsReviewedAssetDigestVerifier($digests, new AlphaLinkInstallerAssetCatalog, $evidence);

        $this->assertTrue($verifier->verifiesRpmForTarget(
            $this->assetRoot,
            'install/pmta_40/pmta.rpm',
            40,
            'AlmaLinux 9.8',
            'x86_64',
        ));
        $this->assertFalse($verifier->verifiesRpmForTarget(
            $this->assetRoot,
            'install/pmta_40/pmta.rpm',
            40,
            'AlmaLinux 9.7',
            'x86_64',
        ));

        $evidence[$rpmDigest]['dnf_plan_resolved'] = false;
        $failedVerifier = new DmsReviewedAssetDigestVerifier($digests, new AlphaLinkInstallerAssetCatalog, $evidence);
        $this->assertFalse($failedVerifier->verifiesRpmForTarget(
            $this->assetRoot,
            'install/pmta_40/pmta.rpm',
            40,
            'AlmaLinux 9.8',
            'x86_64',
        ));
    }

    public function test_absent_assets_are_not_misreported_as_digest_mismatches(): void
    {
        config([
            'infrastructure.install_assets_directory' => $this->assetRoot,
        ]);

        $result = $this->gate()->evaluate($this->almaLinuxFacts(), 40, $this->installOptions());

        $this->assertFalse($result['installation_authorized']);
        $this->assertContains(
            'Missing or unsafe AlphaLink installation assets: pmta/4.0/package.rpm, pmta/4.0/httpd-patch, pmta/4.0/license.lic, pmta/4.0/domains.conf, pmta/shared/source.conf, pmta/shared/base.conf.',
            $result['blockers'],
        );
        $this->assertNotContains(
            'The selected PMTA package does not match its reviewed DMS asset size or SHA-256.',
            $result['blockers'],
        );
        $this->assertNotContains(
            'The selected PMTA license does not match its reviewed DMS asset size or SHA-256.',
            $result['blockers'],
        );
    }

    public function test_selected_option_assets_are_included_in_the_installation_gate(): void
    {
        $options = $this->installOptions();
        $options['proxy'] = true;
        $this->writeDmsAssets(40, $options, ['install/squid_centos.conf']);
        config(['infrastructure.install_assets_directory' => $this->assetRoot]);

        $result = $this->gate()->evaluate($this->almaLinuxFacts(), 40, $options);

        $this->assertFalse($result['checks']['complete_alphalink_asset_presence']);
        $this->assertFalse($result['installation_authorized']);
        $this->assertContains(
            'Missing or unsafe AlphaLink installation assets: optional/proxy/squid.conf.',
            $result['blockers'],
        );
    }

    public function test_reviewed_digests_must_cover_every_selected_asset_and_reject_modified_bytes(): void
    {
        $options = $this->installOptions();
        $this->writeDmsAssets(40, $options);
        config([
            'infrastructure.install_assets_directory' => $this->assetRoot,
        ]);
        $inventory = (new DmsBulkInstallAssetInventory(new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform)))
            ->inspect('AlmaLinux 9.8', 40, $options, $this->assetRoot);
        $paths = array_keys($inventory['pmta']['assets']);
        $digests = [];
        foreach ($paths as $path) {
            $physicalPath = (new AlphaLinkInstallerAssetCatalog)->pathFor($path);
            $content = file_get_contents($this->assetRoot.'/'.$physicalPath);
            $digests[$physicalPath] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }

        $verifier = new DmsReviewedAssetDigestVerifier($digests);
        $this->assertTrue($verifier->verifies($this->assetRoot, $inventory));
        $this->assertTrue($this->gate($verifier)->evaluate($this->almaLinuxFacts(), 40, $options)['checks']['full_alphalink_asset_set_has_reviewed_digests']);

        unset($digests['pmta/4.0/httpd-patch']);
        $this->assertFalse((new DmsReviewedAssetDigestVerifier($digests))->verifies($this->assetRoot, $inventory));

        file_put_contents($this->assetRoot.'/pmta/4.0/httpd-patch', 'changed bytes');
        $this->assertFalse($verifier->verifies($this->assetRoot, $inventory));

        $options['proxy'] = true;
        $this->writeDmsAssets(40, $options);
        $withProxy = (new DmsBulkInstallAssetInventory(new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform)))
            ->inspect('AlmaLinux 9.8', 40, $options, $this->assetRoot);
        $this->assertFalse($verifier->verifies($this->assetRoot, $withProxy));

        $options['proxy'] = false;
        $options['tracking'] = true;
        $this->writeDmsAssets(40, $options);
        $cloudflarePath = $this->assetRoot.'/optional/tracking/cloudflare-ips.conf';
        if (! is_dir(dirname($cloudflarePath))) {
            mkdir(dirname($cloudflarePath), 0700, true);
        }
        file_put_contents($cloudflarePath, 'synthetic optional asset');
        $withOptional = (new DmsBulkInstallAssetInventory(new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform)))
            ->inspect('AlmaLinux 9.8', 40, $options, $this->assetRoot);
        $this->assertFalse($verifier->verifies($this->assetRoot, $withOptional));
    }

    private function gate(?DmsReviewedAssetDigestVerifier $digestVerifier = null): DmsPmtaDeploymentGate
    {
        $platform = new DmsInstallPlatform;

        return new DmsPmtaDeploymentGate(
            new DmsBulkInstallAssetInventory(new DmsBulkInstallInstallerMatrix($platform)),
            $platform,
            $digestVerifier,
        );
    }

    private function syntheticAssetDigestVerifier(): DmsReviewedAssetDigestVerifier
    {
        return new DmsReviewedAssetDigestVerifier($this->syntheticAssetDigests());
    }

    /** @return array<string, array{size: int, sha256: string}> */
    private function syntheticAssetDigests(): array
    {
        $matrix = new DmsBulkInstallAssetInventory(new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform));
        $inventory = $matrix->inspect('AlmaLinux 9.8', 40, $this->installOptions(), $this->assetRoot);
        $catalog = new AlphaLinkInstallerAssetCatalog;
        $digests = [];
        foreach (array_keys($inventory['pmta']['assets']) as $sourcePath) {
            $path = $this->assetRoot.'/'.$catalog->pathFor($sourcePath);
            $content = file_get_contents($path);
            $digests[$catalog->pathFor($sourcePath)] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }

        return $digests;
    }

    /** @param list<string> $omitted */
    private function writeDmsAssets(int $version, array $options, array $omitted = []): void
    {
        $matrix = new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform);
        $plan = $matrix->plan('AlmaLinux 9', $version, $options);
        $paths = $plan['pmta']['referenced_assets'];

        foreach ($plan['selected_option_requirements'] as $requirement) {
            array_push($paths, ...($requirement['expected_assets'] ?? []));
        }

        foreach (array_diff(array_unique($paths), $omitted) as $relativePath) {
            $physicalPath = (new AlphaLinkInstallerAssetCatalog)->pathFor($relativePath);
            $path = $this->assetRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $physicalPath);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $contents = match ($relativePath) {
                'install/pmta_config' => 'listener-port __PMTA_LISTENER_PORT__ admin-port __PMTA_ADMIN_PORT__',
                'install/pmta_source' => "<source __IP__>\n    always-allow-relaying yes # allow AlphaLink relay\n</source>\n",
                default => 'synthetic DMS asset',
            };
            file_put_contents($path, $contents);
            chmod($path, 0600);
        }
    }

    /** @return array{architecture: string, os_pretty_name: string} */
    private function almaLinuxFacts(): array
    {
        return ['architecture' => 'x86_64', 'os_pretty_name' => 'AlmaLinux 9.8', 'pmta_binary_present' => false];
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function installOptions(): array
    {
        return [
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
