<?php

namespace App\Services\AffiliateNetworks;

use RuntimeException;
use ZipArchive;

class DmsSponsorExtensionPackage
{
    private const DIRECTORY = 'dms-reference/sponsor-extension';

    public function extensionId(): string
    {
        $manifest = $this->manifestTemplate();
        $key = base64_decode((string) ($manifest['key'] ?? ''), true);
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The reviewed sponsor helper manifest has no valid public key.');
        }

        $hash = substr(hash('sha256', $key), 0, 32);

        return strtr($hash, '0123456789abcdef', 'abcdefghijklmnop');
    }

    public function createForCurrentOrigin(): string
    {
        $source = storage_path('app/'.self::DIRECTORY.'/extension_tools.zip');
        if (! is_file($source)) {
            throw new RuntimeException('The reviewed DMS sponsor helper bundle is missing from AlphaLink storage.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'alphalink-sponsor-helper-');
        if (! is_string($tempPath) || ! copy($source, $tempPath)) {
            if (is_string($tempPath) && is_file($tempPath)) {
                unlink($tempPath);
            }
            throw new RuntimeException('AlphaLink could not prepare the sponsor helper download.');
        }

        $archive = new ZipArchive;
        if ($archive->open($tempPath) !== true) {
            unlink($tempPath);
            throw new RuntimeException('AlphaLink could not open the reviewed sponsor helper bundle.');
        }

        try {
            $manifest = $this->manifestTemplate();
            $manifest['externally_connectable']['matches'] = [$this->currentOriginPattern()];
            $encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! $archive->addFromString('extension/manifest.json', $encodedManifest, ZipArchive::FL_OVERWRITE)) {
                throw new RuntimeException('AlphaLink could not add its origin-scoped manifest to the sponsor helper.');
            }
            if (! $archive->close()) {
                throw new RuntimeException('AlphaLink could not finalize the sponsor helper download.');
            }
        } catch (\Throwable $exception) {
            $archive->close();
            unlink($tempPath);
            throw $exception;
        }

        return $tempPath;
    }

    /** @return array<string, mixed> */
    private function manifestTemplate(): array
    {
        $manifestPath = storage_path('app/'.self::DIRECTORY.'/extension_manifest.txt');
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The reviewed DMS sponsor helper manifest is missing from AlphaLink storage.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ! is_array($manifest['externally_connectable']['matches'] ?? null)) {
            throw new RuntimeException('The reviewed DMS sponsor helper manifest is invalid.');
        }

        return $manifest;
    }

    private function currentOriginPattern(): string
    {
        $parts = parse_url((string) config('app.url'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('AlphaLink APP_URL is not a valid browser origin for the sponsor helper.');
        }

        return $scheme.'://'.$host.'/*';
    }
}
