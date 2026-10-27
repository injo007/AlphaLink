<?php

namespace App\Services\AffiliateNetworks;

use Throwable;

class DmsNewHitPathCreativeImporter
{
    private const MAX_IMAGE_BYTES = 2_097_152;

    private const VALID_ENCODINGS = ['UTF-8', 'ASCII', 'ISO-8859-1', 'UTF-16', 'CP1252'];

    public function __construct(private readonly DmsAffiliateProviderHttp $http) {}

    /**
     * Fetch NewHitPath email bodies and image attachments using DMS's HTML and link rules.
     * The API token is sent only to the configured provider host, never to an image CDN.
     *
     * @return list<array{name: string, content: string, images: list<array{references: list<string>, bytes: string, extension: string}>}>
     */
    public function read(string $apiUrl, int $offerId, string $apiKey, string $providerHost): array
    {
        try {
            $response = $this->http->get(
                sprintf('%s/campaigns/%d/emails', $apiUrl, $offerId),
                $this->apiHeaders($apiKey),
            );
        } catch (Throwable) {
            return [];
        }

        $emails = $response->successful() ? $response->json('data') : null;
        if (! is_array($emails)) {
            return [];
        }

        $creatives = [];
        $processedHashes = [];
        foreach ($emails as $index => $email) {
            if (! is_array($email) || ! is_string($email['body'] ?? null) || $email['body'] === '') {
                continue;
            }

            $content = $this->toUtf8($email['body']);
            if (! preg_match('/<(html|a|img)[^>]*>/i', $content)) {
                continue;
            }

            $content = preg_replace('/^.*?(<!DOCTYPE|<html)/is', '$1', $content) ?? $content;
            $hash = md5($content);
            if (in_array($hash, $processedHashes, true)) {
                continue;
            }
            $processedHashes[] = $hash;

            $images = [];
            foreach (is_array($email['images'] ?? null) ? $email['images'] : [] as $image) {
                if (! is_array($image)
                    || ! is_string($image['name'] ?? null)
                    || trim($image['name']) === ''
                    || ! is_string($image['url'] ?? null)
                    || filter_var($image['url'], FILTER_VALIDATE_URL) === false) {
                    continue;
                }

                $headers = $this->sameHost($image['url'], $providerHost) ? $this->apiHeaders($apiKey) : [];
                $bytes = $this->http->getBytes($image['url'], $headers, self::MAX_IMAGE_BYTES);
                $extension = is_string($bytes) ? $this->imageExtension($bytes) : null;
                if ($bytes === null || $extension === null) {
                    continue;
                }

                $reference = $image['name'];
                $images[] = [
                    'references' => [$reference],
                    'bytes' => $bytes,
                    'extension' => $extension,
                ];
            }

            $content = $this->rewriteLinks($content);
            $creatives[] = [
                'name' => 'newhitpath-'.$offerId.'-'.(string) $index,
                'content' => $content,
                'images' => $images,
            ];
        }

        return $creatives;
    }

    /** @return array<string, string> */
    public function apiHeaders(string $apiKey): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$apiKey,
        ];
    }

    private function sameHost(string $url, string $providerHost): bool
    {
        $imageHost = parse_url($url, PHP_URL_HOST);

        return is_string($imageHost)
            && strtolower(rtrim($imageHost, '.')) === strtolower(rtrim($providerHost, '.'));
    }

    private function toUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, self::VALID_ENCODINGS, true) ?: 'UTF-8';

        return $encoding === 'UTF-8' ? $content : mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    private function imageExtension(string $bytes): ?string
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

    private function rewriteLinks(string $content): string
    {
        preg_match_all('/<a\s+[^>]*href=["\'](https?:\/\/[^\s<>"\']+)(?:\?[^\s<>"\']*)?["\']/i', $content, $matches);
        $totalLinks = count($matches[1] ?? []);

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

        return preg_replace('/[\\r\\n]+/', '\\n', trim($content)) ?? trim($content);
    }
}
