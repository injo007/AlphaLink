<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpDomain;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Applies AlphaLink Reinstall persistence rules after every selected remote stage completes. */
class AlphaLinkReinstallCompletionWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
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
        bool $pmtaMonitorHttps = true,
        ?string $trackingHost = null,
        bool $trackingHttpsReady = false,
    ): void {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || $rows === []
            || ! in_array($pmtaVersion, [40, 45, 50], true)) {
            throw new InvalidArgumentException('The reinstall completion data is invalid.');
        }

        DB::transaction(function () use ($workspace, $actor, $server, $rows, $pmtaVersion, $options, $requestId, $pmtaMonitorHttps, $trackingHost, $trackingHttpsReady): void {
            if ($options['pmta']) {
                ManagedServerMta::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->delete();

                ManagedServerIp::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->update(['is_active' => false]);

                ManagedServerIpDomain::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->update(['is_active' => false, 'is_primary' => false]);

                foreach ($rows as $row) {
                    $domain = SendingDomain::withTrashed()
                        ->where('workspace_id', $workspace->getKey())
                        ->whereKey($row['domain_id'])
                        ->firstOrFail();
                    $sub = trim((string) ($row['sub'] ?? ''));
                    $dns = $sub !== ''
                        ? $sub.'.'.$domain->name
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
                        'ehlo_hostname' => (string) ($row['ehlo_hostname'] ?? $dns),
                        'is_active' => true,
                    ])->saveOrFail();

                    $assignments = [[...$row, 'is_primary' => true]];
                    foreach ($row['domains'] ?? [] as $additional) {
                        $assignments[] = [...$additional, 'is_primary' => false];
                    }
                    foreach ($assignments as $assignment) {
                        $assignmentDomain = SendingDomain::withTrashed()
                            ->where('workspace_id', $workspace->getKey())
                            ->whereKey($assignment['domain_id'])
                            ->firstOrFail();
                        $assignmentSub = trim((string) ($assignment['sub'] ?? ''));
                        $hostname = strtolower(rtrim(
                            $assignmentSub !== '' ? $assignmentSub.'.'.$assignmentDomain->name : (string) $assignmentDomain->name,
                            '.',
                        ));
                        $dnsVerified = (bool) ($assignment['dns_verified'] ?? false);
                        $authenticationVerified = (bool) ($assignment['authentication_verified'] ?? false);
                        $trackingReady = (bool) ($assignment['tracking_https_ready'] ?? false);
                        ManagedServerIpDomain::query()->updateOrCreate([
                            'workspace_id' => $workspace->getKey(),
                            'managed_server_ip_id' => $ip->getKey(),
                            'hostname' => $hostname,
                        ], [
                            'managed_server_id' => $server->getKey(),
                            'sending_domain_id' => $assignmentDomain->getKey(),
                            'tracking_host' => $assignment['tracking_host'] ?? null,
                            'sending_domain_dkim_identity_id' => $assignment['sending_domain_dkim_identity_id'] ?? null,
                            'dkim_selector' => $assignment['dkim_selector'] ?? null,
                            'is_primary' => (bool) $assignment['is_primary'],
                            'is_active' => true,
                            'tracking_https_ready' => $trackingReady,
                            'dns_verified_at' => $dnsVerified ? now() : null,
                            'authentication_verified_at' => $authenticationVerified ? now() : null,
                        ]);

                        if ($assignmentDomain->is_our) {
                            $assignmentDomain->forceFill(['status' => 'used'])->saveOrFail();
                        }
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
                'tracking_https_ready' => $options['tracking'] && $trackingHttpsReady,
                'pmta_monitor_https' => $options['pmta']
                    ? $pmtaMonitorHttps
                    : (bool) (($server->install_parameters['pmta_monitor_https'] ?? false)),
            ];
            if ($options['tracking'] && ($trackingHost !== null || ! isset($server->install_parameters['tracking_host']))) {
                $installParameters['tracking_host'] = $trackingHost ?: (string) $server->ip_address;
            } elseif (isset($server->install_parameters['tracking_host'])) {
                $installParameters['tracking_host'] = $server->install_parameters['tracking_host'];
            }
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
                    'domain_count' => count(array_unique(array_merge(
                        array_column($rows, 'domain_id'),
                        ...array_map(static fn (array $row): array => array_column($row['domains'] ?? [], 'domain_id'), $rows),
                    ))),
                    'pmta' => $options['pmta'],
                ],
                'occurred_at' => now(),
            ]);
        });
    }
}
