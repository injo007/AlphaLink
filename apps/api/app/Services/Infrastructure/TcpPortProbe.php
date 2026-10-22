<?php

namespace App\Services\Infrastructure;

class TcpPortProbe
{
    /**
     * @param  array<int, int>  $ports
     * @return array<int, bool>
     */
    public function checkMany(string $ip, array $ports, int $timeoutSeconds = 10): array
    {
        $results = [];
        foreach ($ports as $port) {
            $results[(int) $port] = false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $results;
        }

        $pending = [];
        foreach (array_keys($results) as $port) {
            if ($port < 1 || $port > 65535) {
                continue;
            }

            $address = str_contains($ip, ':')
                ? sprintf('tcp://[%s]:%d', $ip, $port)
                : sprintf('tcp://%s:%d', $ip, $port);
            $errorCode = 0;
            $errorMessage = '';
            $stream = @stream_socket_client(
                $address,
                $errorCode,
                $errorMessage,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if (is_resource($stream)) {
                $pending[$port] = $stream;
            }
        }

        $deadline = microtime(true) + max(1, $timeoutSeconds);
        while ($pending !== []) {
            $timeLeft = $deadline - microtime(true);
            if ($timeLeft <= 0) {
                break;
            }

            $read = [];
            $write = array_values($pending);
            $except = array_values($pending);
            $seconds = (int) $timeLeft;
            $microseconds = (int) (($timeLeft - $seconds) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false || $ready === 0) {
                break;
            }

            $readyStreams = [];
            foreach ([...$write, ...$except] as $stream) {
                $readyStreams[get_resource_id($stream)] = true;
            }

            foreach ($pending as $port => $stream) {
                if (! isset($readyStreams[get_resource_id($stream)])) {
                    continue;
                }

                $results[$port] = @stream_socket_get_name($stream, true) !== false;
                fclose($stream);
                unset($pending[$port]);
            }
        }

        foreach ($pending as $stream) {
            fclose($stream);
        }

        return $results;
    }
}
