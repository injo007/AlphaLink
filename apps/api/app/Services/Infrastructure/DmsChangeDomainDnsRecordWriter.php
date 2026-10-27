<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

interface DmsChangeDomainDnsRecordWriter
{
    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setHosts(SendingDomain $domain, array $records): bool;
}
