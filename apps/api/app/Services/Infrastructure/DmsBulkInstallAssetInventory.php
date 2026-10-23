<?php

namespace App\Services\Infrastructure;

/**
 * Checks local file presence against the paths DMS actually consults.
 * Presence is not proof of artifact validity and this class never executes files.
 */
class DmsBulkInstallAssetInventory
{
    public function __construct(private readonly DmsBulkInstallInstallerMatrix $matrix) {}

    /**
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return array<string, mixed>
     */
    public function inspect(string $osPrettyName, int $pmtaVersion, array $options, string $assetRoot): array
    {
        $plan = $this->matrix->plan($osPrettyName, $pmtaVersion, $options);
        $rootStatus = $this->rootStatus($assetRoot);

        if ($rootStatus !== 'available_private') {
            return [
                'asset_root_status' => $rootStatus,
                'asset_presence_complete' => false,
                'pmta' => [
                    'dms_would_skip_version_setup' => null,
                    'version_setup_behavior' => 'unknown_without_private_asset_root',
                    'assets' => [],
                    'missing_referenced_assets' => [],
                ],
                'selected_options' => [],
                'note' => 'File presence is not artifact validation; installation remains disabled.',
            ];
        }

        $pmtaAssets = [];
        foreach ($plan['pmta']['referenced_assets'] as $path) {
            $pmtaAssets[$path] = $this->inspectFile($assetRoot, $path);
        }

        $rpmPath = $plan['pmta']['referenced_assets'][0];
        $patchPath = $plan['pmta']['referenced_assets'][1];
        $rpmExists = $pmtaAssets[$rpmPath] === 'present_unverified';
        $patchExists = $pmtaAssets[$patchPath] === 'present_unverified';
        $dmsWouldSkip = $this->matrix->dmsSkipsPmtaVersionSetup($rpmExists, $patchExists);
        $missingPmtaAssets = array_keys(array_filter($pmtaAssets, fn (string $status): bool => $status !== 'present_unverified'));
        $selectedOptions = [];
        $selectedRequirementsPresent = true;

        foreach ($plan['selected_option_requirements'] as $option => $requirement) {
            $expectedAssets = $requirement['expected_assets'] ?? [];
            $assets = [];
            foreach ($expectedAssets as $path) {
                $assets[$path] = $this->inspectFile($assetRoot, $path);
            }

            $missing = array_keys(array_filter($assets, fn (string $status): bool => $status !== 'present_unverified'));
            if ($missing !== []) {
                $selectedRequirementsPresent = false;
            }

            $optionalAssets = [];
            foreach ($requirement['optional_assets'] ?? [] as $path) {
                $optionalAssets[$path] = $this->inspectFile($assetRoot, $path);
            }

            $selectedOptions[$option] = [
                'assets' => $assets,
                'missing_assets' => $missing,
                'optional_assets' => $optionalAssets,
                'dms_effect_if_missing' => $requirement['when_mini_app_missing']
                    ?? $requirement['when_asset_missing']
                    ?? $requirement['when_key_missing']
                    ?? $requirement['behavior']
                    ?? null,
            ];
        }

        return [
            'asset_root_status' => $rootStatus,
            // This is presence only. Compatibility, licensing, provenance and safe deployability remain unverified.
            'asset_presence_complete' => $missingPmtaAssets === [] && $selectedRequirementsPresent,
            'pmta' => [
                'dms_would_skip_version_setup' => $dmsWouldSkip,
                'version_setup_behavior' => $dmsWouldSkip ? 'dms_skips_when_rpm_and_patch_are_both_absent' : 'dms_attempts_version_setup',
                'assets' => $pmtaAssets,
                'missing_referenced_assets' => $missingPmtaAssets,
            ],
            'selected_options' => $selectedOptions,
            'note' => 'File presence is not artifact validation; installation remains disabled.',
        ];
    }

    private function rootStatus(?string $root): string
    {
        if (! is_string($root) || trim($root) === '') {
            return 'not_configured';
        }

        if (! str_starts_with($root, DIRECTORY_SEPARATOR) || is_link($root) || ! is_dir($root)) {
            return 'unavailable_or_unsafe';
        }

        $mode = fileperms($root);
        if (! is_int($mode) || ($mode & 0077) !== 0) {
            return 'not_private';
        }

        return 'available_private';
    }

    private function inspectFile(string $root, string $relativePath): string
    {
        $current = rtrim($root, DIRECTORY_SEPARATOR);
        $segments = explode('/', $relativePath);

        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'unsafe_path';
            }

            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                return 'unsafe_symlink';
            }
            if (! file_exists($current)) {
                return 'missing';
            }

            $isFinal = $index === array_key_last($segments);
            if ($isFinal && (! is_file($current) || (filesize($current) ?: 0) < 1)) {
                return 'invalid_file';
            }
            if (! $isFinal && ! is_dir($current)) {
                return 'invalid_path';
            }
        }

        return 'present_unverified';
    }
}
