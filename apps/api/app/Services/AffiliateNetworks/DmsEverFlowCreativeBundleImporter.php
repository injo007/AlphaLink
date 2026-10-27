<?php

namespace App\Services\AffiliateNetworks;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DmsEverFlowCreativeBundleImporter
{
    private const MAX_BUNDLE_BYTES = 10_485_760;

    private const MAX_UNCOMPRESSED_BYTES = 20_971_520;

    private const MAX_ENTRY_BYTES = 2_097_152;

    private const MAX_EXTERNAL_IMAGE_BYTES = 10_485_760;

    private const MAX_ARCHIVE_ENTRIES = 250;

    private const VALID_TEXT_EXTENSIONS = ['html', 'htm', 'txt'];

    private const VALID_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    private const VALID_ENCODINGS = ['UTF-8', 'ASCII', 'ISO-8859-1', 'UTF-16', 'CP1252'];

    /**
     * Fetch and parse the DMS EverFlow creative bundle without extracting archive paths.
     * Unsafe URLs, invalid archives, and provider download failures follow DMS behavior by
     * leaving the offer import intact and returning no creatives.
     *
     * @return list<array{name: string, content: string, images: list<array{references: list<string>, bytes: string, extension: string}>}>
     */
    public function fetch(string $url): array
    {
        $archive = $this->downloadPublicHttps($url, self::MAX_BUNDLE_BYTES);
        if ($archive === null) {
            return [];
        }

        return $this->parseArchive($archive);
    }

    /**
     * @param  list<array{name: string, content: string, images: list<array{references: list<string>, bytes: string, extension: string}>}>  $creatives
     */
    public function persist(Workspace $workspace, WorkspaceOffer $offer, ?User $user, array $creatives): void
    {
        if ($creatives === []) {
            return;
        }

        $disk = Storage::disk('local');
        $storedPaths = [];
        $existingHashes = $offer->assets()
            ->where('type', 'html')
            ->pluck('value')
            ->mapWithKeys(static fn (string $value): array => [md5($value) => true]);

        try {
            foreach ($creatives as $creative) {
                $content = $creative['content'];

                foreach ($creative['images'] as $image) {
                    $path = sprintf(
                        'offers/%s/%d/assets/creative/%s.%s',
                        $workspace->getKey(),
                        $offer->getKey(),
                        str()->uuid(),
                        $image['extension'],
                    );

                    if (! $disk->put($path, $image['bytes'])) {
                        throw new RuntimeException('Unable to save an imported offer image.');
                    }
                    $storedPaths[] = $path;

                    $asset = $offer->assets()->create([
                        'workspace_id' => $workspace->getKey(),
                        'type' => 'creative',
                        'value' => $path,
                        'created_by' => $user?->getKey(),
                    ]);
                    $publicUrl = route('public.offer-assets.file', [
                        $workspace->getKey(),
                        $offer->getKey(),
                        $asset->getKey(),
                    ], true);

                    foreach ($image['references'] as $reference) {
                        $content = str_replace($reference, $publicUrl, $content);
                    }
                }

                $hash = md5($content);
                if ($existingHashes->has($hash)) {
                    continue;
                }

                $offer->assets()->create([
                    'workspace_id' => $workspace->getKey(),
                    'type' => 'html',
                    'value' => $content,
                    'created_by' => $user?->getKey(),
                ]);
                $existingHashes->put($hash, true);
            }
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                $disk->delete($storedPaths);
            }

            throw $exception;
        }
    }

    /**
     * @return list<array{name: string, content: string, images: list<array{references: list<string>, bytes: string, extension: string}>}>
     */
    private function parseArchive(string $archive): array
    {
        if ($archive === '') {
            return [];
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'alphalink-everflow-');
        if ($temporaryFile === false) {
            return [];
        }

        $zip = new ZipArchive;
        $isOpen = false;
        try {
            if (file_put_contents($temporaryFile, $archive) === false || $zip->open($temporaryFile) !== true) {
                return [];
            }
            $isOpen = true;

            if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                return [];
            }

            $entries = [];
            $totalUncompressedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null) || ! is_int($stat['size'] ?? null)) {
                    continue;
                }

                $name = $stat['name'];
                if (! $this->isSafeArchivePath($name) || str_ends_with($name, '/')) {
                    continue;
                }

                $attributes = 0;
                $operatingSystem = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    && $operatingSystem === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    continue;
                }

                $size = $stat['size'];
                $totalUncompressedBytes += $size;
                if ($size < 0 || $size > self::MAX_ENTRY_BYTES || $totalUncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    continue;
                }

                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($extension, [...self::VALID_TEXT_EXTENSIONS, ...self::VALID_IMAGE_EXTENSIONS], true)) {
                    continue;
                }

                $bytes = $zip->getFromIndex($index, self::MAX_ENTRY_BYTES + 1);
                if (! is_string($bytes) || strlen($bytes) !== $size) {
                    continue;
                }

                $entries[] = ['name' => $name, 'extension' => $extension, 'bytes' => $bytes];
            }

            return $this->mapCreatives($entries);
        } catch (Throwable) {
            return [];
        } finally {
            if ($isOpen) {
                $zip->close();
            }
            @unlink($temporaryFile);
        }
    }

    /**
     * @param  list<array{name: string, extension: string, bytes: string}>  $entries
     * @return list<array{name: string, content: string, images: list<array{references: list<string>, bytes: string, extension: string}>}>
     */
    private function mapCreatives(array $entries): array
    {
        $imagesByBasename = [];
        foreach ($entries as $entry) {
            $imageExtension = $this->supportedImageExtension($entry['bytes']);
            if ($imageExtension !== null && in_array($entry['extension'], self::VALID_IMAGE_EXTENSIONS, true)) {
                $imagesByBasename[basename($entry['name'])] ??= $entry + ['detected_extension' => $imageExtension];
            }
        }

        $creatives = [];
        $processedHashes = [];
        $externalImageCount = 0;
        $externalImageBytes = 0;
        foreach ($entries as $entry) {
            if (! in_array($entry['extension'], self::VALID_TEXT_EXTENSIONS, true)) {
                continue;
            }

            $content = $this->toUtf8($entry['bytes']);
            if (! preg_match('/<(html|a|img)[^>]*>/i', $content)) {
                continue;
            }

            $content = preg_replace('/^.*?(<!DOCTYPE|<html)/is', '$1', $content) ?? $content;
            $hash = md5($content);
            if (in_array($hash, $processedHashes, true)) {
                continue;
            }
            $processedHashes[] = $hash;

            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $imageMatches);
            preg_match_all('/background-image\s*:\s*url\s*\(["\']?([^"\')]+)["\']?\)/i', $content, $backgroundMatches);
            $references = array_values(array_unique(array_filter(array_map(
                static fn (string $reference): string => trim($reference),
                array_merge($imageMatches[1] ?? [], $backgroundMatches[1] ?? []),
            ))));

            $creativeImages = [];
            foreach ($references as $reference) {
                $extension = $this->imageExtension($reference);
                if ($extension === null) {
                    continue;
                }

                $image = $imagesByBasename[basename((string) parse_url($reference, PHP_URL_PATH))] ?? null;
                if ($image === null
                    && filter_var($reference, FILTER_VALIDATE_URL)
                    && $externalImageCount < 20
                    && $externalImageBytes < self::MAX_EXTERNAL_IMAGE_BYTES) {
                    $remainingImageBytes = self::MAX_EXTERNAL_IMAGE_BYTES - $externalImageBytes;
                    $bytes = $this->downloadPublicHttps($reference, min(self::MAX_ENTRY_BYTES, $remainingImageBytes));
                    $detectedExtension = $bytes !== null ? $this->supportedImageExtension($bytes) : null;
                    if ($bytes !== null && $detectedExtension !== null) {
                        $image = ['bytes' => $bytes, 'extension' => $detectedExtension];
                        $externalImageCount++;
                        $externalImageBytes += strlen($bytes);
                    }
                }

                if (! is_array($image)) {
                    continue;
                }

                $creativeImages[] = [
                    'references' => [$reference],
                    'bytes' => $image['bytes'],
                    'extension' => $image['detected_extension'] ?? $image['extension'],
                ];
            }

            preg_match_all('/<a\s+[^>]*href=["\'](https?:\/\/[^\s<>"\']+)(?:\?[^\s<>"\']*)?["\']/i', $content, $links);
            $totalLinks = count($links[1] ?? []);
            if ($totalLinks > 0) {
                $content = preg_replace_callback(
                    '/(<a\s+[^>]*href=["\'])(https?:\/\/[^\s<>"\']+)(?:\?[^\s<>"\']*)?(["\'])/i',
                    static function (array $match) use (&$totalLinks): string {
                        $replacement = --$totalLinks === 0
                            ? 'http://[domain]/[rndUN_10]u[token]'
                            : 'http://[domain]/[rndUN_10]r[token]';

                        return $match[1].$replacement.$match[3];
                    },
                    $content,
                ) ?? $content;
            }

            $content = preg_replace("/[\r\n]+/", "\n", trim($content)) ?? trim($content);
            $creatives[] = [
                'name' => basename($entry['name']),
                'content' => $content,
                'images' => $creativeImages,
            ];
        }

        return $creatives;
    }

    private function toUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, self::VALID_ENCODINGS, true) ?: 'UTF-8';

        return $encoding === 'UTF-8' ? $content : mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    private function imageExtension(string $reference): ?string
    {
        $path = parse_url($reference, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $reference, PATHINFO_EXTENSION));

        return in_array($extension, self::VALID_IMAGE_EXTENSIONS, true) ? $extension : null;
    }

    private function supportedImageExtension(string $bytes): ?string
    {
        $image = @getimagesizefromstring($bytes);
        if (! is_array($image)) {
            return null;
        }

        return match ($image['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function isSafeArchivePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function downloadPublicHttps(string $url, int $maxBytes): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! $this->hostResolvesOnlyPublicly($parts['host'])) {
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->get($url);

            $contentLength = $response->header('Content-Length');
            if (! $response->successful() || (is_numeric($contentLength) && (int) $contentLength > $maxBytes)) {
                return null;
            }

            $stream = $response->toPsrResponse()->getBody();
            $contents = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, $maxBytes + 1 - strlen($contents)));
                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;
                if (strlen($contents) > $maxBytes) {
                    return null;
                }
            }

            return $contents;
        } catch (Throwable) {
            return null;
        }
    }

    private function hostResolvesOnlyPublicly(string $host): bool
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        if (preg_match('/(?:^|\.)(?:localhost|local|internal|test)$/i', $host)) {
            return false;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return false;
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses !== [] && collect($addresses)->every(fn (string $address): bool => $this->isPublicIp($address));
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
