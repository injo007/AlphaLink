<?php

namespace App\Services\Infrastructure;

interface DmsBulkInstallPublicIpResolver
{
    public function resolve(string $interfaceIp): string;
}
