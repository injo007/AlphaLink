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
    private const STATIC_ASSETS = ['css.css', 'logo.gif'];

    public function get(ManagedServer $server, int $port, string $path, string $workspaceId): Response
    {
        $path = trim($path, '/');
        abort_unless(in_array($path, [...self::MONITOR_PAGES, ...self::STATIC_ASSETS], true), 404);

        $ip = (string) $server->ip_address;
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP) !== false, 404);
        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip;
        $url = 'http://'.$host.':'.$port.'/'.($path === '' ? '' : $path);

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

        if (in_array($path, self::STATIC_ASSETS, true)) {
            $contentType = $path === 'css.css' ? 'text/css; charset=UTF-8' : 'image/gif';

            return response($remote->body(), 200, [
                'Cache-Control' => 'private, max-age=60',
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($this->makeReadOnly($remote->body(), $workspaceId, (string) $server->getKey()), 200, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function makeReadOnly(string $html, string $workspaceId, string $serverId): string
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
                if (str_starts_with($name, 'on') || $name === 'style') {
                    $attributes[] = $attribute->name;
                }
            }
            foreach ($attributes as $attribute) {
                $element->removeAttribute($attribute);
            }

            if (in_array(strtolower($element->tagName), ['button', 'input', 'select', 'textarea'], true)) {
                $element->setAttribute('disabled', 'disabled');
            }

            if ($element->hasAttribute('href')) {
                $this->rewriteLink($element, 'href', $workspaceId, $serverId);
            }
            if ($element->hasAttribute('src')) {
                $this->rewriteLink($element, 'src', $workspaceId, $serverId);
            }
        }

        return $document->saveHTML() ?: '<!doctype html><html><body>PMTA monitor returned an unreadable page.</body></html>';
    }

    private function rewriteLink(\DOMElement $element, string $attribute, string $workspaceId, string $serverId): void
    {
        $value = trim($element->getAttribute($attribute));
        if (parse_url($value, PHP_URL_HOST) !== null) {
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
        $isAsset = in_array($remotePath, self::STATIC_ASSETS, true);

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
}
