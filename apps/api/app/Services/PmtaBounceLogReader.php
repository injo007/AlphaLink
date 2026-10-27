<?php

namespace App\Services;

use App\Models\ManagedServer;
use App\Services\Infrastructure\DmsInstallerSshSession;

class PmtaBounceLogReader
{
    public function command(array $emails): string
    {
        $today = date('Y-m-d');
        $filter = '';
        if ($emails !== []) {
            $patterns = array_map(static fn (string $email): string => preg_quote(strtolower($email), '/'), $emails);
            $filter = escapeshellarg(implode('|', $patterns));
        }
        $patternArgument = $filter === '' ? "''" : $filter;
        $awk = 'BEGIN { FPAT = "([^,]*)|(\"[^\"]*\")" } $1 != "type" && $2 != "no-log" && (email_pattern == "" || tolower($12) ~ email_pattern) { print $2, $3, $4, $8, $9, $12; }';

        return "ls -t /etc/pmta/bounced/archive/{$today}/b-* /etc/pmta/bounced/process/{$today}/b-* /etc/pmta/bounced/treated/b-* /etc/pmta/bounced/b-* 2>/dev/null | xargs -r awk -F',' -v email_pattern={$patternArgument} ".escapeshellarg($awk)." OFS=','";
    }

    /** @return list<array<string, string>> */
    public function read(DmsInstallerSshSession $session, ManagedServer $server, array $emails): array
    {
        $output = trim((string) $session->exec($this->command($emails)));
        if ($output === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $fields = str_getcsv($line);
            if (count($fields) < 6) {
                continue;
            }
            $recipient = $fields[5] ?? '';
            $at = strpos($recipient, '@');
            $rows[] = [
                'server' => $server->name.substr((string) $server->getKey(), 0, 8),
                'category' => $fields[0],
                'time' => $fields[1],
                'vmta' => $fields[2],
                'dns_status' => $fields[3],
                'dns_diag' => preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '(MASKED)', $fields[4]) ?? $fields[4],
                'recipient' => $at !== false ? str_repeat('*', $at).substr($recipient, $at) : $recipient,
            ];
        }

        return $rows;
    }
}
