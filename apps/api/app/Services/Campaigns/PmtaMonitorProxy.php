<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class PmtaMonitorProxy
{
    /** @var list<string> */
    private const MONITOR_PAGES = ['', 'status', 'queues', 'domains', 'vmtas', 'jobs', 'logs'];

    /** @var list<string> */
    private const MONITOR_ASSET_EXTENSIONS = ['css', 'gif', 'png', 'jpg', 'jpeg', 'webp', 'bmp'];

    public function get(ManagedServer $server, int $port, string $path, string $workspaceId): Response
    {
        $path = trim($path, '/');
        abort_unless(in_array($path, self::MONITOR_PAGES, true) || $this->isStaticAsset($path), 404);

        $ip = (string) $server->ip_address;
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP) !== false, 404);
        $url = rtrim(PmtaPanelUrl::forServer($server, $port), '/').'/'.($path === '' ? '' : $path);
        $remoteHost = (string) parse_url($url, PHP_URL_HOST);

        try {
            $remote = Http::connectTimeout(3)
                ->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable $exception) {
            return response('PMTA monitor is unavailable.', 502, ['Cache-Control' => 'no-store']);
        }

        if (! $remote->successful()) {
            return response('PMTA monitor is unavailable.', 502, ['Cache-Control' => 'no-store']);
        }

        if ($this->isStaticAsset($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $contentType = match ($extension) {
                'css' => 'text/css; charset=UTF-8',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'bmp' => 'image/bmp',
                default => 'image/gif',
            };
            $body = $extension === 'css'
                ? $this->rewriteCssAssetReferences($remote->body(), $path, $workspaceId, (string) $server->getKey(), $remoteHost, $ip)
                : $remote->body();

            return response($body, 200, [
                'Cache-Control' => 'private, max-age=60',
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($this->makeReadOnly($remote->body(), $workspaceId, (string) $server->getKey(), $remoteHost, $ip), 200, [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
            'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    private function makeReadOnly(string $html, string $workspaceId, string $serverId, string $remoteHost, string $serverIp): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '<!doctype html><html><body>PMTA monitor returned an unreadable page.</body></html>';
        }

        foreach (['script', 'form', 'iframe', 'object', 'embed', 'base'] as $tag) {
            $nodes = $document->getElementsByTagName($tag);
            for ($index = $nodes->length - 1; $index >= 0; $index--) {
                $node = $nodes->item($index);
                $node?->parentNode?->removeChild($node);
            }
        }

        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('http-equiv')) === 'refresh') {
                $meta->parentNode?->removeChild($meta);
            }
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on')) {
                    $attributes[] = $attribute->name;
                }
            }
            foreach ($attributes as $attribute) {
                $element->removeAttribute($attribute);
            }

            if ($element->hasAttribute('style')) {
                $element->setAttribute('style', $this->rewriteCssAssetReferences(
                    $element->getAttribute('style'), '', $workspaceId, $serverId, $remoteHost, $serverIp,
                ));
            }

            if (in_array(strtolower($element->tagName), ['button', 'input', 'select', 'textarea'], true)) {
                $element->setAttribute('disabled', 'disabled');
            }

            if ($element->hasAttribute('href')) {
                $this->rewriteLink($element, 'href', $workspaceId, $serverId, $remoteHost, $serverIp);
            }
            if ($element->hasAttribute('src')) {
                $this->rewriteLink($element, 'src', $workspaceId, $serverId, $remoteHost, $serverIp);
            }
        }

        foreach ($document->getElementsByTagName('style') as $style) {
            foreach ($style->childNodes as $child) {
                if ($child instanceof \DOMText) {
                    $child->nodeValue = $this->rewriteCssAssetReferences($child->nodeValue, '', $workspaceId, $serverId, $remoteHost, $serverIp);
                }
            }
        }

        return $document->saveHTML() ?: '<!doctype html><html><body>PMTA monitor returned an unreadable page.</body></html>';
    }

    private function rewriteLink(\DOMElement $element, string $attribute, string $workspaceId, string $serverId, string $remoteHost, string $serverIp): void
    {
        $value = trim($element->getAttribute($attribute));
        $linkHost = parse_url($value, PHP_URL_HOST);
        if ($linkHost !== null && ! in_array(strtolower((string) $linkHost), [strtolower($remoteHost), strtolower($serverIp)], true)) {
            if ($attribute === 'href') {
                $element->setAttribute('href', '#');
                $element->setAttribute('aria-disabled', 'true');
                $element->setAttribute('title', 'External links are unavailable in the read-only monitor.');
            } else {
                $element->removeAttribute($attribute);
            }

            return;
        }
        $remotePath = ltrim((string) parse_url($value, PHP_URL_PATH), '/');
        $isPage = in_array($remotePath, self::MONITOR_PAGES, true);
        $isAsset = $this->isStaticAsset($remotePath);

        if (! $isPage && ! $isAsset) {
            if ($attribute === 'href') {
                $element->setAttribute('href', '#');
                $element->setAttribute('aria-disabled', 'true');
                $element->setAttribute('title', 'This PMTA control is unavailable in the read-only monitor.');
            } else {
                $element->removeAttribute($attribute);
            }

            return;
        }

        $routeParameters = ['workspace' => $workspaceId, 'server' => $serverId];
        if ($remotePath !== '') {
            $routeParameters['monitorPath'] = $remotePath;
        }
        $element->setAttribute($attribute, route('console.pmta-admin.monitor', $routeParameters, false));
        if ($attribute === 'href') {
            $element->removeAttribute('target');
        }
    }

    private function isStaticAsset(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return preg_match('~\A[A-Za-z0-9._/-]{1,240}\z~', $path) === 1
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MONITOR_ASSET_EXTENSIONS, true);
    }

    private function rewriteCssAssetReferences(string $css, string $cssPath, string $workspaceId, string $serverId, string $remoteHost, string $serverIp): string
    {
        // Keep the vendor's visual styles while preventing remote CSS imports
        // and proxying only same-server, static image/style references.
        $css = preg_replace('/@import\b[^;]*;?/i', '', $css) ?? $css;
        $directory = trim(dirname($cssPath), '.\\/');

        return preg_replace_callback('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i', function (array $matches) use ($directory, $workspaceId, $serverId, $remoteHost, $serverIp): string {
            $value = trim($matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : ($matches[3] ?? '')));
            $host = parse_url($value, PHP_URL_HOST);
            if ($host !== null && ! in_array(strtolower((string) $host), [strtolower($remoteHost), strtolower($serverIp)], true)) {
                return 'url("")';
            }

            $path = ltrim((string) parse_url($value, PHP_URL_PATH), '/');
            if (! str_starts_with($value, '/') && $directory !== '') {
                $path = $directory.'/'.$path;
            }
            $path = $this->normalizeStaticAssetPath($path);
            if ($path === null) {
                return 'url("")';
            }

            return 'url("'.route('console.pmta-admin.monitor', [
                'workspace' => $workspaceId,
                'server' => $serverId,
                'monitorPath' => $path,
            ], false).'")';
        }, $css) ?? $css;
    }

    private function normalizeStaticAssetPath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
        $normalized = implode('/', $segments);

        return $this->isStaticAsset($normalized) ? $normalized : null;
    }
}
