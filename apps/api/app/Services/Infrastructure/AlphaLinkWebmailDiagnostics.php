<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use RuntimeException;
use Throwable;

/** Inspects live DNS readiness, PMTA delivery/bounce logs, and Dovecot user sync for Webmail & PMTA SMTP. */
class AlphaLinkWebmailDiagnostics
{
    public function __construct(
        private readonly AlphaLinkInstallerServerCredentialResolver $credentials,
        private readonly AlphaLinkInstallerSshSessionFactory $sessions,
    ) {}

    /**
     * Inspect live PTR (rDNS), SPF, DMARC, and DKIM TXT records for an IP/domain pair.
     *
     * @return array{ptr: array{status: string, value: string, detail: string}, spf: array{status: string, value: string, detail: string}, dkim: array{status: string, selector: string, value: string, detail: string}, dmarc: array{status: string, value: string, detail: string}}
     */
    public function dnsReadiness(string $ip, string $domain, ?string $dkimSelector = null): array
    {
        $domain = trim(strtolower($domain));
        $ip = trim($ip);

        // 1. PTR (Reverse DNS)
        $ptrValue = '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $arpa = implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
            $records = @dns_get_record($arpa, DNS_PTR);
            if (is_array($records) && isset($records[0]['target'])) {
                $ptrValue = rtrim(strtolower((string) $records[0]['target']), '.');
            }
        }
        $ptrStatus = 'missing';
        $ptrDetail = $ptrValue === ''
            ? "No PTR (rDNS) record published for {$ip}. Major providers (Gmail) reject direct IP mail with 550 5.7.1 when rDNS is missing."
            : "PTR resolves to {$ptrValue}.";
        if ($ptrValue !== '') {
            $ptrStatus = ($domain !== '' && ($ptrValue === $domain || str_ends_with($ptrValue, '.'.$domain)))
                ? 'pass'
                : 'warn';
            if ($ptrStatus === 'warn' && $domain !== '') {
                $ptrDetail = "PTR resolves to {$ptrValue}, which does not match {$domain}.";
            }
        }

        // 2. SPF
        $spfValue = '';
        if ($domain !== '') {
            $txtRecords = @dns_get_record($domain, DNS_TXT);
            if (is_array($txtRecords)) {
                foreach ($txtRecords as $rec) {
                    $txt = (string) ($rec['txt'] ?? '');
                    if (stripos($txt, 'v=spf1') !== false) {
                        $spfValue = $txt;
                        break;
                    }
                }
            }
        }
        $spfStatus = 'missing';
        $spfDetail = $domain === '' ? 'No domain mapped to IP.' : "No SPF TXT record found on {$domain}.";
        if ($spfValue !== '') {
            $hasIp = $ip !== '' && str_contains($spfValue, $ip);
            $spfStatus = $hasIp || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false ? 'pass' : 'warn';
            $spfDetail = $spfValue;
        }

        // 3. DMARC
        $dmarcValue = '';
        if ($domain !== '') {
            $dmarcRecords = @dns_get_record('_dmarc.'.$domain, DNS_TXT);
            if (is_array($dmarcRecords)) {
                foreach ($dmarcRecords as $rec) {
                    $txt = (string) ($rec['txt'] ?? '');
                    if (stripos($txt, 'v=DMARC1') !== false) {
                        $dmarcValue = $txt;
                        break;
                    }
                }
            }
        }
        $dmarcStatus = $dmarcValue !== '' ? 'pass' : 'missing';
        $dmarcDetail = $dmarcValue !== '' ? $dmarcValue : "No DMARC record on _dmarc.{$domain}.";

        // 4. DKIM
        $selector = trim((string) $dkimSelector);
        $dkimValue = '';
        if ($domain !== '' && $selector !== '') {
            $dkimRecords = @dns_get_record("{$selector}._domainkey.{$domain}", DNS_TXT);
            if (is_array($dkimRecords)) {
                foreach ($dkimRecords as $rec) {
                    $txt = (string) ($rec['txt'] ?? '');
                    if (stripos($txt, 'v=DKIM1') !== false || stripos($txt, 'p=') !== false) {
                        $dkimValue = $txt;
                        break;
                    }
                }
            }
        }
        $dkimStatus = $selector === '' ? 'unknown' : ($dkimValue !== '' ? 'pass' : 'missing');
        $dkimDetail = $selector === ''
            ? 'No active DKIM selector detected in PMTA /etc/pmta/conf/dkim.'
            : ($dkimValue !== '' ? "Selector {$selector}._domainkey.{$domain} published." : "Missing DNS TXT for {$selector}._domainkey.{$domain}.");

        return [
            'ptr' => ['status' => $ptrStatus, 'value' => $ptrValue, 'detail' => $ptrDetail],
            'spf' => ['status' => $spfStatus, 'value' => $spfValue, 'detail' => $spfDetail],
            'dkim' => ['status' => $dkimStatus, 'selector' => $selector, 'value' => $dkimValue, 'detail' => $dkimDetail],
            'dmarc' => ['status' => $dmarcStatus, 'value' => $dmarcValue, 'detail' => $dmarcDetail],
        ];
    }

    /**
     * Collect server Dovecot users, domain DKIM selector, and recent PMTA delivered/bounced records for a server IP.
     *
     * @return array{imap_users: list<string>, dkim_selector: string, events: list<array{type: string, time: string, vmta: string, status: string, diag: string, rcpt: string, dest_ip: string}>}
     */
    public function fetchServerTelemetry(ManagedServer $server, string $ip, string $domain): array
    {
        $resolved = $this->credentials->resolve($server);
        $session = $this->sessions->create($server, $resolved['secret'], $resolved['type']);

        $today = gmdate('Y-m-d');
        $cmd = implode('; ', [
            'echo "===USERS==="',
            'cut -d: -f1 /etc/dovecot/imap.passwd 2>/dev/null || true',
            'echo "===DKIM==="',
            'cat /etc/pmta/conf/dkim 2>/dev/null || true',
            'echo "===BOUNCE==="',
            'find /etc/pmta/bounced -name "b-'.escapeshellarg($today).'*.csv" -size +110c 2>/dev/null | sort -r | head -n 5 | xargs -r grep -hv "^type," 2>/dev/null | tail -n 15 || true',
            'echo "===DELIV==="',
            'find /etc/pmta/delivered -name "d-'.escapeshellarg($today).'*.csv" -size +110c 2>/dev/null | sort -r | head -n 5 | xargs -r grep -hv "^type," 2>/dev/null | tail -n 15 || true',
        ]);

        $raw = (string) $session->exec($cmd);
        $sections = $this->splitSections($raw);

        $users = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/\r?\n/', $sections['USERS'] ?? '') ?: [],
        ), static fn (string $u): bool => $u !== '' && ! str_starts_with($u, '#'))));

        $dkimSelector = '';
        $targetDomain = strtolower(trim($domain));
        foreach (preg_split('/\r?\n/', $sections['DKIM'] ?? '') ?: [] as $line) {
            if (preg_match('/^domain-key\s+([^,\s]+)\s*,\s*([^,\s]+)\s*,/i', trim($line), $m) === 1) {
                if ($targetDomain === '' || strtolower($m[2]) === $targetDomain) {
                    $dkimSelector = trim($m[1]);
                    break;
                }
            }
        }

        $events = [];
        foreach (preg_split('/\r?\n/', $sections['BOUNCE'] ?? '') ?: [] as $line) {
            $cols = str_getcsv(trim($line), ',', '"', '\\');
            if (count($cols) < 9 || ($cols[0] ?? '') !== 'b') {
                continue;
            }
            $events[] = [
                'type' => 'bounced',
                'time' => (string) ($cols[2] ?? ''),
                'vmta' => (string) ($cols[3] ?? ''),
                'status' => trim(((string) ($cols[7] ?? '')).' '.((string) ($cols[1] ?? ''))),
                'diag' => (string) ($cols[8] ?? ''),
                'rcpt' => (string) ($cols[11] ?? ''),
                'dest_ip' => (string) ($cols[10] ?? ''),
            ];
        }
        foreach (preg_split('/\r?\n/', $sections['DELIV'] ?? '') ?: [] as $line) {
            $cols = str_getcsv(trim($line), ',', '"', '\\');
            if (count($cols) < 8 || ($cols[0] ?? '') !== 'd') {
                continue;
            }
            $events[] = [
                'type' => 'delivered',
                'time' => (string) ($cols[1] ?? ''),
                'vmta' => (string) ($cols[2] ?? ''),
                'status' => (string) ($cols[6] ?? '2.0.0 (success)'),
                'diag' => (string) ($cols[7] ?? ''),
                'rcpt' => '',
                'dest_ip' => (string) ($cols[9] ?? ''),
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return [
            'imap_users' => $users,
            'dkim_selector' => $dkimSelector,
            'events' => array_slice($events, 0, 20),
        ];
    }

    /**
     * Read provisioned Dovecot IMAP account names from /etc/dovecot/imap.passwd safely without returning passwords.
     *
     * @return list<string>
     */
    public function listDovecotUsers(ManagedServer $server): array
    {
        try {
            $resolved = $this->credentials->resolve($server);
            $session = $this->sessions->create($server, $resolved['secret'], $resolved['type']);
            $raw = (string) $session->exec('cut -d: -f1 /etc/dovecot/imap.passwd 2>/dev/null || true');

            return array_values(array_unique(array_filter(array_map(
                'trim',
                preg_split('/\r?\n/', $raw) ?: [],
            ), static fn (string $u): bool => $u !== '' && ! str_starts_with($u, '#'))));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Provision or update a Dovecot user in /etc/dovecot/imap.passwd using ~/mail/%d routing without re-running full install.
     */
    public function syncDovecotUser(ManagedServer $server, string $username, string $password): string
    {
        $bare = trim(str_contains($username, '@') ? (string) strstr($username, '@', true) : $username);
        if (preg_match('/\A[A-Za-z0-9._-]{2,64}\z/', $bare) !== 1) {
            throw new RuntimeException('IMAP username must contain only letters, numbers, dots, underscores, or hyphens.');
        }
        if ($password === '' || preg_match('/[\r\n\x00]/', $password) === 1) {
            throw new RuntimeException('Provide a valid non-empty IMAP password without line breaks.');
        }

        $resolved = $this->credentials->resolve($server);
        $session = $this->sessions->create($server, $resolved['secret'], $resolved['type']);

        $checkDovecot = trim((string) $session->exec('rpm -q dovecot >/dev/null 2>&1 && echo OK || echo MISSING'));
        if ($checkDovecot !== 'OK') {
            throw new RuntimeException('Dovecot IMAP is not installed on this server yet. Run Reinstall Server with IMAP selected first.');
        }

        $session->exec('id -u '.escapeshellarg($bare).' >/dev/null 2>&1 || useradd -s /sbin/nologin '.escapeshellarg($bare).';');
        $session->exec('usermod -aG pmta '.escapeshellarg($bare).' 2>/dev/null || true;');
        $session->exec('mkdir -p '.escapeshellarg('/home/'.$bare.'/mail').' && chown -R '.escapeshellarg($bare.':'.$bare).' '.escapeshellarg('/home/'.$bare).' && chmod 0750 '.escapeshellarg('/home/'.$bare).';');
        $session->exec('mkdir -p /etc/pmta/inbound && chmod 2775 /etc/pmta/inbound 2>/dev/null || true; setfacl -m g:pmta:rwX,d:g:pmta:rwX /etc/pmta/inbound 2>/dev/null || true;');
        $session->exec("sed -i 's|^mail_location = .*|mail_location = mbox:~/mail/%d:INBOX=/etc/pmta/inbound/%d|' /etc/dovecot/dovecot.conf;");
        $session->exec("grep -qxF 'mail_access_groups = pmta' /etc/dovecot/dovecot.conf || printf '\\nmail_access_groups = pmta\\n' >> /etc/dovecot/dovecot.conf;");

        $uidRaw = trim((string) $session->exec('id -u '.escapeshellarg($bare).';'));
        $uid = ctype_digit($uidRaw) ? $uidRaw : '1000';

        // Update existing passwd file preserving other existing accounts cleanly.
        $existingPasswd = (string) $session->exec('cat /etc/dovecot/imap.passwd 2>/dev/null || true');
        $lines = array_values(array_filter(preg_split('/\r?\n/', $existingPasswd) ?: [], static function (string $line) use ($bare): bool {
            $trimmed = trim($line);

            return $trimmed !== '' && ! str_starts_with($trimmed, $bare.':');
        }));
        $lines[] = "{$bare}:{plain}{$password}:{$uid}:{$uid}::/home/{$bare}:/sbin/nologin";
        $session->upload(implode("\n", $lines)."\n", '/etc/dovecot/imap.passwd');
        $session->exec('(chown root:dovecot /etc/dovecot/imap.passwd 2>/dev/null && chmod 0640 /etc/dovecot/imap.passwd) || chmod 0644 /etc/dovecot/imap.passwd; systemctl restart dovecot');

        return $bare;
    }

    /** @return array<string, string> */
    private function splitSections(string $raw): array
    {
        $sections = [];
        $current = '';
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^===([A-Z_]+)===$/', trim($line), $m) === 1) {
                $current = $m[1];
                $sections[$current] ??= '';
                continue;
            }
            if ($current !== '') {
                $sections[$current] .= $line."\n";
            }
        }

        return $sections;
    }
}
