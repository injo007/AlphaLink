<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

interface DmsDomainRecordsHostWriter
{
    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setDomainRecordsHosts(SendingDomain $domain, array $records): bool;
}
