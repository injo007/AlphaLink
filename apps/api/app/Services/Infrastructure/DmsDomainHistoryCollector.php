<?php

namespace App\Services\Infrastructure;

use App\Models\SendingDomain;

/** Stores daily DMS-compatible DNS history for every active workspace domain. */
class DmsDomainHistoryCollector
{
    public function __construct(private readonly DmsDomainHistoryProbe $probe) {}

    /**
     * @return array{recorded:int,listed_domains:list<string>}
     */
    public function collect(): array
    {
        $result = ['recorded' => 0, 'listed_domains' => []];

        SendingDomain::query()
            ->where('is_active', true)
            ->with('workspace.infrastructureSettings')
            ->orderBy('id')
            ->chunkById(100, function ($domains) use (&$result): void {
                foreach ($domains as $domain) {
                    $dkimSelector = (string) ($domain->workspace?->infrastructureSettings?->dkim_key ?? '');
                    $snapshot = $this->probe->check($domain, $dkimSelector);

                    $domain->histories()->create([
                        'workspace_id' => $domain->workspace_id,
                        ...$snapshot,
                    ]);

                    $result['recorded']++;
                    if ($snapshot['is_listed_spamhaus'] || $snapshot['is_listed_hostkarma']) {
                        $result['listed_domains'][] = $domain->name;
                    }
                }
            });

        return $result;
    }
}
