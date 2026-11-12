<?php

namespace App\Services\Infrastructure;

use RuntimeException;

/** Pure-PHP IMAP4rev1 client for AlphaLink Dovecot webmail mailboxes without requiring ext-imap. */
class AlphaLinkImapWebmailClient
{
    /** @var resource|null */
    private $socket = null;

    private int $tagCounter = 0;

    private string $currentHost = '';

    private int $currentPort = 143;

    public function __destruct()
    {
        $this->disconnect();
    }

    public function connect(
        string $host,
        int $port,
        string $username,
        string $password,
        string $security = 'auto',
        int $timeout = 8,
    ): void {
        $this->disconnect();

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Webmail target server has no valid IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid IMAP port.');
        }

        $this->currentHost = $host;
        $this->currentPort = $port;

        $useSsl = $security === 'ssl' || ($security === 'auto' && $port === 993);
        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $transport = ($useSsl ? 'ssl://' : 'tcp://').$formattedHost.':'.$port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($transport, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($socket)) {
            throw new RuntimeException(sprintf(
                'Could not reach IMAP on %s:%d (%s). Ensure IMAP (Dovecot) was installed on the server and its firewall allows AlphaLink.',
                $host,
                $port,
                $errstr !== '' ? $errstr : 'connection refused or timed out',
            ));
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        // Read greeting (* OK ...)
        $greeting = $this->readLine();
        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Unexpected IMAP greeting from server: '.trim($greeting));
        }

        if (! $useSsl) {
            $caps = $this->capabilities();
            $shouldStartTls = $security === 'starttls'
                || ($security === 'auto' && in_array('STARTTLS', $caps, true));

            if ($shouldStartTls) {
                if (! in_array('STARTTLS', $caps, true)) {
                    throw new RuntimeException('The IMAP server does not advertise STARTTLS support on port '.$port.'.');
                }
                $this->command('STARTTLS');
                $cryptoOk = @stream_socket_enable_crypto(
                    $this->socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                );
                if ($cryptoOk !== true) {
                    throw new RuntimeException('Failed to negotiate STARTTLS encryption with Dovecot on '.$host.':'.$port.'.');
                }
            }
        }

        try {
            $this->command('LOGIN '.$this->quoteString($username).' '.$this->quoteString($password));
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (stripos($message, 'AUTHENTICATIONFAILED') !== false || stripos($message, 'NO ') !== false) {
                throw new RuntimeException(sprintf(
                    'IMAP authentication failed on %s:%d for "%s". Verify your Webmail username and password in Settings.',
                    $host,
                    $port,
                    $username,
                ));
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    public function listFolders(): array
    {
        $response = $this->command('LIST "" "*"');
        $folders = [];
        foreach ($response['lines'] as $line) {
            if (preg_match('/^\*\s+LIST\s+\([^)]*\)\s+(?:"[^"]*"|NIL)\s+(.+)$/i', trim($line), $matches) === 1) {
                $name = trim($matches[1]);
                if (str_starts_with($name, '"') && str_ends_with($name, '"')) {
                    $name = stripcslashes(substr($name, 1, -1));
                }
                if ($name !== '') {
                    $folders[] = $name;
                }
            }
        }

        if (! in_array('INBOX', $folders, true)) {
            array_unshift($folders, 'INBOX');
        }

        return array_values(array_unique($folders));
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, to: string, date: string, size: int, seen: bool}>
     */
    public function listMessages(string $folder = 'INBOX', int $limit = 50): array
    {
        try {
            $this->selectFolder($folder);
        } catch (RuntimeException $exception) {
            if (stripos($exception->getMessage(), 'NONEXISTENT') !== false
                || stripos($exception->getMessage(), "doesn't exist") !== false) {
                return [];
            }
            throw $exception;
        }

        $search = $this->command('UID SEARCH ALL');
        $uids = [];
        foreach ($search['lines'] as $line) {
            if (preg_match('/^\*\s+SEARCH(?:\s+(.*))?$/i', trim($line), $matches) === 1) {
                $ids = preg_split('/\s+/', trim((string) ($matches[1] ?? ''))) ?: [];
                foreach ($ids as $id) {
                    if (ctype_digit($id)) {
                        $uids[] = (int) $id;
                    }
                }
            }
        }

        if ($uids === []) {
            return [];
        }

        $uids = array_slice(array_reverse($uids), 0, max(1, min(100, $limit)));
        $uidList = implode(',', $uids);
        $fetch = $this->command("UID FETCH {$uidList} (UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID)])");

        $messages = $this->parseFetchHeaders($fetch['items']);
        usort($messages, static fn (array $a, array $b): int => $b['uid'] <=> $a['uid']);

        return $messages;
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: string, size: int, seen: bool, headers: array<string, string>, text: string, html: string, attachments: list<string>, raw_headers: string}
     */
    public function fetchMessage(string $folder, int $uid): array
    {
        if ($uid < 1) {
            throw new RuntimeException('Invalid message UID.');
        }

        $this->selectFolder($folder);
        $fetch = $this->command("UID FETCH {$uid} (UID FLAGS RFC822.SIZE INTERNALDATE BODY[])");
        $item = $fetch['items'][0] ?? null;
        if (! is_array($item)) {
            throw new RuntimeException('Requested email message could not be found in '.$folder.'.');
        }

        $raw = (string) ($item['literal'] ?? '');
        $parsed = $this->parseMimeMessage($raw);

        return [
            'uid' => $item['uid'] ?? $uid,
            'subject' => $parsed['headers']['subject'] ?? '(No Subject)',
            'from' => $parsed['headers']['from'] ?? 'Unknown',
            'to' => $parsed['headers']['to'] ?? '',
            'date' => $parsed['headers']['date'] ?? ($item['internaldate'] ?? ''),
            'size' => $item['size'] ?? strlen($raw),
            'seen' => in_array('\\Seen', $item['flags'] ?? [], true),
            'headers' => $parsed['headers'],
            'text' => $parsed['text'],
            'html' => $parsed['html'],
            'attachments' => $parsed['attachments'],
            'raw_headers' => $parsed['raw_headers'],
        ];
    }

    public function deleteMessage(string $folder, int $uid): void
    {
        if ($uid < 1) {
            throw new RuntimeException('Invalid message UID.');
        }

        $this->selectFolder($folder, readOnly: false);
        $this->command("UID STORE {$uid} +FLAGS (\\Deleted)");
        $this->command('EXPUNGE');
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "ZZZ LOGOUT\r\n");
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Parses raw RFC 5322/MIME message into headers, text body, HTML body, and attachment names.
     *
     * @return array{headers: array<string, string>, text: string, html: string, attachments: list<string>, raw_headers: string}
     */
    public function parseMimeMessage(string $raw): array
    {
        $normalized = str_replace("\r\n", "\n", $raw);
        $parts = explode("\n\n", $normalized, 2);
        $rawHeaders = $parts[0] ?? '';
        $rawBody = $parts[1] ?? '';

        $headers = $this->parseHeaderBlock($rawHeaders);
        $textParts = [];
        $htmlParts = [];
        $attachments = [];

        $this->extractMimeParts($headers, $rawBody, $textParts, $htmlParts, $attachments);

        return [
            'headers' => $headers,
            'text' => trim(implode("\n\n", $textParts)),
            'html' => trim(implode("\n", $htmlParts)),
            'attachments' => array_values(array_unique($attachments)),
            'raw_headers' => $rawHeaders,
        ];
    }

    private function selectFolder(string $folder, bool $readOnly = true): void
    {
        $verb = $readOnly ? 'EXAMINE' : 'SELECT';
        try {
            $this->command($verb.' '.$this->quoteString($folder));
        } catch (RuntimeException $exception) {
            if ($readOnly) {
                $this->command('SELECT '.$this->quoteString($folder));

                return;
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function capabilities(): array
    {
        $resp = $this->command('CAPABILITY');
        $caps = [];
        foreach ($resp['lines'] as $line) {
            if (preg_match('/^\*\s+CAPABILITY\s+(.+)$/i', trim($line), $matches) === 1) {
                foreach (preg_split('/\s+/', strtoupper(trim($matches[1]))) ?: [] as $cap) {
                    if ($cap !== '') {
                        $caps[] = $cap;
                    }
                }
            }
        }

        return $caps;
    }

    /** @return array{lines: list<string>, items: list<array<string, mixed>>} */
    private function command(string $command): array
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('Not connected to an IMAP server.');
        }

        $tag = 'A'.(++$this->tagCounter);
        fwrite($this->socket, $tag.' '.$command."\r\n");

        $lines = [];
        $items = [];

        while (true) {
            $line = $this->readLine();
            if ($line === '') {
                throw new RuntimeException('IMAP connection closed unexpectedly by '.$this->currentHost.':'.$this->currentPort.'.');
            }

            if (preg_match('/^'.preg_quote($tag, '/').'\s+(OK|NO|BAD)\s*(.*)$/i', $line, $tagMatch) === 1) {
                $status = strtoupper($tagMatch[1]);
                $detail = trim($tagMatch[2]);
                if ($status !== 'OK') {
                    throw new RuntimeException("IMAP {$status}: {$detail}");
                }

                return ['lines' => $lines, 'items' => $items];
            }

            // Check if untagged FETCH line ends with literal {bytes}
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $litMatch) === 1) {
                $bytes = (int) $litMatch[1];
                $literal = $this->readBytes($bytes);
                $trailing = $this->readLine(); // consumes closing ) + CRLF
                $combinedMeta = $line.$trailing;
                $items[] = $this->parseFetchItemMeta($combinedMeta, $literal);
                continue;
            }

            if (preg_match('/^\*\s+\d+\s+FETCH\s+/i', $line) === 1) {
                $items[] = $this->parseFetchItemMeta($line, '');
                continue;
            }

            $lines[] = $line;
        }
    }

    private function readLine(): string
    {
        if (! is_resource($this->socket)) {
            return '';
        }

        $line = @fgets($this->socket, 65536);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out'] ?? false) {
                throw new RuntimeException('Timed out waiting for IMAP response from '.$this->currentHost.':'.$this->currentPort.'.');
            }

            return '';
        }

        return $line;
    }

    private function readBytes(int $length): string
    {
        if ($length <= 0 || ! is_resource($this->socket)) {
            return '';
        }

        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function parseFetchItemMeta(string $meta, string $literal): array
    {
        $uid = null;
        if (preg_match('/\bUID\s+(\d+)/i', $meta, $m) === 1) {
            $uid = (int) $m[1];
        }
        $size = null;
        if (preg_match('/\bRFC822\.SIZE\s+(\d+)/i', $meta, $m) === 1) {
            $size = (int) $m[1];
        }
        $flags = [];
        if (preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $meta, $m) === 1) {
            $flags = array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: []));
        }
        $internalDate = '';
        if (preg_match('/\bINTERNALDATE\s+"([^"]*)"/i', $meta, $m) === 1) {
            $internalDate = $m[1];
        }

        return [
            'uid' => $uid,
            'size' => $size,
            'flags' => $flags,
            'internaldate' => $internalDate,
            'literal' => $literal,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{uid: int, subject: string, from: string, to: string, date: string, size: int, seen: bool}>
     */
    private function parseFetchHeaders(array $items): array
    {
        $messages = [];
        foreach ($items as $item) {
            $uid = $item['uid'] ?? null;
            if (! is_int($uid) || $uid < 1) {
                continue;
            }
            $headers = $this->parseHeaderBlock(str_replace("\r\n", "\n", (string) ($item['literal'] ?? '')));
            $messages[] = [
                'uid' => $uid,
                'subject' => ($headers['subject'] ?? '') !== '' ? $headers['subject'] : '(No Subject)',
                'from' => ($headers['from'] ?? '') !== '' ? $headers['from'] : 'Unknown',
                'to' => $headers['to'] ?? '',
                'date' => ($headers['date'] ?? '') !== '' ? $headers['date'] : (string) ($item['internaldate'] ?? ''),
                'size' => (int) ($item['size'] ?? 0),
                'seen' => in_array('\\Seen', $item['flags'] ?? [], true),
            ];
        }

        return $messages;
    }

    /** @return array<string, string> */
    private function parseHeaderBlock(string $rawHeaders): array
    {
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $rawHeaders) ?? $rawHeaders;
        $headers = [];
        foreach (explode("\n", $unfolded) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            if ($key === '') {
                continue;
            }
            $decoded = $this->decodeMimeHeader(trim($value));
            $headers[$key] = isset($headers[$key]) ? $headers[$key].', '.$decoded : $decoded;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $textParts
     * @param  list<string>  $htmlParts
     * @param  list<string>  $attachments
     */
    private function extractMimeParts(
        array $headers,
        string $body,
        array &$textParts,
        array &$htmlParts,
        array &$attachments,
    ): void {
        $contentType = strtolower($headers['content-type'] ?? 'text/plain; charset=utf-8');
        $disposition = strtolower($headers['content-disposition'] ?? '');

        if (str_contains($contentType, 'multipart/') && preg_match('/boundary\s*=\s*"?([^"\s;]+)"?/i', $headers['content-type'] ?? '', $m) === 1) {
            $boundary = $m[1];
            $chunks = explode('--'.$boundary, $body);
            foreach ($chunks as $chunk) {
                $chunk = ltrim($chunk, "\r\n");
                if ($chunk === '' || str_starts_with($chunk, '--')) {
                    continue;
                }
                $subParts = explode("\n\n", str_replace("\r\n", "\n", $chunk), 2);
                $subHeaders = $this->parseHeaderBlock($subParts[0] ?? '');
                $subBody = rtrim($subParts[1] ?? '', "\r\n");
                $this->extractMimeParts($subHeaders, $subBody, $textParts, $htmlParts, $attachments);
            }

            return;
        }

        if (str_contains($disposition, 'attachment') && preg_match('/filename\s*=\s*"?([^"\r\n;]+)"?/i', $disposition, $m) === 1) {
            $attachments[] = trim($m[1]);

            return;
        }

        $decoded = $this->decodeBodyTransfer($body, $headers['content-transfer-encoding'] ?? '');
        $charset = 'UTF-8';
        if (preg_match('/charset\s*=\s*"?([A-Za-z0-9._-]+)"?/i', $headers['content-type'] ?? '', $cm) === 1) {
            $charset = strtoupper($cm[1]);
        }
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if (is_string($converted)) {
                $decoded = $converted;
            }
        }

        if (str_contains($contentType, 'text/html')) {
            $htmlParts[] = $decoded;
        } elseif (str_contains($contentType, 'text/plain') || ! str_contains($contentType, '/')) {
            $textParts[] = $decoded;
        }
    }

    private function decodeBodyTransfer(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true) ?: $body,
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeMimeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @mb_decode_mimeheader($value);

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    private function quoteString(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value).'"';
    }
}
