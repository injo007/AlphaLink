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

        foreach ([
            'ip', 'rdns', 'domain', 'to', 'name', 'from', 'subject', 'date', 'server',
            'main_domain', 'idi', 'idd', 'ids', 'ide', 'boundary', 'reply', 'negative',
            'token', 'textarea', 'smtp',
        ] as $tag) {
            $text = str_ireplace('['.$tag.']', (string) ($values[$tag] ?? ''), $text);
        }

        $text = $this->replaceTextChoices($text);
        $text = $this->replaceBoundaryTags($text, $boundaryCache);
        $text = $this->replaceRandomTags($text);
        $text = preg_replace_callback('/\[printable:([^]]*)\]/i', fn (array $match): string => quoted_printable_encode($match[1]), $text) ?? $text;
        $text = preg_replace_callback('/\[base64:([^]]*)\]/i', function (array $match): string {
            $encoded = str_replace('=', '', base64_encode($match[1]));

            return chunk_split($encoded);
        }, $text) ?? $text;

        return $text;
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
