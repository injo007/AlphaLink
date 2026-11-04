<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

/** Provider boundary for DNS writes during bulk installation. */
interface AlphaLinkBulkInstallDomainDnsRecordWriter
{
    /** Return whether the provider can currently manage this exact domain. */
    public function isDomainAvailable(SendingDomain $domain): bool;

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setHosts(SendingDomain $domain, array $records): bool;
}
