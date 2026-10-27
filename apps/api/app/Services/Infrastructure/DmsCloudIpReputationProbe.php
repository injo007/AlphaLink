<?php

namespace App\Services\Infrastructure;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** Runs the same DNSBL and Sender Score lookups used by the DMS cloud tables. */
class DmsCloudIpReputationProbe
{
    /** @return array{spamhaus:bool,barracuda:bool,spamcop:bool,score:string} */
    public function check(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Reputation checks are available for IPv4 addresses only.');
        }

        $reverse = implode('.', array_reverse(explode('.', $ip)));
        $commands = [
            'spamhaus' => ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.zen.spamhaus.org"],
            'barracuda' => ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.b.barracudacentral.org"],
            'spamcop' => ['dig', '@208.67.222.222', '+short', '+time=5', '+tries=1', '-t', 'a', "{$reverse}.bl.spamcop.net"],
            'score' => ['dig', '+short', '+time=3', '+tries=1', '-t', 'a', "{$reverse}.score.senderscore.com"],
        ];

        try {
            $results = Process::pool(function (Pool $pool) use ($commands): void {
                foreach ($commands as $key => $command) {
                    $pool->as($key)->command($command)->timeout(10)->path(base_path());
                }
            })->start()->wait()->collect();
        } catch (Throwable) {
            throw new RuntimeException('The IP reputation lookup could not run.');
        }

        $listed = static function (string $key) use ($results): bool {
            $output = trim((string) ($results->get($key)?->output() ?? ''));

            return $output !== '' && ! str_contains(strtolower($output), 'timed out');
        };
        $scoreOutput = trim((string) ($results->get('score')?->output() ?? ''));
        $score = $scoreOutput === '' || str_contains(strtolower($scoreOutput), 'timed out')
            ? 'NA'
            : (explode('.', $scoreOutput)[3] ?? 'NA');

        return [
            'spamhaus' => $listed('spamhaus'),
            'barracuda' => $listed('barracuda'),
            'spamcop' => $listed('spamcop'),
            'score' => $score,
        ];
    }
}
