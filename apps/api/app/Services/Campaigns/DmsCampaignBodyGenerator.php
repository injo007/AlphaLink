<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;

class DmsCampaignBodyGenerator
{
    public function generate(string $campaignId, int|string $ispId, string $body): string
    {
        $campaignId = str_replace('-', '', $campaignId);
        $sendId = '0';
        $emailId = '0';
        $protection = hash('adler32', "{$campaignId}_{$sendId}_{$emailId}", false);
        $app = hash('adler32', (string) config('app.name', 'NA'), false);
        $token = base64_encode("{$campaignId}-0-0-t-0-{$ispId}-0-0-0-0-0-0-".uniqid()."_{$app}-{$protection}");

        return $this->replaceRandomTags(str_ireplace('[token]', str_replace('=', '', $token), $body));
    }

    private function replaceRandomTags(string $body): string
    {
        return preg_replace_callback('/(?:\[|\{)(rnd.*?)(?:\]|\})/i', function (array $match): string {
            $parts = explode('_', strtolower($match[1]));
            $length = isset($parts[1]) && ctype_digit($parts[1]) ? min((int) $parts[1], 10000) : 18;

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
        }, $body) ?? $body;
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
