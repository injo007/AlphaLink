<?php

namespace App\Services\Campaigns;

use Symfony\Component\Process\Process;
use Throwable;

class DmsGlobalTestReverseDnsResolver
{
    public function resolve(string $ip, string $fallback): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $fallback;
        }

        $binary = config('global-tests.dig_path', 'dig');
        if (! is_string($binary) || trim($binary) === '') {
            return $fallback;
        }

        try {
            $process = new Process([$binary, '@1.1.1.1', '+short', '+time=3', '+tries=1', '-x', $ip]);
            $process->setTimeout(5);
            $process->run();
            $result = strtolower(trim($process->getOutput(), ". \t\n\r\0\x0B"));
            if ($result === '' || str_contains($result, 'timed out')) {
                return $fallback;
            }

            if (str_contains($result, 'in-addr.arpa')) {
                $lines = array_map(static fn (string $line): string => trim($line, '.'), explode("\n", $result));
                $result = $lines[1] ?? $lines[0];
            }

            return $result !== '' ? $result : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
