<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServerIp;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Runs DMS's reverse-DNS, DNSBL and Sender Score queries behind an opt-in gate. */
class DmsServerIpHistoryProbe
{
    /**
     * @param  list<ManagedServerIp>  $ips
     * @return array<int, array{ptr:string,ip_listed_spamhaus:bool,ip_listed_spamcop:bool,ip_listed_barracuda:bool,sender_score:string,domain_listed_hostkarma:bool,domain_listed_spamhaus_dbl:bool,domain_listed_surbl:bool}>
     */
    public function checkMany(array $ips): array
    {
        $defaults = [
            'ptr' => 'NA',
            'ip_listed_spamhaus' => false,
            'ip_listed_spamcop' => false,
            'ip_listed_barracuda' => false,
            'sender_score' => 'NA',
            'domain_listed_hostkarma' => false,
            'domain_listed_spamhaus_dbl' => false,
            'domain_listed_surbl' => false,
        ];
        $tasks = [];
        $results = [];

        foreach ($ips as $ip) {
            $ipId = (int) $ip->getKey();
            $results[$ipId] = $defaults;
            $address = (string) $ip->ip;

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            $reverse = implode('.', array_reverse(explode('.', $address)));
            $domain = trim((string) $ip->dns, '. ');
            $tasks["{$ipId}:ptr"] = [
                'ip_id' => $ipId,
                'field' => 'ptr',
                'command' => ['dig', '@1.1.1.1', '+short', '+time=3', '+tries=1', '-x', $address],
            ];
            $tasks["{$ipId}:ip_listed_spamhaus"] = $this->task(
                $ipId,
                'ip_listed_spamhaus',
                ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.zen.spamhaus.org"],
            );
            $tasks["{$ipId}:ip_listed_barracuda"] = $this->task(
                $ipId,
                'ip_listed_barracuda',
                ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.b.barracudacentral.org"],
            );
            $tasks["{$ipId}:ip_listed_spamcop"] = $this->task(
                $ipId,
                'ip_listed_spamcop',
                ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.bl.spamcop.net"],
            );
            $tasks["{$ipId}:domain_listed_hostkarma"] = $this->task(
                $ipId,
                'domain_listed_hostkarma',
                ['dig', '@208.67.222.222', '+short', '-t', 'a', "{$domain}.black.junkemailfilter.com"],
            );
            $tasks["{$ipId}:domain_listed_spamhaus_dbl"] = $this->task(
                $ipId,
                'domain_listed_spamhaus_dbl',
                ['dig', '@208.67.222.222', '+short', '-t', 'a', "{$domain}.dbl.spamhaus.org"],
            );
            $tasks["{$ipId}:domain_listed_surbl"] = $this->task(
                $ipId,
                'domain_listed_surbl',
                ['dig', '@208.67.222.222', '+short', '-t', 'a', "{$domain}.multi.surbl.org"],
            );
            $tasks["{$ipId}:sender_score"] = $this->task(
                $ipId,
                'sender_score',
                ['dig', '+short', '+time=3', '+tries=1', '-t', 'a', "{$reverse}.score.senderscore.com"],
            );
        }

        if ($tasks === []) {
            return $results;
        }

        try {
            $processes = Process::pool(function (Pool $pool) use ($tasks): void {
                foreach ($tasks as $key => $task) {
                    $pool->as($key)
                        ->command($task['command'])
                        ->timeout(10)
                        ->path(base_path());
                }
            })->start()->wait()->collect();
        } catch (Throwable) {
            // DMS records the helper defaults when a lookup command cannot run.
            return $results;
        }

        foreach ($tasks as $key => $task) {
            $output = trim((string) ($processes->get($key)?->output() ?? ''));
            $results[$task['ip_id']][$task['field']] = $this->parse($task['field'], $output);
        }

        return $results;
    }

    /** @return array{ip_id:int,field:string,command:list<string>} */
    private function task(int $ipId, string $field, array $command): array
    {
        return ['ip_id' => $ipId, 'field' => $field, 'command' => $command];
    }

    private function parse(string $field, string $output): bool|string
    {
        if ($field === 'ptr') {
            $ptr = trim(strtolower($output), '. ');
            if ($ptr === '' || str_contains($ptr, 'timed out')) {
                return 'NA';
            }

            if (str_contains($ptr, 'in-addr.arpa')) {
                $lines = array_map(static fn (string $line): string => trim($line, '.'), explode("\n", $ptr));
                $ptr = $lines[1] ?? $lines[0] ?? 'NA';
            }

            return $ptr;
        }

        if ($field === 'sender_score') {
            if ($output === '' || str_contains(strtolower($output), 'timed out')) {
                return 'NA';
            }

            return explode('.', $output)[3] ?? 'NA';
        }

        return $output !== '' && ! str_contains(strtolower($output), 'timed out');
    }
}
