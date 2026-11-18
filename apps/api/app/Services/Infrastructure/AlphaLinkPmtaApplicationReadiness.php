<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\Workspace;
use App\Services\Campaigns\PmtaMonitorProxy;
use App\Services\Campaigns\PmtaProxySettings;
use App\Services\Campaigns\PmtaQueueAdmin;
use RuntimeException;

/** Confirms the installed PMTA monitor and command paths from the AlphaLink app. */
class AlphaLinkPmtaApplicationReadiness
{
    public function __construct(
        private readonly PmtaProxySettings $proxySettings,
        private readonly PmtaMonitorProxy $monitor,
        private readonly PmtaQueueAdmin $commands,
    ) {}

    public function verify(Workspace $workspace, ManagedServer $server, int $port): void
    {
        $proxy = $this->proxySettings->forWorkspace($workspace);
        if ($proxy === null) {
            throw new RuntimeException('Configure a valid PMTA relay proxy host and port before installing PMTA.');
        }

        $monitor = $this->monitor->get(
            $server,
            $port,
            '',
            (string) $workspace->getKey(),
            $proxy['host'],
            $proxy['port'],
            $proxy['username'],
            $proxy['password'],
            'ip',
        );
        if ($monitor->getStatusCode() < 200 || $monitor->getStatusCode() >= 300) {
            throw new RuntimeException('PMTA monitor readiness through the configured relay failed with HTTP '.$monitor->getStatusCode().'.');
        }

        $this->verifyMonitorStylesheets($monitor->getContent(), $workspace, $server, $port, $proxy);
        $this->commands->verifyReadOnlyStatusAccess($server, $port, $proxy['url']);
    }

    /** @param array{host: string, port: int, username: string, password: string, url: string} $proxy */
    private function verifyMonitorStylesheets(string|false $html, Workspace $workspace, ManagedServer $server, int $port, array $proxy): void
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = is_string($html) && $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('PMTA monitor returned unreadable HTML through the configured relay.');
        }

        $stylesheetCount = 0;
        foreach ($document->getElementsByTagName('link') as $link) {
            $relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (! in_array('stylesheet', $relations, true)) {
                continue;
            }

            $query = parse_url($link->getAttribute('href'), PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $parameters);
            $asset = $parameters['asset'] ?? null;
            if (! is_string($asset) || strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'css') {
                continue;
            }

            $stylesheetCount++;
            $stylesheet = $this->monitor->get(
                $server,
                $port,
                $asset,
                (string) $workspace->getKey(),
                $proxy['host'],
                $proxy['port'],
                $proxy['username'],
                $proxy['password'],
                'ip',
            );
            $contentType = strtolower((string) $stylesheet->headers->get('Content-Type'));
            if ($stylesheet->getStatusCode() < 200 || $stylesheet->getStatusCode() >= 300
                || ! str_starts_with($contentType, 'text/css')
                || trim((string) $stylesheet->getContent()) === '') {
                throw new RuntimeException('PMTA monitor stylesheet readiness through the configured relay failed.');
            }
        }

        if ($stylesheetCount === 0) {
            throw new RuntimeException('PMTA monitor did not expose a stylesheet through the configured relay.');
        }
    }
}
