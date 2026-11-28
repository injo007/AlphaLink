<?php

namespace App\Services\Campaigns;

use App\Jobs\ProcessCampaignSendRun;
use App\Models\CampaignSendRun;
use App\Models\ManagedServer;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CampaignAutoFailoverService
{
    public function __construct(
        private readonly PmtaQueueAdmin $pmtaQueueAdmin,
        private readonly PmtaProxySettings $proxySettings,
    ) {}

    public function isEnabled(CampaignSendRun $run): bool
    {
        return (bool) data_get($run->execution_options, 'auto_failover_enabled', false);
    }

    /**
     * @return array<string>
     */
    public function getDisabledDomains(CampaignSendRun $run): array
    {
        return array_values(array_unique(array_filter(array_map('strtolower', (array) data_get($run->execution_options, 'disabled_domains', [])))));
    }

    /**
     * @return array<string, int>
     */
    public function getDomainErrorCounts(CampaignSendRun $run): array
    {
        return (array) data_get($run->execution_options, 'domain_error_counts', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFailoverEvents(CampaignSendRun $run): array
    {
        return array_values((array) data_get($run->execution_options, 'failover_events', []));
    }

    /**
     * Check an error string or status code against configured failover patterns.
     */
    public function matchesErrorPattern(CampaignSendRun $run, string $errorText): bool
    {
        if (trim($errorText) === '') {
            return false;
        }

        $rawPatterns = (string) data_get($run->execution_options, 'failover_error_patterns', '421, rate limit, too many connections, temporarily deferred, user complaints');
        $patterns = array_filter(array_map('trim', explode(',', strtolower($rawPatterns))));

        $normalizedError = strtolower($errorText);
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($normalizedError, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record an attempt error and evaluate circuit breaker threshold.
     *
     * @return array{triggered: bool, domain: string, action: ?string, message: string}
     */
    public function recordAttemptError(
        CampaignSendRun $run,
        string $domain,
        string $errorText,
        ?ManagedServer $server = null,
    ): array {
        if (! $this->isEnabled($run)) {
            return ['triggered' => false, 'domain' => $domain, 'action' => null, 'message' => 'Auto-failover disabled'];
        }

        $domain = strtolower(trim($domain));
        if ($domain === '' || ! $this->matchesErrorPattern($run, $errorText)) {
            return ['triggered' => false, 'domain' => $domain, 'action' => null, 'message' => 'Error does not match failover pattern'];
        }

        $disabledDomains = $this->getDisabledDomains($run);
        if (in_array($domain, $disabledDomains, true)) {
            return ['triggered' => false, 'domain' => $domain, 'action' => null, 'message' => 'Domain already disabled'];
        }

        $threshold = max(1, (int) data_get($run->execution_options, 'failover_threshold', 5));
        $errorCounts = $this->getDomainErrorCounts($run);
        $errorCounts[$domain] = ($errorCounts[$domain] ?? 0) + 1;

        $options = $run->execution_options ?? [];
        $options['domain_error_counts'] = $errorCounts;
        $run->execution_options = $options;
        $run->save();

        if ($errorCounts[$domain] >= $threshold) {
            return $this->triggerFailover($run, $domain, $errorText, $server);
        }

        return [
            'triggered' => false,
            'domain' => $domain,
            'action' => 'incremented',
            'message' => "Error recorded ({$errorCounts[$domain]}/{$threshold} toward threshold)",
        ];
    }

    /**
     * Execute the Circuit Breaker: Pause -> Purge Spool -> Remove Domain -> Resume.
     *
     * @return array{triggered: bool, domain: string, action: string, message: string}
     */
    public function triggerFailover(
        CampaignSendRun $run,
        string $domain,
        string $errorText,
        ?ManagedServer $server = null,
    ): array {
        $domain = strtolower(trim($domain));
        $originalState = $run->state;

        // 1. PAUSE send run
        $run->update(['state' => 'paused']);

        // 2. PURGE in-flight spool messages in PMTA if configured
        $purgedSpool = false;
        $purgeConfigured = (bool) data_get($run->execution_options, 'failover_purge_spool', true);
        if ($purgeConfigured && $server instanceof ManagedServer) {
            try {
                $port = $this->resolvePmtaAdminPort($run);
                $workspace = $run->workspace;
                if (! $workspace instanceof Workspace) {
                    throw new RuntimeException('The campaign has no workspace PMTA proxy settings.');
                }
                $proxy = $this->proxySettings->forWorkspace($workspace);
                if ($proxy === null) {
                    throw new RuntimeException('Configure the PMTA relay proxy before running queue commands.');
                }
                // Purge domain queue on PMTA server
                $this->pmtaQueueAdmin->run($server, $port, 'delete', 'domain', ['*/*'], $proxy['url']);
                $purgedSpool = true;
            } catch (Throwable $e) {
                Log::warning("PMTA queue purge warning on {$server->name}: ".$e->getMessage());
            }
        }

        // 3. REMOVE DOMAIN from active placeholder & routing pool
        $disabledDomains = $this->getDisabledDomains($run);
        if (! in_array($domain, $disabledDomains, true)) {
            $disabledDomains[] = $domain;
        }

        $allConfiguredDomains = $this->extractConfiguredDomains($run);
        $remainingDomains = array_values(array_diff($allConfiguredDomains, $disabledDomains));
        $remainingCount = count($remainingDomains);

        // 4. LOG EVENT
        $events = $this->getFailoverEvents($run);
        $event = [
            'id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'domain' => $domain,
            'server_name' => $server?->name ?? 'Configured Server',
            'server_ip' => $server?->ip_address ?? '',
            'error_sample' => Str::limit($errorText, 200),
            'purged_spool' => $purgedSpool,
            'remaining_domains' => $remainingDomains,
            'remaining_count' => $remainingCount,
            'action' => 'domain_removed',
            'message' => "Deferred threshold reached for {$domain}. PMTA queue purged: ".($purgedSpool ? 'Yes' : 'No').". Removed from [domain] pool. Remaining active domains: {$remainingCount}.",
        ];
        array_unshift($events, $event);

        $options = $run->execution_options ?? [];
        $options['disabled_domains'] = $disabledDomains;
        $options['failover_events'] = array_slice($events, 0, 50); // Keep last 50 events
        $run->execution_options = $options;
        $run->save();

        // 5. RESUME or HALT
        $manualPause = (bool) data_get($run->fresh()->execution_options, 'operator_paused', false);
        if ($remainingCount > 0 && ! $manualPause) {
            $run->update(['state' => 'running']);
            ProcessCampaignSendRun::dispatch($run->getKey());
            $action = 'resumed_with_remaining';
            $msg = "Domain {$domain} removed from rotation. Send resumed with {$remainingCount} healthy domains.";
        } else {
            $run->update(['state' => 'paused']);
            $action = $manualPause ? 'paused_by_operator' : 'paused_all_disabled';
            $msg = $manualPause
                ? 'A routing issue was recorded while the operator pause was active. The campaign remains paused.'
                : 'All configured domains have been deferred. Campaign send paused by Circuit Breaker.';
        }

        return [
            'triggered' => true,
            'domain' => $domain,
            'action' => $action,
            'message' => $msg,
        ];
    }

    /**
     * Restore a previously disabled domain back into the rotation.
     *
     * @return array{restored: bool, message: string}
     */
    public function restoreDomain(CampaignSendRun $run, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $disabledDomains = $this->getDisabledDomains($run);

        if (! in_array($domain, $disabledDomains, true)) {
            return ['restored' => false, 'message' => "Domain {$domain} is not currently disabled."];
        }

        $disabledDomains = array_values(array_diff($disabledDomains, [$domain]));
        $errorCounts = $this->getDomainErrorCounts($run);
        unset($errorCounts[$domain]);

        $events = $this->getFailoverEvents($run);
        $events[] = [
            'id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
            'domain' => $domain,
            'server_name' => 'Operator',
            'server_ip' => '',
            'error_sample' => 'Manual operator restore',
            'purged_spool' => false,
            'remaining_domains' => array_values(array_diff($this->extractConfiguredDomains($run), $disabledDomains)),
            'remaining_count' => count(array_diff($this->extractConfiguredDomains($run), $disabledDomains)),
            'action' => 'domain_restored',
            'message' => "Domain {$domain} manually restored by operator.",
        ];

        $options = $run->execution_options ?? [];
        $options['disabled_domains'] = $disabledDomains;
        $options['domain_error_counts'] = $errorCounts;
        $options['failover_events'] = array_slice($events, 0, 50);
        $run->execution_options = $options;
        $run->save();

        if ($run->state === 'paused' && ! (bool) data_get($run->execution_options, 'operator_paused', false)) {
            $run->update(['state' => 'running']);
            ProcessCampaignSendRun::dispatch($run->getKey());
        }

        return ['restored' => true, 'message' => "Domain {$domain} restored to rotation."];
    }

    /**
     * Inspect PMTA queues on campaign servers for deferral/limitation errors.
     *
     * @return list<array{domain: string, queue: string, error: string, count: int}>
     */
    public function inspectPmtaQueues(CampaignSendRun $run): array
    {
        if (! $this->isEnabled($run)) {
            return [];
        }

        $setup = $run->revision?->payload['send_setup'] ?? [];
        $serverIds = (array) ($setup['server_ids'] ?? []);
        if (empty($serverIds)) {
            return [];
        }

        $servers = ManagedServer::query()
            ->whereIn('id', $serverIds)
            ->where('is_active', true)
            ->get();

        $port = $this->resolvePmtaAdminPort($run);
        $workspace = $run->workspace;
        if (! $workspace instanceof Workspace) {
            return [];
        }
        $proxy = $this->proxySettings->forWorkspace($workspace);
        if ($proxy === null) {
            return [];
        }
        $detected = [];

        foreach ($servers as $server) {
            $endpoint = rtrim(PmtaPanelUrl::forInstallation((string) $server->ip_address, null, $port, false), '/');
            if ($endpoint === '') {
                continue;
            }

            try {
                $response = Http::connectTimeout(2)
                    ->timeout(4)
                    ->withOptions(['allow_redirects' => false, 'proxy' => $proxy['url']])
                    ->get($endpoint.'/command', [
                        'format' => 'xml',
                        'command' => 'show queues',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $xml = @simplexml_load_string($response->body(), '\\SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
                if (! $xml) {
                    continue;
                }

                // Parse XML queues
                $queues = $xml->xpath('//queue') ?: [];
                foreach ($queues as $qNode) {
                    $qDomain = strtolower(trim((string) ($qNode->name ?? $qNode->domain ?? '')));
                    $qError = trim((string) ($qNode->lastError ?? $qNode->error ?? ''));
                    $qCount = (int) ($qNode->rcpt ?? $qNode->count ?? 0);

                    if ($qError !== '' && $this->matchesErrorPattern($run, $qError)) {
                        $detected[] = [
                            'domain' => $qDomain,
                            'queue' => (string) ($qNode->vmta ?? $qDomain),
                            'error' => $qError,
                            'count' => $qCount,
                        ];

                        // Evaluate threshold
                        $this->recordAttemptError($run, $qDomain, $qError, $server);
                    }
                }
            } catch (Throwable $e) {
                // Ignore transient PMTA connection timeouts
            }
        }

        return $detected;
    }

    /**
     * Get list of all domains configured for this campaign.
     *
     * @return array<string>
     */
    public function extractConfiguredDomains(CampaignSendRun $run): array
    {
        $setup = $run->revision?->payload['send_setup'] ?? [];
        $domains = [];

        if (! empty($setup['domain'])) {
            $domains[] = strtolower(trim((string) $setup['domain']));
        }

        // Check if VMTA domains are stored in options or IPs
        if (! empty($setup['vmta_domains']) && is_array($setup['vmta_domains'])) {
            foreach ($setup['vmta_domains'] as $d) {
                if (is_string($d) && trim($d) !== '') {
                    $domains[] = strtolower(trim($d));
                }
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    private function resolvePmtaAdminPort(CampaignSendRun $run): int
    {
        $configured = $run->workspace?->infrastructureSettings?->pmta_admin_port;

        return is_numeric($configured) && (int) $configured >= 1 && (int) $configured <= 65535
            ? (int) $configured
            : (int) config('infrastructure.pmta_admin_port', 1213);
    }
}
