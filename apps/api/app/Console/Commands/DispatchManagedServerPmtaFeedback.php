<?php

namespace App\Console\Commands;

use App\Jobs\SyncManagedServerPmtaFeedback;
use App\Models\ManagedServer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:process-pmta-feedback {serverIds?* : Optional managed server UUIDs to process}')]
#[Description('Dispatch verified managed servers for PMTA feedback accounting import.')]
class DispatchManagedServerPmtaFeedback extends Command
{
    public function handle(): int
    {
        $serverIds = array_values(array_unique(array_filter(
            (array) $this->argument('serverIds'),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        $accountingDate = now()->subMinutes(10)->toDateString();
        $dispatched = 0;

        ManagedServer::query()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->where('state', 'verified')
            ->whereHas('workspace', fn ($query) => $query->where('status', 'active'))
            ->when($serverIds !== [], fn ($query) => $query->whereIn('id', $serverIds))
            ->whereHas('credential', fn ($query) => $query
                ->whereIn('credential_type', ['password', 'ssh_key'])
                ->whereNotNull('encrypted_secret'))
            ->select(['id'])
            ->chunkById(100, function ($servers) use ($accountingDate, &$dispatched): void {
                foreach ($servers as $server) {
                    SyncManagedServerPmtaFeedback::dispatch((string) $server->getKey(), $accountingDate);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} verified managed server(s) for {$accountingDate}.");

        return self::SUCCESS;
    }
}
