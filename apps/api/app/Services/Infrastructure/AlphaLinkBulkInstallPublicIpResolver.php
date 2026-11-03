<?php

namespace App\Services\Infrastructure;

interface AlphaLinkBulkInstallPublicIpResolver
{
    public function resolve(string $interfaceIp): string;
}
