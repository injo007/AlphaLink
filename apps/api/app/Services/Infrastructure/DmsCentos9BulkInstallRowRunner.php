<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Connects prepared rows to the AlmaLinux 9 workflow behind execution and PMTA readiness gates. */
class DmsCentos9BulkInstallRowRunner implements DmsBulkInstallRowRunner
{
    public function __construct(
        private readonly DmsPmtaDeploymentGate $gate,
        private readonly DmsBulkInstallRuntimeInputs $runtimeInputs,
        private readonly DmsInstallerServerCredentialResolver $credentials,
        private readonly DmsInstallerSshSessionFactory $sessions,
        private readonly DmsCentos9InstallWorkflow $workflow,
        private readonly DmsBulkInstallDomainDnsRecordWriter $dnsWriter,
        private readonly SshHostProbe $hostProbe,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, bool|int>  $options
     */
    public function install(array $row, array $options, callable $log): void
    {
        $prepared = $row['prepared'] ?? null;
        if (! is_array($prepared)
            || ! ($prepared['workspace'] ?? null) instanceof Workspace
            || ! ($prepared['server'] ?? null) instanceof ManagedServer
            || ! ($prepared['domain'] ?? null) instanceof SendingDomain
            || ! is_array($prepared['ips'] ?? null)
            || ! is_array($prepared['isps'] ?? null)) {
            throw new InvalidArgumentException('The DMS installation row was not prepared.');
        }

        $selected = [];
        foreach (['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'] as $option) {
            if (! is_bool($options[$option] ?? null)) {
                throw new InvalidArgumentException('The DMS installation options are invalid.');
            }
            $selected[$option] = $options[$option];
        }
        $version = $options['pmta_version'] ?? null;
        if (! in_array($version, [40, 45, 50], true)) {
            throw new InvalidArgumentException('The selected PMTA version is invalid.');
        }

        $server = $prepared['server'];
        $workspace = $prepared['workspace'];
        $domain = $prepared['domain'];
        $domains = $prepared['domains'] ?? [$domain];
        if ((string) $server->workspace_id !== (string) $workspace->getKey()) {
            throw new InvalidArgumentException('The prepared server and domain must belong to the same workspace.');
        }
        foreach ($domains as $selectedDomain) {
            if (! $selectedDomain instanceof SendingDomain
                || (string) $selectedDomain->workspace_id !== (string) $workspace->getKey()) {
                throw new InvalidArgumentException('The prepared server and domain must belong to the same workspace.');
            }
        }
        if ($server->is_installed || collect($domains)->contains(fn (SendingDomain $selectedDomain): bool => $selectedDomain->status === 'used')) {
            throw new RuntimeException('The selected server or domain is no longer eligible for installation.');
        }
        $facts = is_array($server->host_facts) ? $server->host_facts : [];
        $readiness = $this->gate->evaluate($facts, $version, $selected);
        if (! $readiness['installation_authorized']) {
            throw new RuntimeException('PMTA deployment prerequisites are not verified.');
        }

        $inputs = $this->runtimeInputs->resolve($selected, $workspace, $version);
        $credential = $this->credentials->resolve($server);
        $currentHost = $this->hostProbe->inspect($server, $credential['secret'], $credential['type']);
        if (! is_string($server->expected_host_fingerprint)
            || ! hash_equals($server->expected_host_fingerprint, $currentHost['fingerprint'])
            || ! is_bool($currentHost['facts']['pmta_binary_present'] ?? null)
            || (($currentHost['facts']['pmta_binary_present'] ?? null) === true
                && ! in_array($currentHost['facts']['pmta_reviewed_identity'] ?? null, ['4.0', '4.5', '5.0'], true))) {
            throw new HostProbeException('The current host identity or PMTA installed state is not clear for a new installation.');
        }
        $server->host_facts = $currentHost['facts'];
        $currentReadiness = $this->gate->evaluate($currentHost['facts'], $version, $selected);
        if (! $currentReadiness['installation_authorized']) {
            throw new RuntimeException('PMTA deployment prerequisites are not verified against the current host state.');
        }
        $session = $this->sessions->create($server, $credential['secret'], $credential['type']);
        $this->workflow->run(
            $workspace, $server, $domain,
            $prepared['ips'], $prepared['isps'], $version, $selected,
            $inputs['settings'], $inputs['assets'], $session,
            $this->dnsWriter,
            Closure::fromCallable($log),
            $prepared['ip_assignments'] ?? null,
            $domains,
        );
    }
}
