<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Runs the DMS DNS, SPF and domain reputation checks for one domain. */
class DmsDomainHistoryProbe
{
    /**
     * @return array{a_record:string,spf:?string,dkim:?string,dmarc:?string,is_listed_spamhaus:bool,is_listed_hostkarma:bool}
     */
    public function check(SendingDomain $domain, string $dkimSelector): array
    {
        $name = trim($domain->name, '. ');
        $aAnswers = $this->query(['dig', '+short', '+time=2', '+tries=1', '-t', 'a', $name]);
        $spfAnswers = $this->query(['dig', '+short', '+time=2', '+tries=1', '-t', 'txt', $name]);
        $dkimAnswers = $this->query([
            'dig', '+short', '+time=2', '+tries=1', '-t', 'txt',
            sprintf('%s._domainkey.%s', $dkimSelector, $name),
        ]);
        $dmarcAnswers = $this->query([
            'dig', '+short', '+time=2', '+tries=1', '-t', 'txt', '_dmarc.'.$name,
        ]);

        return [
            'a_record' => explode("\n", $aAnswers ?? '')[0],
            'spf' => $this->firstSpfRecord($spfAnswers),
            'dkim' => $this->assembleTxt($dkimAnswers),
            'dmarc' => $this->assembleTxt($dmarcAnswers),
            'is_listed_spamhaus' => $this->isListed($name.'.dbl.spamhaus.org'),
            'is_listed_hostkarma' => $this->isListed($name.'.black.junkemailfilter.com'),
        ];
    }

    private function isListed(string $query): bool
    {
        return $this->query(['dig', '@208.67.222.222', '+short', '-t', 'a', $query]) !== null;
    }

    /** @param  list<string>  $command */
    private function query(array $command): ?string
    {
        try {
            $output = trim(Process::path(base_path())->timeout(10)->run($command)->output());
        } catch (Throwable) {
            return null;
        }

        if ($output === '' || str_contains(strtolower($output), 'timed out')) {
            return null;
        }

        return $output;
    }

    private function firstSpfRecord(?string $txtAnswers): ?string
    {
        if ($txtAnswers === null || trim($txtAnswers) === '') {
            return null;
        }

        foreach (preg_split('/\R/', $txtAnswers) ?: [] as $line) {
            $assembled = $this->assembleTxt($line) ?? '';
            $sanitized = $this->sanitizeUtf8($assembled);
            $record = trim(preg_replace('/\s+/u', ' ', $sanitized) ?? $sanitized);

            if (preg_match('/^v=spf1\b/i', $record) === 1) {
                return $record;
            }
        }

        return null;
    }

    private function assembleTxt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(?:\s*"(?:\\\\.|[^"\\\\])*"\s*)+$/', $value) === 1) {
            preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $value, $matches);

            return implode('', array_map(
                static fn (string $chunk): string => str_replace(['\\\\', '\\"'], ['\\', '"'], $chunk),
                $matches[1] ?? [],
            ));
        }

        return str_replace(',', '', $value);
    }

    private function sanitizeUtf8(string $value): string
    {
        $value = function_exists('mb_scrub')
            ? mb_scrub($value, 'UTF-8')
            : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
        $value = str_replace("\xC2\xA0", ' ', $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)
            ?? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value)
            ?? $value;
    }
}
