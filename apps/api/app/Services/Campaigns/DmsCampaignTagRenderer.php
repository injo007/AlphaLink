<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;

/**
 * Resolve the campaign text tags implemented by DMS's Send Helpers\Text.
 *
 * DMS's native campaign sender is not included in the PHP source snapshot, so
 * this renderer follows the readable helper's replacement order and leaves
 * sender-only behavior outside that contract unclaimed.
 */
class DmsCampaignTagRenderer
{
    /**
     * @param  array<string, string|int|null>  $values
     * @param  list<array<string, mixed>>  $placeholderRows
     * @param  array<string, string>  $boundaryCache
     */
    public function render(
        string $text,
        array $values,
        array $placeholderRows = [],
        int $rotationIndex = 0,
        ?array &$boundaryCache = null,
    ): string {
        if ($text === '') {
            return '';
        }

        $boundaryCache ??= [];
        $text = $this->replacePlaceholders($text, $placeholderRows, $rotationIndex);
        $values['domain_sld'] ??= $this->domainWithoutTld((string) ($values['domain'] ?? ''));

        // SMTP route usernames are often complete mailbox addresses. DMS
        // templates also commonly append @[domain] to [smtp], so avoid
        // producing invalid values such as user@example.test@example.test.
        $smtp = trim((string) ($values['smtp'] ?? ''));
        if (filter_var($smtp, FILTER_VALIDATE_EMAIL) !== false) {
            $text = preg_replace_callback(
                '/\[smtp\]\s*@\s*\[domain\]/i',
                static fn (): string => $smtp,
                $text,
            ) ?? $text;
        }

        // Older DMS headers commonly use a fixed From local part with the
        // SMTP tag in the domain position, e.g. `info@[smtp]`. SMTP route
        // usernames can be complete email addresses, so use their domain in
        // this specific position instead of producing `info@user@domain`.
        // A local-part username falls back to the selected route's domain.
        $smtpDomain = '';
        if (filter_var($smtp, FILTER_VALIDATE_EMAIL) !== false) {
            $smtpDomain = (string) substr($smtp, strrpos($smtp, '@') + 1);
        } else {
            $smtpDomain = trim((string) ($values['domain'] ?? ''));
        }
        if ($smtpDomain !== '') {
            $text = preg_replace('/@\s*\[smtp\]/i', '@'.$smtpDomain, $text) ?? $text;
        }

        foreach ([
            'ip', 'rdns', 'domain', 'domain_sld', 'to', 'name', 'from', 'subject', 'date', 'server',
            'main_domain', 'idi', 'idd', 'ids', 'ide', 'boundary', 'reply', 'negative',
            'token', 'textarea', 'smtp', 'smtp_name', 'tag',
        ] as $tag) {
            $text = str_ireplace('['.$tag.']', (string) ($values[$tag] ?? ''), $text);
        }

        $text = $this->replaceTextChoices($text);
        $text = $this->replaceLanguageTags($text);
        $text = $this->replaceBoundaryTags($text, $boundaryCache);
        $text = $this->replaceRandomTags($text);
        $text = preg_replace_callback('/\[printable:([^]]*)\]/i', fn (array $match): string => quoted_printable_encode($match[1]), $text) ?? $text;
        $text = preg_replace_callback('/\[base64:([^]]*)\]/i', function (array $match): string {
            $encoded = str_replace('=', '', base64_encode($match[1]));

            return chunk_split($encoded);
        }, $text) ?? $text;

        return $text;
    }

    private function domainWithoutTld(string $domain): string
    {
        $domain = trim($domain, ". \t\n\r\0\x0B");
        $lastDot = strrpos($domain, '.');

        return $lastDot === false ? $domain : substr($domain, 0, $lastDot);
    }

    public function campaignTestToken(
        int|string $campaignId,
        int|string $ispId,
    ): string {
        $campaignId = str_replace('-', '', (string) $campaignId);
        $sendId = '0';
        $emailId = '0';
        $protection = hash('adler32', "{$campaignId}_{$sendId}_{$emailId}", false);
        $app = hash('adler32', (string) config('app.name', 'NA'), false);
        $type = 't';
        $listId = '0';
        $unique = uniqid();
        $token = base64_encode("{$campaignId}-{$sendId}-{$emailId}-{$type}-{$listId}-{$ispId}-0-0-0-0-0-0-{$unique}_{$app}-{$protection}");

        return str_replace('=', '', $token);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replacePlaceholders(string $text, array $rows, int $rotationIndex): string
    {
        foreach ($rows as $index => $row) {
            $values = $row['data'] ?? null;
            if (! is_array($values)) {
                $values = preg_split('/\r\n|\r|\n/', (string) ($row['data_text'] ?? '')) ?: [];
            }
            if ($values === []) {
                continue;
            }

            $rotateEvery = max(1, (int) ($row['rotate_every'] ?? 1));
            $valueIndex = intdiv(max(0, $rotationIndex), $rotateEvery) % count($values);
            $value = (string) ($values[$valueIndex] ?? '');
            $placeholder = '[placeholder'.($index + 1).']';
            $text = preg_replace_callback(
                '/'.preg_quote($placeholder, '/').'/i',
                fn (): string => $value,
                $text,
            ) ?? $text;
        }

        return $text;
    }

    private function replaceTextChoices(string $text): string
    {
        return preg_replace_callback('/\[text:([^]]*)\]/i', function (array $match): string {
            $choices = array_values(array_filter(explode('|', $match[1]), 'strlen'));

            return $choices === [] ? '' : $choices[array_rand($choices)];
        }, $text) ?? $text;
    }

    /**
     * @param  array<string, string>  $cache
     */
    private function replaceBoundaryTags(string $text, array &$cache): string
    {
        return preg_replace_callback('/(?:\[|\{)(bnd.*?)(?:\]|\})/i', function (array $match) use (&$cache): string {
            $parts = explode('_', strtolower($match[1]));
            $length = isset($parts[1]) && is_numeric($parts[1]) ? min((int) $parts[1], 10000) : 18;
            $key = $parts[0].'_'.$length;
            $generatedLength = $length;
            if ($parts[0] === 'bnd') {
                $generatedLength = 18;
            }
            if (isset($cache[$key])) {
                return $cache[$key];
            }

            $alphabet = match ($parts[0]) {
                'bnd' => null,
                'bnda' => null,
                'bndn' => '0123456789',
                'bndl' => 'abcdefghijklmnopqrstuvwxyz',
                'bndu' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
                'bnds' => '!@#$%^&*()_+{}[]:;"\'<>?/.,|\\~`',
                'bndul', 'bndlu' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
                'bndln', 'bndnl' => 'abcdefghijklmnopqrstuvwxyz0123456789',
                'bndun', 'bndnu' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
                default => false,
            };

            if ($alphabet === false) {
                return $match[0];
            }

            $cache[$key] = $alphabet === null
                ? Str::random($generatedLength)
                : $this->randomFrom($alphabet, $generatedLength);

            return $cache[$key];
        }, $text) ?? $text;
    }

    private function replaceRandomTags(string $text): string
    {
        return preg_replace_callback('/(?:\[|\{)(rnd.*?)(?:\]|\})/i', function (array $match): string {
            $parts = explode('_', strtolower($match[1]));
            $length = isset($parts[1]) && is_numeric($parts[1]) ? min((int) $parts[1], 10000) : 18;

            return match ($parts[0]) {
                'rnd' => Str::random(18),
                'rnda' => Str::random($length),
                'rndn' => $this->randomFrom('0123456789', $length),
                'rndl' => $this->randomFrom('abcdefghijklmnopqrstuvwxyz', $length),
                'rndu' => $this->randomFrom('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $length),
                'rnds' => $this->randomFrom('!@#$%^&*()_+{}[]:;"\'<>?/.,|\\~`', $length),
                'rndul', 'rndlu' => $this->randomFrom('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $length),
                'rndln', 'rndnl' => $this->randomFrom('abcdefghijklmnopqrstuvwxyz0123456789', $length),
                'rndun', 'rndnu' => $this->randomFrom('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $length),
                default => $match[0],
            };
        }, $text) ?? $text;
    }

    private function replaceLanguageTags(string $text): string
    {
        return preg_replace_callback('/\[(ingreek|ingemojicons|ingemoticons|ingdevanagari|ingthai|ingarabic|inglatin|ingchinese|inghiragana|ingcyrrilic|ingcyrillic|ingarmenian|inggeorgian)_(\d+)\]/i', function (array $match): string {
            $length = min((int) $match[2], 10000);
            $alphabet = match (strtolower($match[1])) {
                'ingreek' => 'ΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡΣΤΥΦΧΨΩαβγδεζηθικλμνξοπρστυφχψω',
                'ingdevanagari' => 'अआइईउऊऋएऐओऔकखगघचछजझटठडढतथदधनपफबभमयरलवशषसह',
                'ingthai' => 'กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮ',
                'ingarabic' => 'ءابتثجحخدذرزسشصضطظعغفقكلمنهوي',
                'inglatin' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
                'ingchinese' => 'CJK',
                'inghiragana' => 'HIRAGANA',
                'ingcyrrilic', 'ingcyrillic' => 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯабвгдеёжзийклмнопрстуфхцчшщъыьэюя',
                'ingarmenian' => 'ԱԲԳԴԵԶԷԸԹԺԻԼԽԾԿՀՁՂՃՄՅՆՇՈՉՊՋՌՍՎՏՐՑՒՓՔՕՖաբգդեզէըթժիլխծկհձղճմյնշոչպջռցւփքօֆ',
                'inggeorgian' => 'აბგდევზთიკლმნოპჟრსტუფქღყშჩცძწჭხჯჰ',
                'ingemojicons', 'ingemoticons' => ['😀', '😁', '😂', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😍', '😘', '😎', '😃', '😜', '😢', '😭', '😡', '😇', '🤔'],
                default => null,
            };

            if ($alphabet === null) {
                return $match[0];
            }

            if ($alphabet === 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') {
                return $this->randomFrom($alphabet, $length);
            }

            if (strtolower($match[1]) === 'ingchinese') {
                return $this->randomUnicodeRange(0x4E00, 0x9FFF, $length);
            }

            if (strtolower($match[1]) === 'inghiragana') {
                return $this->randomUnicodeRange(0x3041, 0x3096, $length);
            }

            $characters = is_array($alphabet)
                ? $alphabet
                : (preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $result = '';
            for ($index = 0; $index < $length; $index++) {
                $result .= $characters[array_rand($characters)];
            }

            return $result;
        }, $text) ?? $text;
    }

    private function randomUnicodeRange(int $minimum, int $maximum, int $length): string
    {
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            $result .= $this->unicodeCharacter(random_int($minimum, $maximum));
        }

        return $result;
    }

    private function unicodeCharacter(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }
        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6)).chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12)).chr(0x80 | (($codepoint >> 6) & 0x3F)).chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18)).chr(0x80 | (($codepoint >> 12) & 0x3F)).chr(0x80 | (($codepoint >> 6) & 0x3F)).chr(0x80 | ($codepoint & 0x3F));
    }

    private function randomFrom(string $alphabet, int $length): string
    {
        $result = '';
        $max = strlen($alphabet) - 1;

        for ($index = 0; $index < $length; $index++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }
}
