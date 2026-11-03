<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/** Describes the source-backed DMS OS/PMTA branches and their local asset needs. */
class AlphaLinkBulkInstallInstallerMatrix
{
    public function __construct(private readonly AlphaLinkInstallPlatform $platform) {}

    /**
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return array<string, mixed>
     */
    public function plan(string $osPrettyName, int $pmtaVersion, array $options): array
    {
        $family = $this->platform->installerFamily($osPrettyName);

        if ($family === null) {
            throw new InvalidArgumentException('The installer requires a supported CentOS 7 or 9 release.');
        }

        if (! in_array($pmtaVersion, [40, 45, 50], true)) {
            throw new InvalidArgumentException('The installer supports PMTA versions 40, 45, and 50.');
        }

        $this->validateOptions($options);

        $versionDirectory = "install/pmta_{$pmtaVersion}";
        $isLegacy = $pmtaVersion === 40;
        $packageManager = $family === 'centos7' ? 'yum' : 'dnf';
        $patchName = $isLegacy ? 'pmtahttp_patch' : 'pmtad_patch';
        $patchTarget = $isLegacy ? '/usr/sbin/pmtahttpd' : '/usr/sbin/pmtad';
        $reverseProxyAssets = [];

        if (! $isLegacy) {
            $reverseProxyAssets[] = $family === 'centos7'
                ? 'install/nginx_repo'
                : 'install/nginx_config';
        }

        return [
            'os_family' => $family,
            'package_manager' => $packageManager,
            'pmta' => [
                'version' => $pmtaVersion,
                'referenced_assets' => [
                    "{$versionDirectory}/pmta.rpm",
                    "{$versionDirectory}/{$patchName}",
                    "{$versionDirectory}/pmta.lic",
                    "{$versionDirectory}/pmta_domains",
                    'install/pmta_source',
                    'install/pmta_config',
                    ...$reverseProxyAssets,
                ],
                'patch_target' => $patchTarget,
                'admin_http_port_multiplier' => $isLegacy ? 1 : 2,
                'reverse_proxy' => $isLegacy ? null : 'nginx',
                'extra_config_lines' => match ($pmtaVersion) {
                    45 => ['http-redirect-to-https false'],
                    50 => ['http-redirect-to-https false', 'http-csrf-protection false'],
                    default => [],
                },
                // DMS uses AND here: the PMTA method returns early only when both files are absent.
                'skip_gate' => ['rpm_and_patch_both_absent' => true],
            ],
            'selected_option_requirements' => $this->selectedOptionRequirements($options),
        ];
    }

    /** @param array<string, bool> $options */
    private function selectedOptionRequirements(array $options): array
    {
        $requirements = [];

        if ($options['tracking']) {
            $requirements['tracking'] = [
                'expected_assets' => ['install/mini_app.zip'],
                'optional_assets' => ['install/cf_ips.conf'],
                'when_mini_app_missing' => 'Apache is configured, but the tracking mini-app is not uploaded or configured.',
            ];
        }

        if ($options['proxy']) {
            $requirements['proxy'] = [
                'expected_assets' => ['install/squid_centos.conf'],
                'when_asset_missing' => 'Squid is not installed or enabled when its configuration asset is missing.',
            ];
        }

        if ($options['imap']) {
            $requirements['imap'] = [
                'expected_assets' => ['install/dovecot_centos.conf'],
                'when_asset_missing' => 'Dovecot is not installed or enabled when its configuration asset is missing.',
            ];
        }

        if ($options['dkim']) {
            $requirements['dkim'] = [
                'expected_assets' => [],
                'optional_assets' => ['install/dkim.json'],
                'when_key_missing' => 'AlphaLink generates and encrypts a workspace-specific DKIM key before host changes.',
            ];
        }

        if ($options['https']) {
            $requirements['https'] = [
                'local_assets' => [],
                'behavior' => 'The installer adds Certbot, requests a non-redirecting certificate, and schedules renewal.',
            ];
        }

        return $requirements;
    }

    /** Mirrors the DMS version-specific method's RPM/patch early-return guard. */
    public function pmtaVersionSetupWouldBeSkipped(bool $rpmExists, bool $patchExists): bool
    {
        return ! $rpmExists && ! $patchExists;
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        $expected = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];
        $keys = array_keys($options);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new InvalidArgumentException('The installer requires all seven options.');
        }

        foreach ($options as $option) {
            if (! is_bool($option)) {
                throw new InvalidArgumentException('Installer options must be booleans.');
            }
        }
    }
}
