<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

/** Provider boundary for the DNS record write in DMS ServerInstallBulk. */
interface DmsBulkInstallDomainDnsRecordWriter
{
    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setHosts(SendingDomain $domain, array $records): bool;
}
