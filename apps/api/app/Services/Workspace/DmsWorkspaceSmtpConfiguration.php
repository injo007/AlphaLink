<?php

namespace App\Services\Workspace;

use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGoogleProject;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\PmtaRemoteConfiguration;
use App\Services\PmtaSmtpConfigBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** DMS-mapped Workspace SMTP push/reset for the selected AlphaLink user. */
class DmsWorkspaceSmtpConfiguration
{
    public function __construct(private readonly PmtaRemoteConfiguration $remote) {}

    /** @return list<array{id: string, data: string}> */
    public function push(Workspace $workspace, User $user, array $ipIds, array $projectIds, bool $splitOnIps): array
    {
        $ips = $this->selectedIps($workspace, $ipIds);
        $projects = WorkspaceGoogleProject::query()->with([
            'accounts' => fn ($query) => $query->where('is_active', true),
        ])
            ->where('workspace_id', $workspace->id)->where('is_active', true)->whereIn('id', $projectIds)->get();
        if ($projects->count() !== count(array_unique($projectIds))) {
            throw new RuntimeException('Project not found or inactive.');
        }

        $smtpAccounts = [];
        foreach ($projects as $project) {
            foreach ($project->accounts as $account) {
                $smtpAccounts[] = ['email' => $account->email, 'project_name' => $project->name];
            }
        }
        if ($smtpAccounts === []) {
            throw new RuntimeException('No active Workspace accounts were found in the selected projects.');
        }

        $relayAssets = $this->requireRelayAssets();
        $chunks = collect($smtpAccounts)->split(count($ips));
        foreach ($ips as $index => $ip) {
            $ips[$index]['smtp_accounts'] = $splitOnIps ? ($chunks->has($index) ? $chunks[$index]->all() : []) : $smtpAccounts;
        }
        $grouped = collect($ips)->groupBy('managed_server_id');
        $results = [];
        foreach ($grouped as $serverId => $serverIps) {
            $server = ManagedServer::query()->where('workspace_id', $workspace->id)->whereKey($serverId)->first();
            if (! $server) {
                $results[] = ['id' => (string) $serverId, 'data' => 'Server Not Found'];

                continue;
            }
            $createdIds = [];
            $routeIdsToActivate = [];
            $relayDeploymentMayBeBroken = false;
            try {
                $existingRoutes = ManagedServerMta::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('managed_server_id', $server->id)
                    ->where('user_id', $user->id)
                    ->where('mta_type', 'smtp')
                    ->orderBy('id')
                    ->get()
                    ->groupBy(fn (ManagedServerMta $mta): string => $mta->managed_server_ip_id.'|'.mb_strtolower(trim((string) $mta->username)).'|'.(string) $mta->name);
                foreach ($serverIps as $ip) {
                    foreach ($ip['smtp_accounts'] as $account) {
                        $routeKey = $ip['id'].'|'.mb_strtolower(trim($account['email'])).'|'.$account['project_name'];
                        $matchingRoutes = $existingRoutes->get($routeKey, collect());
                        $mta = $matchingRoutes->first(fn (ManagedServerMta $route): bool => $route->is_active)
                            ?? $matchingRoutes->first();
                        if (! $mta) {
                            $mta = ManagedServerMta::query()->create([
                                'workspace_id' => $workspace->id,
                                'managed_server_id' => $server->id,
                                'managed_server_ip_id' => $ip['id'],
                                'ip' => $ip['ip'],
                                'dns' => $ip['dns'],
                                'ip_type' => $ip['type'],
                                'mta_type' => 'smtp',
                                'username' => $account['email'],
                                'name' => $account['project_name'],
                                // Keep routes out of the campaign catalog until the
                                // remote relay, PMTA config, and final reload pass.
                                'is_active' => false,
                                'user_id' => $user->id,
                            ]);
                            $createdIds[] = $mta->id;
                            $existingRoutes->put($routeKey, $matchingRoutes->push($mta));
                        }
                        $routeIdsToActivate[] = (int) $mta->getKey();
                    }
                }
                if ($routeIdsToActivate === []) {
                    throw new RuntimeException('Nothing Generated');
                }

                $configurationRoutes = ManagedServerMta::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('managed_server_id', $server->id)
                    ->where('user_id', $user->id)
                    ->where('mta_type', 'smtp')
                    ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $routeIdsToActivate))
                    ->orderBy('id')
                    ->get();
                $data = $configurationRoutes
                    ->map(fn (ManagedServerMta $mta): string => $this->renderVmta(
                        $mta,
                        (string) ($mta->name ?: $mta->username),
                        (int) ($server->install_parameters['pmta_version'] ?? 40),
                    ))
                    ->implode('');

                $session = $this->remote->open($server);
                $this->installRelay($session, $projects, $relayAssets, $relayDeploymentMayBeBroken);
                $relayDeploymentMayBeBroken = false;
                $temporary = '/etc/pmta/conf/tmp_workspace_'.$user->id;
                $configuration = '/etc/pmta/conf/workspace_'.$user->id;
                $data = str_replace(['$', '"', '!', '___'], ['\$', '\"', '\!', '"'], $data);
                if (! $session->upload($data, $temporary)) {
                    throw new RuntimeException('Workspace SMTP configuration upload failed.');
                }
                $backup = '/etc/pmta/.workspace_'.$user->id.'-'.Str::uuid().'.bak';
                $hadConfiguration = trim((string) $session->exec('test -f '.escapeshellarg($configuration).' && echo yes || echo no')) === 'yes';
                if ($hadConfiguration) {
                    $backupResult = (string) $session->exec('cp -p '.escapeshellarg($configuration).' '.escapeshellarg($backup).' && echo __WORKSPACE_CONFIG_BACKUP_READY__');
                    if (! str_contains($backupResult, '__WORKSPACE_CONFIG_BACKUP_READY__')) {
                        throw new RuntimeException('The existing Workspace SMTP configuration could not be backed up.');
                    }
                }

                try {
                    $installResult = (string) $session->exec('mv -f '.escapeshellarg($temporary).' '.escapeshellarg($configuration).' && echo __WORKSPACE_CONFIG_INSTALLED__');
                    if (! str_contains($installResult, '__WORKSPACE_CONFIG_INSTALLED__')) {
                        throw new RuntimeException('The Workspace SMTP configuration could not be installed.');
                    }

                    $finalReload = (string) $session->exec('pmta reload');
                    if (! str_contains($finalReload, 'reloaded') && ! str_contains($finalReload, 'running')) {
                        throw new RuntimeException($finalReload !== '' ? $finalReload : 'PMTA did not confirm the Workspace SMTP configuration reload.');
                    }

                    $routeChecks = $configurationRoutes
                        ->map(fn (ManagedServerMta $mta): string => 'grep -Fq -- '.escapeshellarg(
                            '<virtual-mta '.PmtaSmtpConfigBuilder::workspaceVirtualMtaName((string) $mta->ip, (int) $mta->getKey()).'>',
                        ).' '.escapeshellarg($configuration))
                        ->implode(' && ');
                    $routeHealth = (string) $session->exec('set -e; '.$routeChecks.' && echo __WORKSPACE_PMTA_ROUTES_READY__');
                    if (! str_contains($routeHealth, '__WORKSPACE_PMTA_ROUTES_READY__')) {
                        throw new RuntimeException('The Workspace SMTP routes were not present in the reloaded PMTA configuration.');
                    }
                } catch (Throwable $exception) {
                    if ($hadConfiguration) {
                        $session->exec('mv -f '.escapeshellarg($backup).' '.escapeshellarg($configuration));
                    } else {
                        $session->exec('rm -f '.escapeshellarg($configuration));
                    }
                    $session->exec('pmta reload');

                    throw $exception;
                } finally {
                    $session->exec('rm -f '.escapeshellarg($temporary));
                }

                if ($hadConfiguration) {
                    $session->exec('rm -f '.escapeshellarg($backup));
                }
                ManagedServerMta::query()->where('workspace_id', $workspace->id)
                    ->where('managed_server_id', $server->id)->where('user_id', $user->id)
                    ->whereIn('id', $routeIdsToActivate)->update(['is_active' => true]);
                $results[] = ['id' => (string) $server->id, 'data' => 'Workspace Pushed Successfully'];
            } catch (Throwable $exception) {
                if ($relayDeploymentMayBeBroken) {
                    // installRelay stops/replaces the service before its final
                    // listener check. Existing rows are no longer trustworthy
                    // if the new relay fails that check, so hide them from
                    // Campaign Test until a healthy push succeeds.
                    ManagedServerMta::query()->where('workspace_id', $workspace->id)
                        ->where('managed_server_id', $server->id)->where('user_id', $user->id)
                        ->where('mta_type', 'smtp')->update(['is_active' => false]);
                }
                if ($createdIds !== []) {
                    ManagedServerMta::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->whereIn('id', $createdIds)->delete();
                }
                $results[] = ['id' => (string) $server->id, 'data' => $exception->getMessage()];
            }
        }

        return $results;
    }

    /** @return list<array{id: string, data: string}> */
    public function reset(Workspace $workspace, User $user, array $ipIds): array
    {
        $ips = $this->selectedIps($workspace, $ipIds);
        $grouped = collect($ips)->groupBy('managed_server_id');
        $results = [];
        foreach ($grouped as $serverId => $serverIps) {
            $server = ManagedServer::query()->where('workspace_id', $workspace->id)->whereKey($serverId)->first();
            if (! $server) {
                $results[] = ['id' => (string) $serverId, 'data' => 'Server Not Found'];

                continue;
            }

            // DMS deletes the user's selected-server SMTP rows before remote cleanup.
            ManagedServerMta::query()->where('workspace_id', $workspace->id)->where('managed_server_id', $server->id)
                ->where('user_id', $user->id)->where('mta_type', 'smtp')->delete();
            try {
                $session = $this->remote->open($server);
                $session->exec('rm -rf '.escapeshellarg('/etc/pmta/conf/workspace_'.$user->id));
                $session->exec('pmta reload');
                $results[] = ['id' => (string) $server->id, 'data' => 'Restored Successfully'];
            } catch (Throwable $exception) {
                $results[] = ['id' => (string) $server->id, 'data' => $exception->getMessage()];
            }
        }

        return $results;
    }

    private function selectedIps(Workspace $workspace, array $ipIds): array
    {
        $ips = ManagedServerIp::query()->with('server')
            ->where('workspace_id', $workspace->id)->where('is_active', true)
            ->whereHas('server', fn ($query) => $query->where('is_active', true)->where('is_installed', true))
            ->whereIn('id', $ipIds)->orderBy('id')->get();
        if ($ips->count() !== count(array_unique($ipIds))) {
            throw new RuntimeException('Choose active IPs from active, installed servers in this workspace.');
        }

        return $ips->map(fn (ManagedServerIp $ip): array => [
            'id' => $ip->id,
            'managed_server_id' => $ip->managed_server_id,
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'type' => $ip->type,
        ])->all();
    }

    private function renderVmta(ManagedServerMta $mta, string $projectName, int $version): string
    {
        $email = $mta->username;
        $password = rtrim(strtr(base64_encode($projectName), '+/', '-_'), '=');
        $route = $version !== 40 ? 'smtp-hosts' : 'route';

        return '<virtual-mta '.PmtaSmtpConfigBuilder::workspaceVirtualMtaName((string) $mta->ip, (int) $mta->getKey()).">\n"
            ."    smtp-source-ip {$mta->ip}\n"
            ."    host-name {$mta->dns}\n"
            ."<domain *>\n"
            ."    queue-to workspace.queue\n"
            ."    deliver-local-dsn no\n"
            ."</domain>\n"
            ."<domain workspace.queue>\n"
            ."    use-unencrypted-plain-auth yes\n"
            ."    auth-username {$email}\n"
            ."    auth-password ___{$password}___\n"
            ."    {$route} 127.0.0.1:1028\n"
            ."    max-smtp-out 5\n"
            ."    require-starttls yes\n"
            ."</domain>\n"
            ."</virtual-mta>\n";
    }

    /** @return array{binary: string, service: string, config: string} */
    private function requireRelayAssets(): array
    {
        $directory = rtrim((string) config('infrastructure.workspace_relay_assets_directory'), DIRECTORY_SEPARATOR);
        if ($directory === '') {
            throw new RuntimeException('Workspace relay deployment assets are missing or invalid.');
        }
        $assets = [
            'binary' => $directory.DIRECTORY_SEPARATOR.'workspace_relay',
            'service' => $directory.DIRECTORY_SEPARATOR.'workspace_relay.service',
            'config' => $directory.DIRECTORY_SEPARATOR.'config.json',
        ];
        foreach ($assets as $name => $path) {
            if (! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) === 0) {
                throw new RuntimeException('Workspace relay deployment assets are missing or invalid.');
            }
        }
        $configuration = json_decode((string) file_get_contents($assets['config']), true);
        if (! is_array($configuration)
            || ($configuration['listen_addr'] ?? null) !== '127.0.0.1:1028'
            || ($configuration['service_accounts_dir'] ?? null) !== '/etc/workspace_relay/accounts') {
            throw new RuntimeException('The Workspace relay configuration is invalid.');
        }

        return $assets;
    }

    /**
     * @param  Collection<int, WorkspaceGoogleProject>  $projects
     * @param  array{binary: string, service: string, config: string}  $assets
     */
    private function installRelay(
        DmsInstallerSshSession $session,
        Collection $projects,
        array $assets,
        bool &$relayDeploymentMayBeBroken,
    ): void {
        $this->requireRemoteMarker(
            $session,
            'mkdir -p /etc/workspace_relay/accounts /var/lib/workspace_relay /var/log/workspace_relay && chmod 700 /etc/workspace_relay /etc/workspace_relay/accounts && echo __WORKSPACE_DIRS_READY__',
            '__WORKSPACE_DIRS_READY__',
            'Workspace relay directories could not be prepared.',
        );

        $binaryTemporary = '/usr/sbin/.workspace_relay-'.Str::uuid().'.new';
        if (! $session->uploadFile($assets['binary'], $binaryTemporary)) {
            throw new RuntimeException('Workspace relay binary upload failed.');
        }

        $configurationTemporary = '/etc/workspace_relay/.config-'.Str::uuid().'.new';
        if (! $session->uploadFile($assets['config'], $configurationTemporary)) {
            throw new RuntimeException('Workspace relay configuration upload failed.');
        }

        foreach ($projects as $project) {
            $accountPath = '/etc/workspace_relay/accounts/'.$project->name.'.json';
            $accountTemporary = '/etc/workspace_relay/accounts/.account-'.Str::uuid().'.new';
            if (! $session->upload($project->service_account_json, $accountTemporary)) {
                throw new RuntimeException('Workspace service-account upload failed.');
            }

            $this->requireRemoteMarker(
                $session,
                'test -s '.escapeshellarg($accountTemporary).' && chmod 600 '.escapeshellarg($accountTemporary)
                    .' && mv -f '.escapeshellarg($accountTemporary).' '.escapeshellarg($accountPath)
                    .' && test -s '.escapeshellarg($accountPath).' && echo __WORKSPACE_ACCOUNT_READY__',
                '__WORKSPACE_ACCOUNT_READY__',
                'Workspace service-account file was not installed correctly.',
            );
        }

        $binaryDestination = '/usr/sbin/workspace_relay';
        $configurationDestination = '/etc/workspace_relay/config.json';
        $serviceDestination = '/etc/systemd/system/workspace_relay.service';
        $serviceTemporary = '/etc/systemd/system/.workspace_relay-'.Str::uuid().'.new';
        if (! $session->uploadFile($assets['service'], $serviceTemporary)) {
            throw new RuntimeException('Workspace relay service upload failed.');
        }

        $this->requireRemoteMarker(
            $session,
            'if ! test -s '.escapeshellarg($binaryTemporary)
                .' || ! test -s '.escapeshellarg($configurationTemporary)
                .' || ! test -s '.escapeshellarg($serviceTemporary)
                .'; then echo "Workspace relay staging files are incomplete." >&2; exit 1; fi; echo __WORKSPACE_RELAY_STAGED__',
            '__WORKSPACE_RELAY_STAGED__',
            'Workspace relay staging files are incomplete.',
        );

        $relayDeploymentMayBeBroken = true;
        $activation = $session->exec(
            'set -e; systemctl stop workspace_relay.service >/dev/null 2>&1 || true; '
                .'chmod 700 '.escapeshellarg($binaryTemporary).' && chown root:root '.escapeshellarg($binaryTemporary)
                .' && mv -f '.escapeshellarg($binaryTemporary).' '.escapeshellarg($binaryDestination)
                .' && chmod 600 '.escapeshellarg($configurationTemporary)
                .' && chown root:root '.escapeshellarg($configurationTemporary)
                .' && mv -f '.escapeshellarg($configurationTemporary).' '.escapeshellarg($configurationDestination)
                .' && chmod 600 /etc/workspace_relay/accounts/*.json'
                .' && chown root:root '.escapeshellarg($serviceTemporary)
                .' && mv -f '.escapeshellarg($serviceTemporary).' '.escapeshellarg($serviceDestination)
                .' && systemctl daemon-reload && systemctl enable workspace_relay.service'
                .' && systemctl restart workspace_relay.service'
                .' && systemctl is-active --quiet workspace_relay.service'
                .' && ss -H -ltn sport = :1028 | awk \'{print $4}\' | grep -Fxq 127.0.0.1:1028'
                .' && echo __WORKSPACE_RELAY_READY__',
        );
        if (! is_string($activation) || ! str_contains($activation, '__WORKSPACE_RELAY_READY__')) {
            $session->exec('rm -f '.escapeshellarg($binaryTemporary).' '.escapeshellarg($configurationTemporary).' '.escapeshellarg($serviceTemporary));
            throw new RuntimeException('Workspace relay failed its post-install service or listener health check. Existing Workspace SMTP routes were disabled until a healthy push succeeds.');
        }
    }

    private function requireRemoteMarker(
        DmsInstallerSshSession $session,
        string $command,
        string $marker,
        string $message,
    ): void {
        $output = $session->exec('set -e; '.$command);
        if (! is_string($output) || ! str_contains($output, $marker)) {
            throw new RuntimeException($message);
        }
    }
}
