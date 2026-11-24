<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

interface AlphaLinkAssignmentDnsRecordWriter
{
    public function isAssignmentDomainAvailable(SendingDomain $domain): bool;

    /** @param list<array{host: string, address: string, type: string, ttl: string}> $records */
    public function setAssignmentHosts(SendingDomain $domain, array $records): bool;
}
