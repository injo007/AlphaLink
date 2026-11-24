<?php

namespace App\Services\Infrastructure;

interface AlphaLinkInstallerSshFailureDiagnostics
{
    public function lastFailureReason(): string;
}
