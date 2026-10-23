<?php

namespace App\Services\Infrastructure;

class PmtaBundleIntegrityVerifier
{
    /**
     * @param array{
     *     directory: string,
     *     manifest: array<string, mixed>,
     *     files: array<string, array{size: int, sha256: string}>
     * }|null $contract Defaults to the reviewed PowerMTA 4.0r8 bundle contract.
     */
    public function __construct(private readonly ?array $contract = null) {}

    public function isVerified(?string $bundleRoot): bool
    {
        $contract = $this->contract ?? PmtaBundleDefinition::integrityContract();
        if (! is_string($bundleRoot) || $bundleRoot === '') {
            return false;
        }

        $root = rtrim($bundleRoot, DIRECTORY_SEPARATOR);
        if ($root === '' || ! $this->isPrivateDirectory($root) || ! $this->isPrivateDirectory(dirname($root))) {
            return false;
        }

        $bundleDirectoryName = $contract['directory'] ?? null;
        $expectedManifest = $contract['manifest'] ?? null;
        $expectedFiles = $contract['files'] ?? null;
        if (! is_string($bundleDirectoryName) || preg_match('/\A[a-f0-9]{64}\z/', $bundleDirectoryName) !== 1 || ! is_array($expectedManifest) || ! is_array($expectedFiles)) {
            return false;
        }

        $bundleDirectory = $root.DIRECTORY_SEPARATOR.$bundleDirectoryName;
        if (! $this->isPrivateDirectory($bundleDirectory)) {
            return false;
        }

        $manifestPath = $bundleDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        if (! $this->isPrivateFile($manifestPath)) {
            return false;
        }

        $manifestJson = @file_get_contents($manifestPath);
        if (! is_string($manifestJson)) {
            return false;
        }

        try {
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($manifest) || $this->canonicalize($manifest) !== $this->canonicalize($expectedManifest)) {
            return false;
        }

        $entries = @scandir($bundleDirectory);
        if (! is_array($entries)) {
            return false;
        }
        $actualNames = array_values(array_diff($entries, ['.', '..']));
        $expectedNames = array_merge(['manifest.json'], array_keys($expectedFiles));
        sort($actualNames);
        sort($expectedNames);
        if ($actualNames !== $expectedNames) {
            return false;
        }

        foreach ($expectedFiles as $name => $expectedFile) {
            if (! is_string($name) || preg_match('/\A[a-zA-Z0-9._-]+\z/', $name) !== 1 || ! is_array($expectedFile)) {
                return false;
            }

            $path = $bundleDirectory.DIRECTORY_SEPARATOR.$name;
            if (! $this->isPrivateFile($path) || filesize($path) !== ($expectedFile['size'] ?? null)) {
                return false;
            }

            $actualHash = hash_file('sha256', $path);
            if (! is_string($actualHash) || ! is_string($expectedFile['sha256'] ?? null) || ! hash_equals($expectedFile['sha256'], $actualHash)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateDirectory(string $path): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path) || ! is_dir($path)) {
            return false;
        }

        $resolvedPath = realpath($path);
        $permissions = fileperms($path);

        return $resolvedPath !== false
            && $resolvedPath === rtrim($path, DIRECTORY_SEPARATOR)
            && $permissions !== false
            && ($permissions & 0077) === 0;
    }

    private function isPrivateFile(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }

        $permissions = fileperms($path);

        return $permissions !== false && ($permissions & 0777) === 0600;
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item, $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
