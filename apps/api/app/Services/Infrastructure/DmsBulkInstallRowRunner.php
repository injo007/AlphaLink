<?php

namespace App\Services\Infrastructure;

interface DmsBulkInstallRowRunner
{
    /**
     * Execute one row using the given DMS options and report progress messages.
     * No production implementation is registered until the remote installer is reconstructed.
     *
     * @param  array{id: string, domain: string}  $row
     * @param  array<string, bool|int>  $options
     * @param  callable(string): void  $log
     */
    public function install(array $row, array $options, callable $log): void;
}
