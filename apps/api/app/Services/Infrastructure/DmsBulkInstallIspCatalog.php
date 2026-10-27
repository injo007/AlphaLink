<?php

namespace App\Services\Infrastructure;

use App\Models\Workspace;

class DmsBulkInstallIspCatalog
{
    public function __construct(private readonly DmsBulkInstallIspPlanner $planner) {}

    /**
     * DMS IspAction::build_vmtas() plucks all ISP names, including inactive
     * records, and then lowercases and deduplicates them for PMTA VMTA names.
     *
     * @return list<string>
     */
    public function vmtaNames(Workspace $workspace): array
    {
        $names = $workspace->isps()->orderBy('id')->pluck('name')->all();

        return $this->planner->buildVmtaNames($names);
    }
}
