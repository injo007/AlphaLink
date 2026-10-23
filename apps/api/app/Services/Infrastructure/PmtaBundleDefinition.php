<?php

namespace App\Services\Infrastructure;

class PmtaBundleDefinition
{
    public const ARCHIVE_SHA256 = '2ea8e4776b705dec618178bddc1ead3cfcb9fcff5a36bc20309dac160eb46e16';

    public const FILES = [
        'pmta/pmta.rpm' => ['name' => 'pmta.rpm', 'size' => 14166816, 'sha256' => 'ac2f9183559e6725e667ea2d9c2e2aab0e6c13adcec46e51d6ffa678425e08c5'],
        'pmta/license' => ['name' => 'license', 'size' => 328, 'sha256' => '320d5223cb961be1ecb33d086b79b7a60f1c75cb2b070b92cf5408124fabc95b'],
        'pmta/config' => ['name' => 'reference-config', 'size' => 14306, 'sha256' => 'd168a185b244843f96f9ea7fa61c85c9175d4006f0378581018e51d856a90e38'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'archive_sha256' => self::ARCHIVE_SHA256,
            'package' => 'PowerMTA',
            'version' => '4.0r8',
            'release' => '201209102127',
            'architecture' => 'x86_64',
            'files' => array_map(
                fn (array $file): array => ['name' => $file['name'], 'size' => $file['size'], 'sha256' => $file['sha256']],
                array_values(self::FILES),
            ),
            'reference_config_deployable' => false,
            'host_compatibility_verified' => false,
            'license_validity_verified' => false,
        ];
    }

    /**
     * @return array{
     *     directory: string,
     *     manifest: array<string, mixed>,
     *     files: array<string, array{size: int, sha256: string}>
     * }
     */
    public static function integrityContract(): array
    {
        $files = [];
        foreach (self::FILES as $file) {
            $files[$file['name']] = ['size' => $file['size'], 'sha256' => $file['sha256']];
        }

        return [
            'directory' => self::ARCHIVE_SHA256,
            'manifest' => self::manifest(),
            'files' => $files,
        ];
    }
}
