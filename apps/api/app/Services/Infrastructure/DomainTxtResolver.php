<?php

namespace App\Services\Infrastructure;

class DomainTxtResolver
{
    /** @return array<int, string> */
    public function records(string $hostname): array
    {
        $records = dns_get_record($hostname, DNS_TXT);

        return array_values(array_filter(array_map(
            fn (array $record) => (string) ($record['txt'] ?? implode('', $record['entries'] ?? [])),
            $records ?: [],
        )));
    }
}
