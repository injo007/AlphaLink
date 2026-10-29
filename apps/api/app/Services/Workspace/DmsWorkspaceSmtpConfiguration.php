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
use Illuminate\Database\Eloquent\Collection;
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
            try {
                $data = '';
                foreach ($serverIps as $ip) {
                    foreach ($ip['smtp_accounts'] as $account) {
                        $mta = ManagedServerMta::query()->create([
                            'workspace_id' => $workspace->id,
                            'managed_server_id' => $server->id,
                            'managed_server_ip_id' => $ip['id'],
                            'ip' => $ip['ip'],
                            'dns' => $ip['dns'],
                            'ip_type' => $ip['type'],
                            'mta_type' => 'smtp',
                            'username' => $account['email'],
                            'is_active' => true,
                            'user_id' => $user->id,
                        ]);
                        $createdIds[] = $mta->id;
                        $data .= $this->renderVmta($mta, $account['project_name'], (int) ($server->install_parameters['pmta_version'] ?? 40));
                    }
                }
                if ($data === '') {
                    throw new RuntimeException('Nothing Generated');
                }

                $session = $this->remote->open($server);
                $this->installRelay($session, $projects, $relayAssets);
                $temporary = '/etc/pmta/conf/tmp_workspace_'.$user->id;
                $configuration = '/etc/pmta/conf/workspace_'.$user->id;
                $data = str_replace(['$', '"', '!', '___'], ['\$', '\"', '\!', '"'], $data);
                if (! $session->upload($data, $temporary)) {
                    throw new RuntimeException('Workspace SMTP configuration upload failed.');
                }
                $reload = (string) $session->exec('pmta reload');
                if (! str_contains($reload, 'reloaded') && ! str_contains($reload, 'running')) {
                    $session->exec('rm -f '.escapeshellarg($temporary));
                    $session->exec('pmta reload');
                    throw new RuntimeException($reload !== '' ? $reload : 'PMTA did not confirm the configuration reload.');
                }
                $session->exec('cat '.escapeshellarg($temporary).' >> '.escapeshellarg($configuration));
                $session->exec('pmta reload');
                $session->exec('rm -f '.escapeshellarg($temporary));
                $results[] = ['id' => (string) $server->id, 'data' => 'Workspace Pushed Successfully'];
            } catch (Throwable $exception) {
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
        $ipName = str_replace(':', '.', $mta->ip);
        $route = $version !== 40 ? 'smtp-hosts' : 'route';

        return "<virtual-mta smtp-{$ipName}-{$mta->id}>\n"
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
        if (! is_executable($assets['binary'])) {
            throw new RuntimeException('The Workspace relay executable is not executable.');
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
    private function installRelay(DmsInstallerSshSession $session, Collection $projects, array $assets): void
    {
        $session->exec('lsof -t -i:1028 2>/dev/null | xargs -r kill -9');
        $session->exec('mkdir -p /etc/workspace_relay/accounts /var/lib/workspace_relay /var/log/workspace_relay');
        $session->exec('rm -f /usr/sbin/workspace_relay');
        if (! $session->uploadFile($assets['binary'], '/usr/sbin/workspace_relay')) {
            throw new RuntimeException('Workspace relay binary upload failed.');
        }
        $session->exec('chmod +x /usr/sbin/workspace_relay && chown root:root /usr/sbin/workspace_relay');
        $session->exec('rm -f /etc/workspace_relay/config.json');
        if (! $session->uploadFile($assets['config'], '/etc/workspace_relay/config.json')) {
            throw new RuntimeException('Workspace relay configuration upload failed.');
        }
        foreach ($projects as $project) {
            if (! $session->upload($project->service_account_json, '/etc/workspace_relay/accounts/'.$project->name.'.json')) {
                throw new RuntimeException('Workspace service-account upload failed.');
            }
        }
        $session->exec('chmod 600 /etc/workspace_relay/accounts/*.json');
        $serviceExists = trim((string) $session->exec('systemctl list-unit-files | grep -q "^workspace_relay.service" && echo y || echo n'));
        if ($serviceExists === 'n') {
            if (! $session->uploadFile($assets['service'], '/etc/systemd/system/workspace_relay.service')) {
                throw new RuntimeException('Workspace relay service upload failed.');
            }
            $session->exec('systemctl daemon-reload && systemctl enable workspace_relay.service');
        }
        $session->exec('systemctl restart workspace_relay.service');
    }
}
