<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Executes the DMS ServerChangeDomain order against an already installed AlmaLinux 9 host. */
class DmsChangeDomainRunner
{
    public function __construct(
        private readonly DmsChangeDomainSelectionLoader $selectionLoader,
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly DmsCentos9HostPreparation $hostPreparation,
        private readonly DmsBulkInstallDnsRecordPlanner $dnsPlanner,
        private readonly DmsBulkInstallDomainRecordStage $domainRecordStage,
        private readonly DmsCentos9VmtaDeploymentStage $vmtaStage,
        private readonly DmsCentos9HttpsDeploymentStage $httpsStage,
        private readonly DmsChangeDomainDnsRecordWriter $dnsWriter,
        private readonly DmsBulkInstallIspCatalog $ispCatalog,
        private readonly DmsBulkInstallPrivateAssetReader $assets,
    ) {}

    /**
     * @param  list<array{ip_id: int, ip: string, type: string, old_domain_id: string, domain_id: string, sub: string, main: bool}>  $rows
     * @param  array{https: bool, spf: bool, dkim: bool, dmarc: bool}  $options
     * @param  callable(string): void  $log
     */
    public function run(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        array $rows,
        array $options,
        callable $log,
        ?string $requestId = null,
    ): void {
        if ((string) $server->workspace_id !== (string) $workspace->getKey()
            || ! $server->is_active || ! $server->is_installed) {
            throw new RuntimeException('Choose an active, installed server from this workspace.');
        }
        $hostFacts = is_array($server->host_facts) ? $server->host_facts : [];
        if ((new DmsInstallPlatform)->installerFamily((string) ($hostFacts['os_pretty_name'] ?? '')) !== 'centos9') {
            throw new RuntimeException('Change Domain is currently available for the verified AlmaLinux 9 / CentOS 9 host workflow.');
        }

        $saved = $this->selectionLoader->load($workspace, $server)['data'];
        $this->validateRows($saved, $rows);
        $domains = $this->loadDomains($workspace, $rows);
        $parameters = is_array($server->install_parameters) ? $server->install_parameters : [];
        $tracking = (bool) ($parameters['tracking'] ?? false);
        $imap = (bool) ($parameters['imap'] ?? false);
        $dkimSelector = (string) ($workspace->infrastructureSettings?->dkim_key ?? '');
        $dkimMaterial = ['value' => '', 'key' => ''];
        if ($options['dkim']) {
            $root = config('infrastructure.install_assets_directory');
            if (! is_string($root) || $root === '') {
                throw new RuntimeException('The AlphaLink DKIM asset directory is unavailable.');
            }
            $dkimMaterial = json_decode($this->assets->read($root, 'install/dkim.json'), true) ?? [];
            if (! is_string($dkimMaterial['value'] ?? null) || ! is_string($dkimMaterial['key'] ?? null) || $dkimSelector === '') {
                throw new RuntimeException('The selected DKIM settings are incomplete.');
            }
        }

        $mainRow = collect($rows)->firstWhere('main', true) ?? $rows[0];
        $mainDomain = $domains[(string) $mainRow['domain_id']];
        $subdomains = $this->subdomains($rows, $domains);
        $formatted = $this->domainGroups($rows, $domains);

        $log('Prepare Changing Domain Server : '.$server->getKey());
        $credential = $this->credentials->resolve($server);
        $session = new DmsFailClosedInstallerTransport(
            $this->sessions->create($server, $credential['secret'], $credential['type']),
        );
        $dnsGroups = $this->resolveDnsGroups(
            $formatted, $tracking, $imap, $options, $dkimSelector, $dkimMaterial['key'], $session, $log,
        );

        $log('Setup Host');
        $this->hostPreparation->configureHost($session, (string) $server->ip_address, (string) $mainDomain->name);
        $log('End Setting Host');

        $log('Setup VHost');
        $this->writeVhost($session, (string) $mainDomain->name, $subdomains);
        $log('End Setting VHost');

        $this->writeDomainRecords($dnsGroups, $tracking, $imap, $options, $dkimSelector, $dkimMaterial['key'], $log);

        $log('Setup VMTAS');
        $vmtaRows = [];
        foreach ($rows as $row) {
            $domain = $domains[(string) $row['domain_id']];
            $vmtaRows[] = [
                'ip' => $row['ip'],
                'sub' => $row['sub'],
                'domain' => (string) $domain->name,
                'is_our' => (bool) $domain->is_our,
            ];
        }
        $this->vmtaStage->run(
            $session,
            $session,
            false,
            $vmtaRows,
            $this->ispCatalog->vmtaNames($workspace),
            $dkimSelector,
            $options['dkim'],
            $dkimMaterial['value'],
        );
        $log('End Setting VMTAS');

        if ($options['https']) {
            $log('Setup Https');
            $this->httpsStage->run($session, (string) $mainDomain->name, $subdomains);
            $log('Setting Https');
        }

        $log('Cleaning');
        $this->persist($workspace, $actor, $server, $rows, $domains, $parameters, $tracking, $imap, $options, $requestId);
        $log('Done Changing Domain');
    }

    /** @param list<array<string, mixed>> $saved @param list<array<string, mixed>> $rows */
    private function validateRows(array $saved, array $rows): void
    {
        if ($rows === [] || count($rows) !== count($saved) || count($rows) > 128) {
            throw new InvalidArgumentException('The changed IP assignments must include every saved server IP exactly once.');
        }

        $savedById = collect($saved)->keyBy('ip_id');
        $seen = [];
        $mainCount = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['ip_id']) || ! is_numeric($row['ip_id'])) {
                throw new InvalidArgumentException('The changed IP assignment is invalid.');
            }
            $id = (int) $row['ip_id'];
            $original = $savedById->get($id);
            if ($original === null || isset($seen[$id])) {
                throw new InvalidArgumentException('The changed IP assignments do not match the saved server IPs.');
            }
            $seen[$id] = true;
            foreach (['ip', 'type', 'old_domain_id'] as $key) {
                if (! is_string($row[$key] ?? null) || $row[$key] !== $original[$key]) {
                    throw new InvalidArgumentException('The changed IP assignments do not match the saved server IPs.');
                }
            }
            if (! is_string($row['domain_id'] ?? null) || ! Str::isUuid($row['domain_id'])
                || ! is_string($row['sub'] ?? null) || strlen($row['sub']) > 63
                || ($row['sub'] !== '' && ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $row['sub']))
                || ! is_bool($row['main'] ?? null)) {
                throw new InvalidArgumentException('The changed domain or subdomain values are invalid.');
            }
            $mainCount += $row['main'] ? 1 : 0;
        }
        if ($mainCount > 1) {
            throw new InvalidArgumentException('Only one server IP can be marked as MAIN.');
        }
    }

    /** @param list<array<string, mixed>> $rows @return array<string, SendingDomain> */
    private function loadDomains(Workspace $workspace, array $rows): array
    {
        $ids = array_values(array_unique(array_column($rows, 'domain_id')));
        $domains = $workspace->sendingDomains()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereIn('status', ['free', 'new', 'used'])
            ->with(['histories' => fn ($query) => $query->select('id', 'workspace_id', 'sending_domain_id', 'is_listed_spamhaus', 'is_listed_hostkarma')])
            ->get()
            ->filter(fn (SendingDomain $domain): bool => $domain->histories->isEmpty() || $domain->histories->contains(
                fn ($history): bool => ! $history->is_listed_spamhaus && ! $history->is_listed_hostkarma,
            ))
            ->keyBy(fn (SendingDomain $domain): string => (string) $domain->getKey())
            ->all();

        if (count($domains) !== count($ids)) {
            throw new RuntimeException('One or more selected domains are no longer active and available.');
        }

        return $domains;
    }

    /** @param list<array<string, mixed>> $rows @param array<string, SendingDomain> $domains @return list<string> */
    private function subdomains(array $rows, array $domains): array
    {
        $result = [];
        foreach ($rows as $row) {
            $domain = $domains[$row['domain_id']];
            if ($domain->is_our) {
                $result[] = 'www.'.$domain->name;
                $result[] = $row['sub'] === '' ? (string) $domain->name : $row['sub'].'.'.$domain->name;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param list<array<string, mixed>> $rows @param array<string, SendingDomain> $domains
     * @return array<string, array{domain: SendingDomain, rows: list<array<string, mixed>>}>
     */
    private function domainGroups(array $rows, array $domains): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $domain = $domains[$row['domain_id']];
            $groups[(string) $domain->getKey()]['domain'] = $domain;
            $groups[(string) $domain->getKey()]['rows'][] = [
                ...$row,
                'domain' => (string) $domain->name,
            ];
        }

        return $groups;
    }

    /** @param array<string, array{domain: SendingDomain, rows: list<array<string, mixed>>}> $groups
     * @param  array{https: bool, spf: bool, dkim: bool, dmarc: bool}  $options
     * @param  callable(string): void  $log
     */
    private function resolveDnsGroups(
        array $groups,
        bool $tracking,
        bool $imap,
        array $options,
        string $dkimSelector,
        string $dkimPublicKey,
        DmsInstallerRemoteCommandTransport $session,
        callable $log,
    ): array {
        $resolver = new DmsCentos9PublicIpResolver($session);
        foreach ($groups as &$group) {
            $group['rows'] = $this->domainRecordStage->resolvePublicAddresses(
                $group['rows'],
                $resolver,
                $log,
            );
            $this->dnsPlanner->records((bool) $group['domain']->is_our, $group['rows'], [
                'tracking' => $tracking,
                'imap' => $imap,
                'spf' => $options['spf'],
                'dkim' => $options['dkim'],
                'dmarc' => $options['dmarc'],
            ], $dkimSelector, $dkimPublicKey);
        }
        unset($group);

        return $groups;
    }

    private function writeDomainRecords(
        array $groups,
        bool $tracking,
        bool $imap,
        array $options,
        string $dkimSelector,
        string $dkimPublicKey,
        callable $log,
    ): void {
        foreach ($groups as $group) {
            $domain = $group['domain'];
            $log('Config Hosts A-AAAA-MX... For '.$domain->name);
            $ips = $group['rows'];
            $records = $this->dnsPlanner->records((bool) $domain->is_our, $ips, [
                'tracking' => $tracking,
                'imap' => $imap,
                'spf' => $options['spf'],
                'dkim' => $options['dkim'],
                'dmarc' => $options['dmarc'],
            ], $dkimSelector, $dkimPublicKey);
            $success = $this->dnsWriter->setHosts($domain, $records);
            $log($success ? 'Done Setting Records' : 'Failed Setting Records');
        }
    }

    /** @param list<array<string, mixed>> $rows @param array<string, SendingDomain> $domains
     * @param  array<string, mixed>  $parameters  @param array{https: bool, spf: bool, dkim: bool, dmarc: bool} $options
     */
    private function persist(
        Workspace $workspace,
        User $actor,
        ManagedServer $server,
        array $rows,
        array $domains,
        array $parameters,
        bool $tracking,
        bool $imap,
        array $options,
        ?string $requestId,
    ): void {
        DB::transaction(function () use ($workspace, $actor, $server, $rows, $domains, $parameters, $tracking, $imap, $options, $requestId): void {
            $oldIds = [];
            foreach ($rows as $row) {
                $domain = $domains[$row['domain_id']];
                $dns = $row['sub'] !== '' ? $row['sub'].'.'.$domain->name : $domain->name;
                ManagedServerIp::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->where('managed_server_id', $server->getKey())
                    ->whereKey($row['ip_id'])
                    ->firstOrFail()
                    ->forceFill(['sending_domain_id' => $domain->getKey(), 'dns' => $dns])
                    ->saveOrFail();

                if ($domain->is_our) {
                    $domain->forceFill(['status' => 'used'])->saveOrFail();
                }
                if ((string) $row['old_domain_id'] !== (string) $row['domain_id']) {
                    $oldIds[(string) $row['old_domain_id']] = (string) $row['old_domain_id'];
                }
            }
            if ($oldIds !== []) {
                $workspace->sendingDomains()->whereIn('id', array_values($oldIds))->update(['status' => 'free']);
            }

            $parameters['data'] = array_map(function (array $row) use ($domains): array {
                $domain = $domains[$row['domain_id']];

                return [...$row, 'domain' => (string) $domain->name, 'is_our' => (bool) $domain->is_our];
            }, $rows);
            $parameters['tracking'] = $tracking;
            $parameters['imap'] = $imap;
            $parameters['spf'] = $options['spf'];
            $parameters['dkim'] = $options['dkim'];
            $parameters['dmarc'] = $options['dmarc'];
            $parameters['https'] = $options['https'];
            $server->forceFill(['install_parameters' => $parameters])->saveOrFail();

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'server.domain_changed',
                'subject_type' => 'managed_server',
                'subject_id' => (string) $server->getKey(),
                'request_id' => $requestId,
                'properties' => ['ip_count' => count($rows), 'domain_count' => count($domains)],
                'occurred_at' => now(),
            ]);
        });
    }

    private function writeVhost(DmsInstallerRemoteCommandTransport&DmsInstallerRemoteFileTransport $session, string $domain, array $subdomains): void
    {
        if (! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $domain)) {
            throw new InvalidArgumentException('The selected main domain is not a valid hostname.');
        }
        $fileName = Str::slug(str_replace('.', '_', $domain), '_');
        $serverAlias = $subdomains === [] ? '' : '    ServerAlias '.implode(' ', $subdomains);
        $content = "<VirtualHost *:80>\n"
            ."    ServerName {$domain}\n"
            .($serverAlias === '' ? '' : $serverAlias."\n")
            ."    DocumentRoot \"/var/mini/public\"\n"
            ."    <Directory /var/mini/public>\n"
            ."        AllowOverride all\n"
            ."        Options Indexes FollowSymLinks ExecCGI\n"
            ."        Require all granted\n"
            ."    </Directory>\n"
            ."    ErrorLog \"logs/{$fileName}-error.log\"\n"
            ."    CustomLog \"logs/{$fileName}-access.log\" common\n"
            ."</VirtualHost>\n";

        if (! $session->upload($content, '/etc/httpd/conf.d/'.$fileName.'.conf')) {
            throw new RuntimeException('The Apache virtual host configuration could not be uploaded.');
        }
        $session->exec('systemctl restart httpd');
    }
}
