<?php

namespace App\Services\Infrastructure;

/**
 * Fails closed before the PMTA stage can reach a host runner.
 *
 * The inspected source flow checks only whether both the RPM and patch are absent. AlphaLink additionally
 * requires the complete source-mapped asset set and evidence that identifies and supports
 * the exact deployment artifacts. Reviewed DMS asset pins identify the files AlphaLink
 * will deploy; they do not establish license entitlement or target-host compatibility.
 */
class AlphaLinkPmtaDeploymentGate
{
    public function __construct(
        private readonly AlphaLinkBulkInstallAssetInventory $assetInventory,
        private readonly AlphaLinkInstallPlatform $platform,
        private readonly ?AlphaLinkReviewedAssetDigestVerifier $digestVerifier = null,
        private readonly AlphaLinkInstallerAssetCatalog $assetCatalog = new AlphaLinkInstallerAssetCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $hostFacts
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return array{
     *     installation_authorized: bool,
     *     checks: array<string, bool>,
     *     blockers: list<string>,
     *     asset_inventory: array<string, mixed>|null
     * }
     */
    public function evaluate(array $hostFacts, int $pmtaVersion, array $options, bool $reinstall = false): array
    {
        $platformBlockers = $this->platform->blockers($hostFacts);
        $supportedHost = $platformBlockers === [];

        if (! $supportedHost) {
            return [
                'installation_authorized' => false,
                'checks' => $this->blockedChecks(),
                'blockers' => $platformBlockers,
                'asset_inventory' => null,
            ];
        }

        $assetRoot = config('infrastructure.install_assets_directory');
        $inventory = $this->assetInventory->inspect(
            (string) $hostFacts['os_pretty_name'],
            $pmtaVersion,
            $options,
            is_string($assetRoot) ? $assetRoot : '',
        );
        $pmtaAssets = $inventory['pmta']['assets'] ?? [];
        $rpmPath = "install/pmta_{$pmtaVersion}/pmta.rpm";
        $patchPath = $pmtaVersion === 40
            ? 'install/pmta_40/pmtahttp_patch'
            : "install/pmta_{$pmtaVersion}/pmtad_patch";
        $rpmAndPatchPresent = ($pmtaAssets[$rpmPath] ?? null) === 'present_unverified'
            && ($pmtaAssets[$patchPath] ?? null) === 'present_unverified';
        $versionSetupWouldBeSkipped = $inventory['pmta']['would_skip_version_setup'] ?? null;
        $digestVerifier = $this->digestVerifier ?? new AlphaLinkReviewedAssetDigestVerifier;
        $pinnedRpmMatches = $this->versionedAssetMatchesReviewedAlphaLinkAsset(
            $assetRoot,
            $inventory,
            $rpmPath,
            $digestVerifier,
        );
        $versionedLicenseMatches = $this->versionedAssetMatchesReviewedAlphaLinkAsset(
            $assetRoot,
            $inventory,
            "install/pmta_{$pmtaVersion}/pmta.lic",
            $digestVerifier,
        );
        $completeAssetSetHasReviewedDigests = $digestVerifier->verifies(
            is_string($assetRoot) ? $assetRoot : '',
            $inventory,
        );
        $rpmMetadataVerified = is_string($assetRoot)
            && $digestVerifier->verifiesRpmForTarget(
                $assetRoot,
                $rpmPath,
                $pmtaVersion,
                (string) $hostFacts['os_pretty_name'],
                (string) $hostFacts['architecture'],
            );
        $configurationTemplateIsDeployable = $this->configurationTemplateIsDeployable(
            $assetRoot,
            $inventory,
        );

        $checks = [
            'supported_host' => $supportedHost,
            'host_state_ready_for_install' => $reinstall
                || ($hostFacts['pmta_binary_present'] ?? null) === false
                || in_array($hostFacts['pmta_reviewed_identity'] ?? null, ['4.0', '4.5', '5.0'], true),
            'pmta_queue_empty_for_replacement' => ($hostFacts['pmta_queue_nonempty'] ?? false) === false,
            'private_alphalink_asset_root' => $inventory['asset_root_status'] === 'available_private',
            'complete_alphalink_asset_presence' => $inventory['asset_presence_complete'],
            'versioned_rpm_and_patch_present' => $rpmAndPatchPresent,
            'version_setup_would_not_skip' => $versionSetupWouldBeSkipped === false,
            'versioned_rpm_matches_reviewed_asset' => $pinnedRpmMatches === true,
            'versioned_license_matches_reviewed_asset' => $versionedLicenseMatches === true,
            'full_alphalink_asset_set_has_reviewed_digests' => $completeAssetSetHasReviewedDigests,
            'configuration_template_is_deployable' => $configurationTemplateIsDeployable,
            'pmta_rpm_validation_verified' => $rpmMetadataVerified,
            // DMS copies the selected version's license file to the host without a
            // separate entitlement preflight. The versioned file's DMS hash is checked
            // above; do not invent an entitlement workflow AlphaLink cannot verify.
            'target_host_compatibility_verified' => $rpmMetadataVerified,
        ];

        $blockers = $this->blockers(
            $checks,
            $inventory,
            $rpmPath,
            $versionSetupWouldBeSkipped,
            $pinnedRpmMatches,
            $versionedLicenseMatches,
        );

        return [
            'installation_authorized' => $blockers === [],
            'checks' => $checks,
            'blockers' => $blockers,
            'asset_inventory' => $inventory,
        ];
    }

    /** @return array<string, bool> */
    private function blockedChecks(): array
    {
        return [
            'supported_host' => false,
            'host_state_ready_for_install' => false,
            'pmta_queue_empty_for_replacement' => false,
            'private_alphalink_asset_root' => false,
            'complete_alphalink_asset_presence' => false,
            'versioned_rpm_and_patch_present' => false,
            'version_setup_would_not_skip' => false,
            'versioned_rpm_matches_reviewed_asset' => false,
            'versioned_license_matches_reviewed_asset' => false,
            'full_alphalink_asset_set_has_reviewed_digests' => false,
            'configuration_template_is_deployable' => false,
            'pmta_rpm_validation_verified' => false,
            'target_host_compatibility_verified' => false,
        ];
    }

    /**
     * @param  array<string, bool>  $checks
     * @param  array<string, mixed>  $inventory
     * @return list<string>
     */
    private function blockers(
        array $checks,
        array $inventory,
        string $rpmPath,
        ?bool $versionSetupWouldBeSkipped,
        ?bool $pinnedRpmMatches,
        ?bool $versionedLicenseMatches,
    ): array {
        $blockers = [];

        if (! $checks['host_state_ready_for_install']) {
            $blockers[] = 'The existing PMTA installation does not match a reviewed package, patch, and license identity; replacement is blocked.';
        }

        if (! $checks['pmta_queue_empty_for_replacement']) {
            $blockers[] = 'The existing PMTA queue contains mail; drain it before replacing the installation.';
        }

        if (! $checks['private_alphalink_asset_root']) {
            $blockers[] = 'The AlphaLink installation asset directory is not configured as a private directory.';
        }

        if (! $checks['complete_alphalink_asset_presence']) {
            $missing = array_merge(
                $inventory['pmta']['missing_referenced_assets'] ?? [],
                ...array_values(array_map(
                    static fn (array $option): array => $option['missing_assets'] ?? [],
                    $inventory['selected_options'] ?? [],
                )),
            );
            $missing = array_values(array_unique($missing));
            $missing = array_map($this->assetCatalog->pathFor(...), $missing);
            $blockers[] = $missing === []
                ? 'At least one selected AlphaLink installation asset is missing, unsafe, or invalid.'
                : 'Missing or unsafe AlphaLink installation assets: '.implode(', ', $missing).'.';
        }

        if (! $checks['versioned_rpm_and_patch_present']) {
            $blockers[] = 'The selected PMTA package and matching daemon patch must both be present before installation can continue.';
        }

        if ($versionSetupWouldBeSkipped === true) {
            $blockers[] = 'The source-equivalent PMTA flow would skip version setup because both the package and patch are absent.';
        } elseif ($versionSetupWouldBeSkipped === null) {
            $blockers[] = 'The PMTA setup path cannot be determined because the AlphaLink asset inventory is unavailable.';
        }

        if ($pinnedRpmMatches === false) {
            $blockers[] = 'The selected PMTA package does not match its reviewed asset size or SHA-256.';
        }

        if ($versionedLicenseMatches === false) {
            $blockers[] = 'The selected PMTA license does not match its reviewed asset size or SHA-256.';
        }

        if (! $checks['full_alphalink_asset_set_has_reviewed_digests']) {
            $blockers[] = 'The reviewed digest manifest does not cover every selected AlphaLink PMTA asset.';
        }

        if (! $checks['configuration_template_is_deployable']) {
            $blockers[] = 'The PMTA template is missing required port substitutions or SMTP relay authorization.';
        }

        if (($inventory['pmta']['assets'][$rpmPath] ?? null) === 'present_unverified'
            && ! $checks['pmta_rpm_validation_verified']) {
            $blockers[] = 'The selected PMTA RPM has no successful metadata, digest, and target DNF dependency-plan validation.';
        }

        if (! $checks['target_host_compatibility_verified']) {
            $blockers[] = 'PMTA compatibility with the selected target host has not been verified.';
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return bool|null True when pinned, false when present but mismatched, null when absent or unavailable.
     */
    private function versionedAssetMatchesReviewedAlphaLinkAsset(
        mixed $assetRoot,
        array $inventory,
        string $assetPath,
        AlphaLinkReviewedAssetDigestVerifier $digestVerifier,
    ): ?bool {
        if (! is_string($assetRoot)
            || ($inventory['asset_root_status'] ?? null) !== 'available_private'
            || ($inventory['pmta']['assets'][$assetPath] ?? null) !== 'present_unverified') {
            return null;
        }

        return $digestVerifier->verifiesAsset($assetRoot, $assetPath);
    }

    /** @param array<string, mixed> $inventory */
    private function configurationTemplateIsDeployable(mixed $assetRoot, array $inventory): bool
    {
        if (! is_string($assetRoot)
            || ($inventory['asset_root_status'] ?? null) !== 'available_private'
            || ($inventory['pmta']['missing_referenced_assets'] ?? []) !== []) {
            return false;
        }

        try {
            $reader = new AlphaLinkBulkInstallPrivateAssetReader($this->assetCatalog);
            $baseConfig = $reader->read($assetRoot, 'install/pmta_config');
            $sourceConfig = $reader->read($assetRoot, 'install/pmta_source');
        } catch (\RuntimeException) {
            return false;
        }

        return is_string($baseConfig)
            && str_contains($baseConfig, '__PMTA_LISTENER_PORT__')
            && str_contains($baseConfig, '__PMTA_ADMIN_PORT__')
            && is_string($sourceConfig)
            && str_contains($sourceConfig, '__IP__')
            && preg_match('/^\s*always-allow-relaying\s+yes(?:\s+#.*)?\s*$/m', $sourceConfig) === 1;
    }
}
