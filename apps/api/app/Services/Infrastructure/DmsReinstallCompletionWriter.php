<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Applies DMS Reinstall persistence rules after every selected remote stage completes. */
class DmsReinstallCompletionWriter
{
    /**
     * @param  list<array{main: bool, ip: string, type: string, domain_id: string, sub: string, domain: string, is_our: bool}>  $rows
     * @param  array{pmta: bool, tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     */
    public function finalize(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        array $rows,
        int $pmtaVersion,
        array $options,
        ?string $requestId = null,
    ): void {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || $rows === []
            || ! in_array($pmtaVersion, [40, 45, 50], true)) {
            throw new InvalidArgumentException('The reinstall completion data is invalid.');
        }

        DB::transaction(function () use ($workspace, $actor, $server, $rows, $pmtaVersion, $options, $requestId): void {
            if ($options['pmta']) {
                ManagedServerMta::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->delete();

                ManagedServerIp::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->update(['is_active' => false]);

                foreach ($rows as $row) {
                    $domain = SendingDomain::withTrashed()
                        ->where('workspace_id', $workspace->getKey())
                        ->whereKey($row['domain_id'])
                        ->firstOrFail();
                    $dns = $domain->is_our && $row['sub'] !== ''
                        ? $row['sub'].'.'.$domain->name
                        : (string) $domain->name;

                    $ip = ManagedServerIp::query()->firstOrNew([
                        'workspace_id' => $workspace->getKey(),
                        'managed_server_id' => $server->getKey(),
                        'ip' => $row['ip'],
                    ]);
                    $ip->forceFill([
                        'sending_domain_id' => $domain->getKey(),
                        'type' => $row['type'],
                        'dns' => $dns,
                        'is_active' => true,
                    ])->saveOrFail();

                    if ($domain->is_our) {
                        $domain->forceFill(['status' => 'used'])->saveOrFail();
                    }
                }
            }

            $installParameters = [
                'data' => $rows,
                'pmta_version' => $pmtaVersion,
                'tracking' => $options['tracking'],
                'proxy' => $options['proxy'],
                'imap' => $options['imap'],
                'spf' => $options['spf'],
                'dkim' => $options['dkim'],
                'dmarc' => $options['dmarc'],
                'https' => $options['https'],
                // PMTA reinstalls always configure and verify its TLS monitor
                // independently of the optional tracking HTTPS component.
                'pmta_monitor_https' => $options['pmta']
                    ? true
                    : (bool) (($server->install_parameters['pmta_monitor_https'] ?? false)),
            ];
            $server->forceFill(['install_parameters' => $installParameters])->saveOrFail();

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'server.reinstalled',
                'subject_type' => 'managed_server',
                'subject_id' => (string) $server->getKey(),
                'request_id' => $requestId,
                'properties' => [
                    'ip_count' => count($rows),
                    'domain_count' => count(array_unique(array_column($rows, 'domain_id'))),
                    'pmta' => $options['pmta'],
                ],
                'occurred_at' => now(),
            ]);
        });
    }
}
