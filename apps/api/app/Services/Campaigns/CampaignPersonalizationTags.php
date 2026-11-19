<?php

namespace App\Services\Campaigns;

class CampaignPersonalizationTags
{
    /** @param array<string, mixed> $profile */
    public function personalizeSubjectHeader(string $header, array $profile, string $recipient): string
    {
        return preg_replace_callback('/^Subject:[^\r\n]*/im', function (array $match) use ($profile, $recipient): string {
            return $this->replaceTokens($match[0], $this->names($profile, $recipient), false);
        }, $header) ?? $header;
    }

    /** @param array<string, mixed> $profile */
    public function personalizeSubjectLine(string $subject, array $profile, string $recipient): string
    {
        return $this->replaceTokens($subject, $this->names($profile, $recipient), false);
    }

    /** @param array<string, mixed> $profile */
    public function personalizeBody(string $body, array $profile, string $recipient, bool $html): string
    {
        return $this->replaceTokens($body, $this->names($profile, $recipient), $html);
    }

    /** @param array<string, mixed> $profile @return array{fname: string, lname: string} */
    private function names(array $profile, string $recipient): array
    {
        $atPosition = strpos($recipient, '@');
        $alias = trim($atPosition === false ? $recipient : substr($recipient, 0, $atPosition));
        $firstName = trim((string) ($profile['first_name'] ?? ''));
        $lastName = trim((string) ($profile['last_name'] ?? ''));

        return [
            'fname' => $firstName !== '' ? $firstName : $alias,
            'lname' => $lastName !== '' ? $lastName : $alias,
        ];
    }

    /** @param array{fname: string, lname: string} $names */
    private function replaceTokens(string $value, array $names, bool $html): string
    {
        return preg_replace_callback('/__(fname|lname)__/i', function (array $match) use ($names, $html): string {
            $name = $names[strtolower($match[1])];
            $name = str_replace(["\r", "\n"], '', $name);

            return $html ? htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $name;
        }, $value) ?? $value;
    }
}
