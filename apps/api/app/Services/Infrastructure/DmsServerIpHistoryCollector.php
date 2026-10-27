<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServerIp;

/** Mirrors DMS's hourly active IPv4 collection and persists workspace-scoped snapshots. */
class DmsServerIpHistoryCollector
{
    public function __construct(private readonly DmsServerIpHistoryProbe $probe) {}

    public function collect(): int
    {
        $recorded = 0;

        ManagedServerIp::query()
            ->where('type', 'ipv4')
            ->where('is_active', true)
            ->whereHas('server', fn ($query) => $query
                ->where('is_active', true)
                ->where('is_installed', true))
            ->orderBy('managed_server_id')
            ->orderBy('id')
            ->chunk(5, function ($ips) use (&$recorded): void {
                $checks = $this->probe->checkMany($ips->all());

                foreach ($ips as $ip) {
                    $ip->histories()->create([
                        'workspace_id' => $ip->workspace_id,
                        ...($checks[$ip->getKey()] ?? []),
                    ]);
                    $recorded++;
                }
            });

        return $recorded;
    }
}
