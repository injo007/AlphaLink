<?php

namespace App\Console\Commands;

use App\Models\ManagedServer;
use App\Models\ManagedServerIpDomain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:prune-returned-tracking-endpoints')]
#[Description('Remove saved tracking endpoints 30 days after an installed server is returned.')]
class PruneReturnedServerTrackingEndpoints extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays(30);
        $removed = 0;

        ManagedServer::onlyTrashed()
            ->where('is_installed', true)
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($servers) use (&$removed): void {
                $serverIds = $servers->modelKeys();
                $removed += ManagedServerIpDomain::query()
                    ->whereIn('managed_server_id', $serverIds)
                    ->delete();

                foreach ($servers as $server) {
                    $parameters = (array) $server->install_parameters;
                    unset($parameters['tracking_host']);
                    $parameters['tracking'] = false;
                    $parameters['tracking_https_ready'] = false;
                    $server->forceFill(['install_parameters' => $parameters])->saveQuietly();
                }
            });

        $this->info('Removed '.$removed.' expired server tracking endpoint(s).');

        return self::SUCCESS;
    }
}
