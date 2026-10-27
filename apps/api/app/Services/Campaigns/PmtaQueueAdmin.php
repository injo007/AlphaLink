<?php

namespace App\Services\Campaigns;

use App\Models\ManagedServer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PmtaQueueAdmin
{
    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public function run(ManagedServer $server, int $port, string $action, string $queue, array $tags): array
    {
        if (filter_var($server->ip_address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The selected server does not have a valid IP address.');
        }

        $commands = $this->commands($action, $queue, $tags);
        $results = [];
        $host = filter_var($server->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$server->ip_address.']'
            : $server->ip_address;

        foreach ($commands as $command) {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->get('http://'.$host.':'.$port.'/command', [
                    'format' => 'xml',
                    'command' => $command,
                ]);

            $results[] = $this->responseResult($response);
        }

        return $results;
    }

    /**
     * @param  list<ManagedServer>  $servers
     * @param  array<string, int>  $portsByServer
     * @param  list<string>  $tags
     * @return array<string, array{results: list<string>, error: ?string}>
     */
    public function runConcurrently(array $servers, array $portsByServer, string $action, string $queue, array $tags): array
    {
        $commands = $this->commands($action, $queue, $tags);
        $results = [];
        $errors = [];

        foreach ($servers as $server) {
            $serverId = (string) $server->getKey();
            $results[$serverId] = [];
            if (filter_var($server->ip_address, FILTER_VALIDATE_IP) === false) {
                $errors[$serverId] = 'The selected server does not have a valid IP address.';
            }
        }

        foreach ($commands as $command) {
            $eligibleServers = array_filter(
                $servers,
                fn (ManagedServer $server): bool => ! isset($errors[(string) $server->getKey()]),
            );

            foreach (array_chunk($eligibleServers, 5, true) as $serverBatch) {
                $responses = Http::pool(function (Pool $pool) use ($serverBatch, $command, $portsByServer): void {
                    foreach ($serverBatch as $server) {
                        $serverId = (string) $server->getKey();
                        $host = filter_var($server->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                            ? '['.$server->ip_address.']'
                            : $server->ip_address;

                        $pool->as($serverId)
                            ->connectTimeout(3)
                            ->timeout(5)
                            ->get('http://'.$host.':'.$portsByServer[$serverId].'/command', [
                                'format' => 'xml',
                                'command' => $command,
                            ]);
                    }
                }, concurrency: 5);

                foreach ($serverBatch as $server) {
                    $serverId = (string) $server->getKey();
                    try {
                        $response = $responses[$serverId] ?? null;
                        if ($response instanceof Throwable) {
                            throw $response;
                        }
                        if (! $response instanceof Response) {
                            throw new RuntimeException('PMTA did not return a command response.');
                        }

                        $results[$serverId][] = $this->responseResult($response);
                    } catch (Throwable $exception) {
                        $errors[$serverId] = $exception->getMessage();
                    }
                }
            }
        }

        $outcomes = [];
        foreach ($results as $serverId => $serverResults) {
            $outcomes[$serverId] = ['results' => $serverResults, 'error' => $errors[$serverId] ?? null];
        }

        return $outcomes;
    }

    private function responseResult(Response $response): string
    {
        if (! $response->successful()) {
            throw new RuntimeException('PMTA returned HTTP '.$response->status().'.');
        }

        $xml = @simplexml_load_string($response->body(), '\\SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        if (! $xml) {
            throw new RuntimeException('PMTA returned an unreadable response.');
        }

        $values = [];
        foreach ($xml->children() as $key => $value) {
            $values[$key] = trim((string) $value);
        }

        if (isset($values['fail'])) {
            throw new RuntimeException($values['fail']);
        }
        if (isset($values['body'])) {
            throw new RuntimeException('PMTA returned access denied. Verify the AlphaLink app host is allowed for admin access in PMTA; the */* tag only selects the queue.');
        }
        if (! isset($values['succ'])) {
            throw new RuntimeException('PMTA did not return a command result.');
        }

        return $values['succ'];
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function commands(string $action, string $queue, array $tags): array
    {
        if (! in_array('*/*', $tags, true)) {
            throw new RuntimeException('Select the */* queue tag before executing a PMTA command.');
        }

        if ($action === 'reset') {
            return ['reset counters'];
        }

        return array_map(function (string $tag) use ($action, $queue): string {
            return match ($action) {
                'pause' => $queue === 'domain'
                    ? 'pmta pause queue '.$tag
                    : throw new RuntimeException('Pause is available for the Domain queue only.'),
                'resume' => $queue === 'domain'
                    ? 'resume queue '.$tag
                    : throw new RuntimeException('Resume is available for the Domain queue only.'),
                'delete' => $queue === 'domain'
                    ? 'delete --queue='.$tag
                    : 'delete --jobId='.$tag,
                'schedule' => 'schedule '.$tag,
                default => throw new RuntimeException('Unsupported PMTA action.'),
            };
        }, $tags);
    }
}
