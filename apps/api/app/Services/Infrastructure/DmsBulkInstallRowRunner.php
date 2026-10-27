<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;

interface DmsBulkInstallRowRunner
{
    /**
     * Execute one row using the given DMS options and report progress messages.
     * The coordinator wrapper adds a prepared context with internally pinned server identity,
     * selected models, ordered discovered IPs, and workspace ISP names before delegation.
     * The production implementation is registered but remains protected by the default-off
     * installation execution gate and the selected PMTA readiness checks.
     *
     * @param  array{id: string, domain: string, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>, prepared?: array{workspace: Workspace, server: ManagedServer, domain: SendingDomain, domains?: list<SendingDomain>, ips: list<string>, isps: list<string>, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string, domain: string, is_our: bool}>}}  $row
     * @param  array<string, bool|int>  $options
     * @param  callable(string): void  $log
     */
    public function install(array $row, array $options, callable $log): void;
}
