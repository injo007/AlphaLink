<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/**
 * Pins the exact DMS install bytes copied over the host-key-verified reference SSH session.
 * These hashes prevent local drift; they do not prove vendor authenticity, RPM validity,
 * license entitlement, or host compatibility.
 */
class DmsReviewedAssetDigestVerifier
{
    /** @var array<string, array{size: int, sha256: string}> */
    public const REVIEWED_DIGESTS = [
        'pmta/4.0/package.rpm' => ['size' => 13906662, 'sha256' => '43e13f7a177b4e2d03a1e9fed87a3faaecaf59c1f0084d9989182b7e2e17b77f'],
        'pmta/4.0/httpd-patch' => ['size' => 5001698, 'sha256' => 'ae550d86e9fad7024ff579e5757ddd0ea2de73dbbb35f0488a8e91b935925c73'],
        'pmta/4.0/license.lic' => ['size' => 330, 'sha256' => 'f1b43323d76f61145dbe3ec3c508e745837409aeef877aa579bb83a5878cdf09'],
        'pmta/4.0/domains.conf' => ['size' => 11482, 'sha256' => '96a4dc3a04fcb072edf3e562377b99d1ab1995a4e6f9ca576712dd5b5e501c7b'],
        'pmta/4.5/package.rpm' => ['size' => 70416545, 'sha256' => '79d6e811fef22e79e3886f3104d10e550ffa622c04915c265631e3d7c91d1de9'],
        'pmta/4.5/pmtad-patch' => ['size' => 34034512, 'sha256' => 'b1a18e52265544fef9624d61b1a69ee167d4b9bcf698d2bd42a7de2f0a7c6437'],
        'pmta/4.5/license.lic' => ['size' => 369, 'sha256' => 'b91aa8b7f4533a8814d55422bcc58c5981e00cf1596ff8a61c42fbb49a0c96b3'],
        'pmta/4.5/domains.conf' => ['size' => 11326, 'sha256' => 'd4813788bb165a50f445fca52b77fb342af07bec3f3b89832ea7015bf0ebadc3'],
        'pmta/5.0/package.rpm' => ['size' => 87076836, 'sha256' => '2b0f14cd245a39ef66706a4be8e92a8f959ca9f8a37ffca934e8c4804cf696a9'],
        'pmta/5.0/pmtad-patch' => ['size' => 35736416, 'sha256' => 'ab98f475b60efb5cc33d5123457c9ea064b3103f9f4e2e0841fad12bf4f55cd3'],
        'pmta/5.0/license.lic' => ['size' => 361, 'sha256' => 'a467b0b579427cb329d2ee5a909733c0eb7bcc823d83bf40be5eb1559de35aac'],
        'pmta/5.0/domains.conf' => ['size' => 11326, 'sha256' => '1cc206a120b287a95733f9a8d03b62c083df2e25fc82519652d3de43eeb28e05'],
        'pmta/shared/source.conf' => ['size' => 443, 'sha256' => '0089794e29807889584ad0c7050766db26b7dcb60ce7d261261a0fd5e17c94e5'],
        'pmta/shared/base.conf' => ['size' => 5519, 'sha256' => '6f2391ca2369d5a26d932cc8cec4281e6310e844a418774ee9ba25fb509cbf81'],
        'optional/tracking/mini-app.zip' => ['size' => 9855996, 'sha256' => 'be4c6fc20e109f57f532715727c9e305521fb6c8c9cad9438da9cd51412f921a'],
        'optional/tracking/cloudflare-ips.conf' => ['size' => 830, 'sha256' => '286bc02aea42c1e2f02dfb90dc6e184b5e0cc6595e20eb71a55929fa8059f80f'],
        'optional/imap/dovecot.conf' => ['size' => 3703, 'sha256' => '9cf50a3953f57d7345a4ef72f740710b1d4e6076b51dc26f93cfc607a15bc4ff'],
        'optional/proxy/squid.conf' => ['size' => 3055, 'sha256' => '599b7848da81016448ad3433572223cd79f07e78178f85754dcfc4b128ab0af3'],
        'optional/dkim/keys.json' => ['size' => 2171, 'sha256' => '1d9b6eef92b7c71e754d9d55eafb55e4e9da4f5d80467184913a518c6fbd363f'],
        'optional/reverse-proxy/nginx-repo.conf' => ['size' => 118, 'sha256' => '64dadbb3b8af4c56ca93aad89115d06b4eef269575ae735bac79386fcd486448'],
        'optional/reverse-proxy/nginx.conf' => ['size' => 652, 'sha256' => 'b98b073620ce7b9989e04c6818699a9eaf81c1d05069145a28949105463c230f'],
    ];

    /** @var array<string, array{pmta_version: int, target_os: string, architecture: string, rpm_metadata_verified: bool, rpm_digests_verified: bool, dnf_plan_resolved: bool}> */
    public const VERIFIED_RPM_TARGETS = [
        '79d6e811fef22e79e3886f3104d10e550ffa622c04915c265631e3d7c91d1de9' => [
            'pmta_version' => 45,
            'target_os' => 'AlmaLinux 9.8 (Olive Jaguar)',
            'architecture' => 'x86_64',
            'rpm_metadata_verified' => true,
            'rpm_digests_verified' => true,
            'dnf_plan_resolved' => true,
        ],
    ];

    /** @param  array<string, array{size: int, sha256: string}>|null  $reviewedDigests */
    public function __construct(
        private readonly ?array $reviewedDigests = null,
        private readonly AlphaLinkInstallerAssetCatalog $catalog = new AlphaLinkInstallerAssetCatalog,
        private readonly ?array $verifiedRpmTargets = null,
    ) {}

    /** @param  array<string, mixed>  $inventory */
    public function verifies(string $root, array $inventory): bool
    {
        $paths = array_keys($inventory['pmta']['assets'] ?? []);
        if (! is_array($paths) || $paths === []) {
            return false;
        }

        foreach ($inventory['selected_options'] ?? [] as $option) {
            array_push($paths, ...array_keys($option['assets'] ?? []));
            foreach ($option['optional_assets'] ?? [] as $path => $status) {
                if ($status === 'present_unverified') {
                    $paths[] = $path;
                }
            }
        }

        $digests = $this->reviewedDigests ?? self::REVIEWED_DIGESTS;
        foreach (array_unique($paths) as $relativePath) {
            if (! is_string($relativePath) || ! $this->verifiesAsset($root, $relativePath, $digests)) {
                return false;
            }
        }

        return true;
    }

    /** Verify one source-named asset against the DMS asset pin set. */
    public function verifiesAsset(string $root, string $sourceIdentifier, ?array $reviewedDigests = null): bool
    {
        if (! str_starts_with($root, DIRECTORY_SEPARATOR) || is_link($root) || ! is_dir($root)) {
            return false;
        }

        $mode = fileperms($root);
        if (! is_int($mode) || ($mode & 0077) !== 0) {
            return false;
        }

        try {
            $assetPath = $this->catalog->pathFor($sourceIdentifier);
        } catch (InvalidArgumentException) {
            return false;
        }

        $digests = $reviewedDigests ?? $this->reviewedDigests ?? self::REVIEWED_DIGESTS;
        $expected = $digests[$assetPath] ?? null;
        if (! is_array($expected)
            || ! is_int($expected['size'] ?? null)
            || $expected['size'] < 1
            || ! is_string($expected['sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expected['sha256'])) {
            return false;
        }

        $current = rtrim($root, DIRECTORY_SEPARATOR);
        foreach (explode('/', $assetPath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                return false;
            }
        }

        $size = is_file($current) ? filesize($current) : false;
        $digest = $size === $expected['size'] ? hash_file('sha256', $current) : false;

        return is_string($digest) && hash_equals($expected['sha256'], $digest);
    }

    public function verifiesRpmForTarget(
        string $root,
        string $sourceIdentifier,
        int $pmtaVersion,
        string $targetOs,
        string $architecture,
    ): bool {
        if (! $this->verifiesAsset($root, $sourceIdentifier)) {
            return false;
        }

        try {
            $assetPath = $this->catalog->pathFor($sourceIdentifier);
        } catch (InvalidArgumentException) {
            return false;
        }

        $digests = $this->reviewedDigests ?? self::REVIEWED_DIGESTS;
        $assetDigest = $digests[$assetPath]['sha256'] ?? null;
        $evidence = is_string($assetDigest)
            ? ($this->verifiedRpmTargets ?? self::VERIFIED_RPM_TARGETS)[$assetDigest] ?? null
            : null;

        return is_array($evidence)
            && ($evidence['pmta_version'] ?? null) === $pmtaVersion
            && is_string($evidence['target_os'] ?? null)
            && strtolower(trim($evidence['target_os'])) === strtolower(trim($targetOs))
            && is_string($evidence['architecture'] ?? null)
            && strtolower(trim($evidence['architecture'])) === strtolower(trim($architecture))
            && ($evidence['rpm_metadata_verified'] ?? false) === true
            && ($evidence['rpm_digests_verified'] ?? false) === true
            && ($evidence['dnf_plan_resolved'] ?? false) === true;
    }
}
