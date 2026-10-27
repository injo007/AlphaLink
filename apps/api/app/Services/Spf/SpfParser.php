<?php

namespace App\Services\Spf;

use Illuminate\Support\Facades\Http;

class SpfParser
{
    /**
     * @return array<int, string>
     */
    public static function extractIncludes(string $spf): array
    {
        preg_match_all('/(?:include:|redirect=)([^\s]+)/i', $spf, $matches);

        return $matches[1] ?? [];
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    public static function extractIpMechanisms(string $spf): array
    {
        $result = [];
        if (preg_match_all('/\b(ip4|ip6):([^\s]+)/i', $spf, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $version = strtolower($m[1]) === 'ip6' ? 6 : 4;
                $result[] = [$version, $m[2]];
            }
        }

        return $result;
    }

    public static function normalizeIpMechanism(int $version, string $value): ?string
    {
        $val = trim(preg_replace('/\s+/', '', $value));
        $parts = explode('/', $val, 2);
        $ip = $parts[0] ?? '';
        $prefix = $parts[1] ?? null;

        if ($version === 4) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return null;
            }

            if ($prefix !== null && $prefix !== '' && ! ctype_digit($prefix)) {
                return null;
            }

            $p = $prefix !== null && $prefix !== '' ? (int) $prefix : 32;
            if ($p < 0 || $p > 32) {
                return null;
            }

            return self::networkCidr($ip, $p);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        if ($prefix !== null && $prefix !== '' && ! ctype_digit($prefix)) {
            return null;
        }

        $p = $prefix !== null && $prefix !== '' ? (int) $prefix : 128;
        if ($p < 0 || $p > 128) {
            return null;
        }

        return self::networkCidr($ip, $p);
    }

    /**
     * Zero host bits so PostgreSQL cidr accepts the value (e.g. 207.46.22.98/29 → 207.46.22.96/29).
     */
    public static function networkCidr(string $ip, int $prefix): ?string
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $max = strlen($packed) === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $max) {
            return null;
        }

        $bits = unpack('C*', $packed);
        $fullBytes = intdiv($prefix, 8);
        $remain = $prefix % 8;

        foreach ($bits as $i => $byte) {
            $byteIndex = $i - 1;
            if ($byteIndex < $fullBytes) {
                continue;
            }
            if ($byteIndex === $fullBytes && $remain > 0) {
                $bits[$i] = $byte & (0xFF << (8 - $remain) & 0xFF);
            } else {
                $bits[$i] = 0;
            }
        }

        $network = inet_ntop(pack('C*', ...$bits));
        if ($network === false) {
            return null;
        }

        return $network.'/'.$prefix;
    }

    public static function normalizeCidrString(string $cidr): ?string
    {
        $parts = explode('/', trim($cidr), 2);
        $ip = $parts[0] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 32;

            return self::networkCidr($ip, $prefix);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 128;

            return self::networkCidr($ip, $prefix);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function extractCidrs(string $spf): array
    {
        $cidrs = [];
        foreach (self::extractIpMechanisms($spf) as [$version, $value]) {
            $normalized = self::normalizeIpMechanism($version, $value);
            if ($normalized !== null) {
                $cidrs[$normalized] = true;
            }
        }

        return array_keys($cidrs);
    }

    /**
     * Hostname DNS mechanisms: a, mx, exists, ptr. Macros are kept until they can be expanded.
     *
     * @return array<int, array{type: string, domain: string, prefix4: ?int, prefix6: ?int, has_macros: bool}>
     */
    public static function extractDnsTargets(string $currentDomain, string $spf): array
    {
        $currentDomain = self::normalizeDomain($currentDomain);
        $targets = [];

        if (! preg_match_all('/(?:^|\s)[+?~-]?(a|mx|ptr|exists)(?::([^\s]+))?(?:\/(\d+)(?:\/(\d+))?)?(?=\s|$)/i', $spf, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $type = strtolower($match[1]);
            $spec = $match[2] ?? '';
            $prefix4 = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : null;
            $prefix6 = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : null;
            $domain = $currentDomain;

            if ($spec !== '') {
                $domainPart = $spec;
                if (preg_match('/^(.*?)(?:\/(\d+)(?:\/(\d+))?)?$/', $spec, $cidrParts)) {
                    $domainPart = $cidrParts[1];
                    if (isset($cidrParts[2]) && $cidrParts[2] !== '' && ctype_digit($cidrParts[2])) {
                        $prefix4 = (int) $cidrParts[2];
                    }
                    if (isset($cidrParts[3]) && $cidrParts[3] !== '' && ctype_digit($cidrParts[3])) {
                        $prefix6 = (int) $cidrParts[3];
                    }
                }

                if ($domainPart !== '') {
                    $expanded = self::expandMacros($domainPart, ['domain' => $currentDomain]);
                    if ($expanded === null) {
                        continue;
                    }
                    $domain = str_contains($expanded, '%{') ? $expanded : self::normalizeDomain($expanded);
                }
            }

            if ($domain === '') {
                continue;
            }

            $hasMacros = str_contains($domain, '%{');
            if (! $hasMacros && ! self::isHostname($domain)) {
                continue;
            }

            if ($prefix4 !== null && ($prefix4 < 0 || $prefix4 > 32)) {
                $prefix4 = null;
            }
            if ($prefix6 !== null && ($prefix6 < 0 || $prefix6 > 128)) {
                $prefix6 = null;
            }

            if (in_array($type, ['exists', 'ptr'], true)) {
                $prefix4 = null;
                $prefix6 = null;
            }

            $targets[] = [
                'type' => $type,
                'domain' => $domain,
                'prefix4' => $prefix4,
                'prefix6' => $prefix6,
                'has_macros' => $hasMacros,
            ];
        }

        return $targets;
    }

    /**
     * a / mx targets that are static after expanding %{d}.
     *
     * @return array<int, array{type: string, domain: string, prefix4: ?int, prefix6: ?int}>
     */
    public static function extractAAndMxTargets(string $currentDomain, string $spf): array
    {
        $targets = [];
        foreach (self::extractDnsTargets($currentDomain, $spf) as $target) {
            if (! in_array($target['type'], ['a', 'mx'], true) || $target['has_macros']) {
                continue;
            }
            unset($target['has_macros']);
            $targets[] = $target;
        }

        return $targets;
    }

    /**
     * exists / ptr / macro a,mx — evaluated at IP-search time, not flattened to CIDRs.
     *
     * @return array<int, array{type: string, domain: string, prefix4: ?int, prefix6: ?int}>
     */
    public static function extractStoredDnsChecks(string $currentDomain, string $spf): array
    {
        $checks = [];
        foreach (self::extractDnsTargets($currentDomain, $spf) as $target) {
            if ($target['type'] === 'exists' || $target['type'] === 'ptr' || $target['has_macros']) {
                unset($target['has_macros']);
                $checks[] = $target;
            }
        }

        return $checks;
    }

    /**
     * Expand RFC 7208 macros. Unknown letters (sender/helo/ptr) stay unresolved → null.
     *
     * @param  array{domain?: string, ip?: string}  $ctx
     */
    public static function expandMacros(string $spec, array $ctx = []): ?string
    {
        $spec = strtr($spec, [
            '%%' => "\0",
            '%_' => ' ',
            '%-' => '%20',
        ]);

        $unresolved = false;
        $expanded = preg_replace_callback(
            '/%\{([a-zA-Z])([0-9]*r?)([^}]*)\}/',
            function (array $match) use ($ctx, &$unresolved): string {
                $letter = strtolower($match[1]);
                $value = self::macroValue($letter, $ctx);
                if ($value === null) {
                    if (in_array($letter, ['i', 'c', 'v'], true) && empty($ctx['ip'])) {
                        return $match[0];
                    }
                    $unresolved = true;

                    return $match[0];
                }

                $transformers = strtolower($match[2]);
                $reverse = str_contains($transformers, 'r');
                $digits = (int) $transformers;
                $delimiter = $match[3] !== '' ? $match[3] : '.';

                return self::transformMacro($value, $reverse, $digits, $delimiter);
            },
            $spec
        ) ?? $spec;

        $expanded = str_replace("\0", '%', $expanded);

        if ($unresolved) {
            return null;
        }

        return $expanded;
    }

    /**
     * @param  array{domain?: string, ip?: string}  $ctx
     */
    protected static function macroValue(string $letter, array $ctx): ?string
    {
        $ip = $ctx['ip'] ?? null;
        $domain = $ctx['domain'] ?? null;

        return match ($letter) {
            'd' => $domain !== null && $domain !== '' ? $domain : null,
            'i', 'c' => $ip !== null && $ip !== '' ? self::macroIp($ip) : null,
            'v' => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? 'ip6'
                : ($ip !== null ? 'in-addr' : null),
            default => null,
        };
    }

    protected static function macroIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        return implode('.', str_split(bin2hex($packed)));
    }

    protected static function transformMacro(string $value, bool $reverse, int $digits, string $delimiter): string
    {
        $labels = str_contains($value, '.') ? explode('.', $value) : [$value];
        if ($reverse) {
            $labels = array_reverse($labels);
        }
        if ($digits > 0) {
            $labels = array_slice($labels, -$digits);
        }

        return implode($delimiter === '' ? '.' : $delimiter, $labels);
    }

    public static function ipToCidr(string $ip, ?int $prefix4 = null, ?int $prefix6 = null): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $prefix = $prefix4 ?? 32;

            return $prefix >= 0 && $prefix <= 32 ? self::networkCidr($ip, $prefix) : null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $prefix = $prefix6 ?? 128;

            return $prefix >= 0 && $prefix <= 128 ? self::networkCidr($ip, $prefix) : null;
        }

        return null;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $network = $parts[0] ?? '';
        if ($network === '' || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $netBin = inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $prefix = (int) $parts[1];
        $max = strlen($ipBin) === 4 ? 32 : 128;
        if ($prefix < 0 || $prefix > $max) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $bits)) - 1)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /**
     * @param  array{type: string, domain: string, prefix4: ?int, prefix6: ?int}  $check
     */
    public static function formatDnsCheck(array $check): string
    {
        $spec = $check['type'];
        if (($check['domain'] ?? '') !== '') {
            $spec .= ':'.$check['domain'];
        }
        if ($check['prefix4'] !== null) {
            $spec .= '/'.$check['prefix4'];
            if ($check['prefix6'] !== null) {
                $spec .= '/'.$check['prefix6'];
            }
        } elseif ($check['prefix6'] !== null) {
            $spec .= '//'.$check['prefix6'];
        }

        return $spec;
    }

    public static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public static function isHostname(string $domain): bool
    {
        return $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN) !== false;
    }

    public static function isSpfRecord(?string $record): bool
    {
        return $record !== null && preg_match('/^v=spf1\b/i', $record) === 1;
    }

    public static function recordHash(?string $record): ?string
    {
        return ($record !== null && $record !== '') ? hash('sha256', $record) : null;
    }

    /**
     * include:/redirect= targets that are static after expanding %{d}.
     *
     * @return array<int, string>
     */
    public static function childIncludes(string $spf, string $currentDomain): array
    {
        $currentDomain = self::normalizeDomain($currentDomain);
        $children = [];
        foreach (self::extractIncludes($spf) as $spec) {
            $child = self::expandInclude($spec, $currentDomain);
            if ($child !== null && $child !== $currentDomain) {
                $children[$child] = true;
            }
        }

        return array_keys($children);
    }

    public static function expandInclude(string $spec, string $currentDomain): ?string
    {
        $expanded = self::expandMacros($spec, ['domain' => $currentDomain]);
        if ($expanded === null || str_contains($expanded, '%')) {
            return null;
        }

        $domain = self::normalizeDomain($expanded);

        return self::isHostname($domain) ? $domain : null;
    }

    public static function expandForIp(string $spec, string $ip): ?string
    {
        $expanded = self::expandMacros($spec, ['ip' => $ip]);
        if ($expanded === null || str_contains($expanded, '%{')) {
            return null;
        }

        $domain = self::normalizeDomain($expanded);

        return $domain === '' ? null : $domain;
    }

    public static function ptrNameMatches(string $name, string $target): bool
    {
        $name = self::normalizeDomain($name);
        $target = self::normalizeDomain($target);

        return $name === $target || str_ends_with($name, '.'.$target);
    }

    public static function mxTarget(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/^\d+\s+/', '', $value) ?? $value;
        $host = self::normalizeDomain(rtrim($value, '.'));

        return self::isHostname($host) ? $host : null;
    }

    /**
     * @param  array{type?: string, domain?: string, prefix4?: ?int, prefix6?: ?int}  $check
     */
    public static function dnsCheckKey(array $check): string
    {
        return strtolower((string) ($check['type'] ?? '')).'|'.($check['domain'] ?? '').'|'.($check['prefix4'] ?? '').'|'.($check['prefix6'] ?? '');
    }

    /**
     * RFC 7208 ptr: a validated name matches the target if it is identical or a subdomain.
     *
     * @return array<int, string>
     */
    public static function domainSuffixes(string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return [];
        }

        $labels = explode('.', $domain);
        $suffixes = [];
        for ($i = 0; $i < count($labels); $i++) {
            $suffixes[] = implode('.', array_slice($labels, $i));
        }

        return $suffixes;
    }

    /**
     * Concatenate a TXT RDATA presentation into one string (RFC 7208 §3.3).
     * Quoted chunks come from dig-style answers; commas are LibDNS joining 255-byte strings.
     */
    public static function assembleTxt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $chunks = self::quotedTxtChunks($value);
        if ($chunks !== null) {
            return implode('', $chunks);
        }

        return str_replace(',', '', $value);
    }

    /**
     * Canonical one-line SPF TXT: joined character-strings, UTF-8, collapsed whitespace.
     */
    public static function normalizeRecord(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $joined = self::sanitizeUtf8(self::assembleTxt($value)) ?? '';
        $joined = trim(preg_replace('/\s+/u', ' ', $joined) ?? $joined);

        return $joined === '' ? null : $joined;
    }

    /**
     * First v=spf1 record from a dig-style TXT answer (one RR per line).
     */
    public static function firstSpfRecord(?string $txtAnswers): ?string
    {
        if ($txtAnswers === null || trim($txtAnswers) === '') {
            return null;
        }

        foreach (preg_split('/\R/', $txtAnswers) as $line) {
            $record = self::normalizeRecord($line);
            if (self::isSpfRecord($record)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * One mechanism per line for display. Does not change stored/canonical form.
     */
    public static function formatRecord(?string $value): string
    {
        $record = self::normalizeRecord($value);
        if ($record === null) {
            return '';
        }

        $tokens = preg_split('/\s+/', $record, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) <= 1) {
            return $record;
        }

        $version = array_shift($tokens);

        return $version."\n".implode("\n", array_map(static fn (string $token) => '  '.$token, $tokens));
    }

    /**
     * Make a DNS TXT string safe for PostgreSQL UTF-8 text columns.
     * Invalid bytes are dropped, C0 controls/NULs removed, NBSP becomes a space.
     */
    public static function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = function_exists('mb_scrub')
            ? mb_scrub($value, 'UTF-8')
            : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);

        $value = str_replace("\xC2\xA0", ' ', $value);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return $clean ?? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    /**
     * @return array<int, string>|null
     */
    protected static function quotedTxtChunks(string $value): ?array
    {
        if (! preg_match('/^(?:\s*"(?:\\\\.|[^"\\\\])*")+\s*$/', $value)) {
            return null;
        }

        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $value, $matches);

        return array_map(
            static fn (string $chunk) => str_replace(['\\\\', '\\"'], ['\\', '"'], $chunk),
            $matches[1] ?? []
        );
    }

    public static function domainVolume(string $domain): mixed
    {
        $cacheKey = 'domain_volume:'.str_replace('.', '_', $domain);

        if (cache()->has($cacheKey)) {
            return cache()->get($cacheKey);
        }

        $result = Http::get('https://talosintelligence.com/cloud_intel/domain_info', [
            'domain_name' => $domain,
        ]);

        if ($result->successful()) {
            $data = $result->json();
            cache()->put($cacheKey, $data, now()->addDay());

            return $data;
        }

        return false;
    }
}
