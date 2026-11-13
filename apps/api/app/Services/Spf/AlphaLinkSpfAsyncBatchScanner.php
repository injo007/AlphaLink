<?php

namespace App\Services\Spf;

use Closure;
use React\Dns\Config\Config;
use React\Dns\Model\Message;
use React\Dns\Resolver\Factory;
use React\Dns\Resolver\RecordNotFoundException;
use React\Dns\Resolver\ResolverInterface;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Async\async;
use function React\Async\await;

/**
 * Resolve independent root domains concurrently while preserving the scanner's
 * existing SPF parsing and result shape.
 *
 * Each scan worker owns one resolver and a bounded number of in-flight roots;
 * this keeps DNS waits from serializing otherwise independent input domains.
 */
class AlphaLinkSpfAsyncBatchScanner
{
    private ?ResolverInterface $resolver = null;

    private bool $resolverInitialized = false;

    public function __construct(private readonly AlphaLinkSpfScanner $fallbackScanner) {}

    public function isAsyncResolverAvailable(): bool
    {
        $this->initializeResolver();

        return $this->resolver !== null;
    }

    /**
     * @param  list<string>  $domains
     * @param  (callable(string, string, string, ?int, bool): void)|null  $activity
     * @return array<string, array{domain: string, status: string, record: ?string, cidrs: list<string>, hosts: list<array{domain: string, record: ?string, status: string, cidrs: list<string>}>, dns_errors: int}>
     */
    public function scan(array $domains, int $concurrency, ?callable $activity = null): array
    {
        if (! $this->isAsyncResolverAvailable()) {
            $results = [];
            foreach ($domains as $domain) {
                $results[$domain] = $this->fallbackScanner->scanDomain($domain, $activity === null ? null : Closure::fromCallable($activity));
            }

            return $results;
        }

        $results = [];
        $concurrency = max(1, min(256, $concurrency));
        foreach (array_chunk($domains, $concurrency) as $domainWave) {
            $promises = [];
            foreach ($domainWave as $domain) {
                $promises[$domain] = async(fn (): array => $this->scanDomain($domain, $activity))();
            }
            foreach (await(\React\Promise\all($promises)) as $domain => $result) {
                $results[$domain] = $result;
            }
        }

        return $results;
    }

    private function initializeResolver(): void
    {
        if ($this->resolverInitialized) {
            return;
        }
        $this->resolverInitialized = true;

        try {
            $config = Config::loadSystemConfigBlocking();
            if ($config->nameservers === []) {
                return;
            }
            $this->resolver = (new Factory)->createCached($config);
        } catch (Throwable) {
            // Preserve scan functionality on hosts with an unreadable resolver config.
            $this->resolver = null;
        }
    }

    /** @return array{domain: string, status: string, record: ?string, cidrs: list<string>, hosts: list<array{domain: string, record: ?string, status: string, cidrs: list<string>}>, dns_errors: int} */
    private function scanDomain(string $domain, ?callable $activity): array
    {
        return await(async(function () use ($domain, $activity): array {
            $domain = SpfParser::normalizeDomain(rtrim(trim($domain), '.'));
            if (! SpfParser::isHostname($domain)) {
                return ['domain' => $domain, 'status' => 'error', 'record' => null, 'cidrs' => [], 'hosts' => [], 'dns_errors' => 0];
            }

            $visited = [];
            $hosts = [];
            $dnsErrors = 0;
            await($this->scanNode($domain, $visited, $hosts, $dnsErrors, max(1, (int) config('spf.max_includes', 50)), $activity));

            $root = $hosts[$domain] ?? ['domain' => $domain, 'record' => null, 'status' => 'error', 'cidrs' => []];
            $cidrs = [];
            foreach ($hosts as $host) {
                foreach ($host['cidrs'] as $cidr) {
                    $cidrs[$cidr] = true;
                }
            }

            return [
                'domain' => $domain,
                'status' => $root['status'],
                'record' => $root['record'],
                'cidrs' => array_keys($cidrs),
                'hosts' => array_values($hosts),
                'dns_errors' => $dnsErrors,
            ];
        })());
    }

    /**
     * @param  array<string, true>  $visited
     * @param  array<string, array{domain: string, record: ?string, status: string, cidrs: list<string>}>  $hosts
     */
    private function scanNode(string $domain, array &$visited, array &$hosts, int &$dnsErrors, int $maximumHosts, ?callable $activity): PromiseInterface
    {
        return async(function () use ($domain, &$visited, &$hosts, &$dnsErrors, $maximumHosts, $activity): void {
            $domain = SpfParser::normalizeDomain($domain);
            if (isset($visited[$domain]) || count($visited) >= $maximumHosts) {
                return;
            }
            $visited[$domain] = true;

            [$answers, $failed] = await($this->records($domain, Message::TYPE_TXT, 'spf_txt', $activity));
            $dnsErrors += (int) $failed;
            $record = null;
            foreach ($answers as $chunks) {
                $candidate = is_array($chunks) ? implode('', array_map('strval', $chunks)) : (string) $chunks;
                $normalized = SpfParser::normalizeRecord($candidate);
                if (SpfParser::isSpfRecord($normalized)) {
                    $record = $normalized;
                    break;
                }
            }

            $hosts[$domain] = [
                'domain' => $domain,
                'record' => $record,
                'status' => $record !== null ? 'ok' : ($failed ? 'error' : 'no_spf'),
                'cidrs' => $record === null ? [] : SpfParser::extractCidrs($record),
            ];
            if ($record === null) {
                return;
            }

            if (config('spf.resolve_a_mx', true)) {
                $targets = array_slice(
                    SpfParser::extractAAndMxTargets($domain, $record),
                    0,
                    max(1, (int) config('spf.max_mechanism_lookups', 10)),
                );
                $domainCidrs = $hosts[$domain]['cidrs'];
                foreach ($targets as $target) {
                    $addresses = await($this->targetAddresses($target['type'], $target['domain'], $dnsErrors, $activity));
                    foreach ($addresses as $ip) {
                        $cidr = SpfParser::ipToCidr($ip, $target['prefix4'], $target['prefix6']);
                        if ($cidr !== null) {
                            $domainCidrs[] = $cidr;
                        }
                    }
                }
                $hosts[$domain]['cidrs'] = $domainCidrs;
            }
            $hosts[$domain]['cidrs'] = array_values(array_unique($hosts[$domain]['cidrs']));

            foreach (SpfParser::childIncludes($record, $domain) as $include) {
                if (count($visited) >= $maximumHosts) {
                    break;
                }
                $this->notify($activity, 'include', $include, 'include_started');
                await($this->scanNode($include, $visited, $hosts, $dnsErrors, $maximumHosts, $activity));
            }
        })();
    }

    /** @return PromiseInterface<list<string>> */
    private function targetAddresses(string $type, string $domain, int &$dnsErrors, ?callable $activity): PromiseInterface
    {
        return async(function () use ($type, $domain, &$dnsErrors, $activity): array {
            if ($type === 'a') {
                return await($this->addressRecords($domain, $dnsErrors, $activity));
            }

            [$mxRecords, $mxFailed] = await($this->records($domain, Message::TYPE_MX, 'mx', $activity));
            $dnsErrors += (int) $mxFailed;
            $addresses = [];
            foreach (array_slice($mxRecords, 0, max(1, (int) config('spf.max_mechanism_lookups', 10))) as $mx) {
                $target = is_array($mx) ? rtrim((string) ($mx['target'] ?? ''), '.') : '';
                if ($target !== '' && $target !== '.') {
                    foreach (await($this->addressRecords($target, $dnsErrors, $activity)) as $address) {
                        $addresses[$address] = true;
                    }
                }
            }

            return array_keys($addresses);
        })();
    }

    /** @return PromiseInterface<list<string>> */
    private function addressRecords(string $domain, int &$dnsErrors, ?callable $activity): PromiseInterface
    {
        return async(function () use ($domain, &$dnsErrors, $activity): array {
            $promises = [
                $this->records($domain, Message::TYPE_A, 'a_mx', $activity),
                $this->records($domain, Message::TYPE_AAAA, 'a_mx', $activity),
            ];
            $addresses = [];
            foreach (await(\React\Promise\all($promises)) as $records) {
                $dnsErrors += (int) $records[1];
                foreach ($records[0] as $address) {
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP)) {
                        $addresses[$address] = true;
                    }
                }
            }

            return array_keys($addresses);
        })();
    }

    /** @return PromiseInterface<array{0: array, 1: bool}> */
    private function records(string $domain, int $type, string $stage, ?callable $activity): PromiseInterface
    {
        return async(function () use ($domain, $type, $stage, $activity): array {
            $startedAt = microtime(true);
            $this->notify($activity, $stage, $domain, 'lookup_started');
            try {
                $records = await($this->resolver->resolveAll($domain, $type));
                $failed = false;
            } catch (RecordNotFoundException $exception) {
                $records = [];
                $failed = ! in_array($exception->getCode(), [Message::RCODE_OK, Message::RCODE_NAME_ERROR], true);
            } catch (Throwable) {
                $records = [];
                $failed = true;
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->notify($activity, $stage, $domain, 'lookup_finished', $durationMs, $failed);

            return [$records, $failed];
        })();
    }

    private function notify(?callable $activity, string $stage, string $domain, string $event, ?int $durationMs = null, bool $failed = false): void
    {
        if ($activity === null) {
            return;
        }
        try {
            $activity($stage, $domain, $event, $durationMs, $failed);
        } catch (Throwable) {
            // Telemetry is best-effort and cannot stop a DNS scan.
        }
    }
}
