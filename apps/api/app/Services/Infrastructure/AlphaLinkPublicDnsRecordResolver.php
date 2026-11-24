<?php

namespace App\Services\Infrastructure;

class AlphaLinkPublicDnsRecordResolver
{
    /** @return list<array<string, mixed>> */
    public function records(string $hostname, int $type): array
    {
        return @dns_get_record($hostname, $type) ?: [];
    }
}
