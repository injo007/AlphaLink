<?php

namespace App\Services\Infrastructure;

use App\Models\User;
use App\Models\Workspace;

/** Supplies the internally pinned, read-only prepared host context to a row runner. */
class DmsBulkInstallPreparedRowRunner implements DmsBulkInstallRowRunner
{
    public function __construct(
        private readonly DmsBulkInstallRowRunner $runner,
        private readonly DmsBulkInstallRowPreparation $preparation,
        private readonly User $user,
        private readonly Workspace $workspace,
    ) {}

    /**
     * @param  array{id: string, domain: string, ip_assignments?: list<array{ip: string, type: string, main: bool, sub: string, domain_id: string}>}  $row
     * @param  array<string, bool|int>  $options
     */
    public function install(array $row, array $options, callable $log): void
    {
        if (isset($row['ip_assignments'])) {
            $prepared = $this->preparation->prepare(
                $this->user,
                $this->workspace,
                $row['id'],
                $row['domain'],
                $row['ip_assignments'],
            );
        } else {
            $prepared = $this->preparation->prepare(
                $this->user,
                $this->workspace,
                $row['id'],
                $row['domain'],
            );
        }

        $this->runner->install($row + ['prepared' => $prepared], $options, $log);
    }
}
