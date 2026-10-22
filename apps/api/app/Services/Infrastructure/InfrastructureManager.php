<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\InstallationPlan;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InfrastructureManager
{
    public function __construct(
        private readonly DomainTxtResolver $dns,
        private readonly PmtaCatalog $catalog,
        private readonly SshHostProbe $hostProbe,
    ) {}

    /** @return Collection<int, ManagedServer> */
    public function registerServers(Workspace $workspace, User $actor, array $data, string $key, ?string $requestId = null): Collection
    {
        $ips = $data['ip_addresses'];
        if (count($ips) !== count(array_unique($ips))) {
            throw ValidationException::withMessages(['ip_addresses' => 'Each server IP must be unique.']);
        }

        // A batch is all-or-nothing, including idempotent replay and duplicate-IP checks.
        return DB::transaction(function () use ($workspace, $actor, $data, $key, $requestId, $ips): Collection {
            return collect($ips)->map(function (string $ip, int $index) use ($workspace, $actor, $data, $key, $requestId): ManagedServer {
                $item = $data;
                unset($item['ip_addresses']);
                $item['ip_address'] = $ip;
                $itemKey = hash('sha256', $key.'|'.$index);

                return $this->registerServer($workspace, $actor, $item, $itemKey, $requestId);
            });
        });
    }

    public function registerServer(Workspace $workspace, User $actor, array $data, string $key, ?string $requestId = null): ManagedServer
    {
        $credentialType = isset($data['ssh_private_key']) ? 'private_key' : 'password';
        $credential = $data['ssh_private_key'] ?? $data['root_password'];
        $ip = trim($data['ip_address']);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw ValidationException::withMessages(['ip_address' => 'Enter a public server IP address.']);
        }

        $payload = [
            'name' => trim($data['name']),
            'provider' => trim($data['provider']),
            'ip_address' => $ip,
            'ssh_port' => (int) ($data['ssh_port'] ?? 22),
            'ssh_username' => trim($data['ssh_username'] ?? 'root'),
            'expected_host_fingerprint' => $data['expected_host_fingerprint'] ?? null,
            'credential_proof' => hash_hmac('sha256', $credential, config('app.key')),
            'credential_type' => $credentialType,
        ];
        $fingerprint = $this->digest($payload);
        $prior = $workspace->managedServers()->where('idempotency_key', $key)->first();
        if ($prior) {
            abort_unless(hash_equals($prior->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for another server request.');

            return $prior;
        }

        if ($workspace->managedServers()->where('ip_address', $ip)->exists()) {
            throw ValidationException::withMessages(['ip_address' => 'This IP is already registered in this workspace.']);
        }

        return DB::transaction(function () use ($workspace, $actor, $data, $key, $requestId, $payload, $fingerprint, $credentialType, $credential): ManagedServer {
            $server = $workspace->managedServers()->create([
                ...array_diff_key($payload, ['credential_proof' => true, 'credential_type' => true]),
                'state' => 'pending_host_verification',
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(),
            ]);

            ServerCredential::query()->create([
                'workspace_id' => $workspace->getKey(),
                'managed_server_id' => $server->getKey(),
                'credential_type' => $credentialType,
                'encrypted_secret' => Crypt::encryptString($credential),
                'created_by' => $actor->getKey(),
                'rotated_at' => now(),
            ]);

            $this->audit($workspace, $actor, 'server.registered', 'managed_server', $server->getKey(), $requestId, [
                'provider' => $server->provider, 'state' => $server->state,
            ]);

            return $server;
        });
    }

    public function registerDomain(Workspace $workspace, User $actor, string $name, ?string $requestId = null): SendingDomain
    {
        $name = mb_strtolower(rtrim(trim($name), '.'));
        if (strlen($name) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $name)) {
            throw ValidationException::withMessages(['name' => 'Enter a valid domain name.']);
        }

        if ($workspace->sendingDomains()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'This domain is already registered in this workspace.']);
        }

        return DB::transaction(function () use ($workspace, $actor, $name, $requestId): SendingDomain {
            $domain = $workspace->sendingDomains()->create([
                'name' => $name,
                'state' => 'pending',
                'verification_token' => 'alphalink-verification='.bin2hex(random_bytes(24)),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit($workspace, $actor, 'domain.registered', 'sending_domain', $domain->getKey(), $requestId);

            return $domain;
        });
    }

    public function discoverServerFingerprint(Workspace $workspace, User $actor, ManagedServer $server, ?string $requestId = null): string
    {
        abort_unless($server->workspace_id === $workspace->getKey(), 404);
        $fingerprint = $this->hostProbe->fingerprint($server);
        if ($server->state === 'verified' && ! hash_equals((string) $server->expected_host_fingerprint, $fingerprint)) {
            throw new HostProbeException('SSH host fingerprint changed. Stop and confirm the host identity with your provider.');
        }

        DB::transaction(function () use ($workspace, $actor, $server, $fingerprint, $requestId): void {
            $server->update(['observed_host_fingerprint' => $fingerprint, 'last_probe_at' => now()]);
            $this->audit($workspace, $actor, 'server.host_key_observed', 'managed_server', $server->getKey(), $requestId, [
                'fingerprint' => $fingerprint,
            ]);
        });

        return $fingerprint;
    }

    public function verifyServerHost(Workspace $workspace, User $actor, ManagedServer $server, string $expectedFingerprint, ?string $requestId = null): ManagedServer
    {
        abort_unless($server->workspace_id === $workspace->getKey(), 404);
        abort_unless($server->observed_host_fingerprint, 409, 'Read the SSH host key before preflight.');
        if (! hash_equals($server->observed_host_fingerprint, $expectedFingerprint)) {
            throw new HostProbeException('The provider fingerprint does not match the observed SSH host key.');
        }

        $credential = ServerCredential::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->firstOrFail();
        $result = $this->hostProbe->inspect($server, Crypt::decryptString($credential->encrypted_secret), $expectedFingerprint, $credential->credential_type);

        DB::transaction(function () use ($workspace, $actor, $server, $result, $expectedFingerprint, $requestId): void {
            $server->update([
                'expected_host_fingerprint' => $expectedFingerprint,
                'observed_host_fingerprint' => $result['fingerprint'],
                'host_facts' => $result['facts'],
                'last_probe_at' => now(),
                'state' => 'verified',
            ]);
            $this->audit($workspace, $actor, 'server.preflight_verified', 'managed_server', $server->getKey(), $requestId, [
                'fingerprint' => $expectedFingerprint,
                'os_id' => $result['facts']['os_id'],
                'os_version' => $result['facts']['os_version'],
                'architecture' => $result['facts']['architecture'],
            ]);
        });

        return $server;
    }

    public function verifyDomain(Workspace $workspace, SendingDomain $domain, User $actor, ?string $requestId = null): bool
    {
        abort_unless($domain->workspace_id === $workspace->getKey(), 404);
        $verified = in_array($domain->verification_token, $this->dns->records('_alphalink-verify.'.$domain->name), true);
        if (! $verified || $domain->state === 'verified') {
            return $verified;
        }

        DB::transaction(function () use ($workspace, $domain, $actor, $requestId): void {
            $domain->update(['state' => 'verified', 'verified_at' => now()]);
            $this->audit($workspace, $actor, 'domain.verified', 'sending_domain', $domain->getKey(), $requestId);
        });

        return true;
    }

    public function createPlan(Workspace $workspace, User $actor, ManagedServer $server, SendingDomain $domain, string $version, array $options, ?string $requestId = null): InstallationPlan
    {
        abort_unless($server->workspace_id === $workspace->getKey() && $domain->workspace_id === $workspace->getKey(), 404);
        abort_unless(array_key_exists($version, $this->catalog->versions()), 422, 'Unknown PMTA version.');
        abort_unless($domain->state === 'verified', 422, 'Verify domain ownership before planning installation.');

        $options = $options + array_fill_keys(['tracking', 'dkim', 'dmarc', 'proxy', 'spf'], false);
        foreach ($options as $key => $value) {
            abort_unless(in_array($key, ['tracking', 'dkim', 'dmarc', 'proxy', 'spf'], true) && is_bool($value), 422, 'Invalid installation option.');
        }

        $package = $this->catalog->versions()[$version];
        $blockers = [];
        if ($server->state !== 'verified') {
            $blockers[] = 'SSH host identity and read-only OS preflight have not been verified.';
        }
        if (! $package['installable']) {
            $blockers[] = $package['reason'];
        }
        if ($options['proxy']) {
            $blockers[] = 'Choose and validate a specific proxy type and topology before installation.';
        }
        $blockers[] = 'Remote installer, backup, rollback and health verification are not enabled in this release.';

        $preview = [
            'server' => ['id' => $server->id, 'name' => $server->name, 'ip_address' => $server->ip_address, 'provider' => $server->provider],
            'domain' => ['id' => $domain->id, 'name' => $domain->name],
            'pmta' => ['version' => $version, 'label' => $package['label'], 'mandatory' => true, 'artifact_status' => $package['artifact_status']],
            'os_candidate' => 'AlmaLinux 9 x86_64; exact PMTA build compatibility pending',
            'options' => $options,
            'dns' => $this->dnsPreview($domain, $options),
            'blockers' => $blockers,
            'execution_enabled' => false,
        ];
        $digest = $this->digest($preview);

        return DB::transaction(function () use ($workspace, $actor, $server, $domain, $version, $options, $preview, $digest, $requestId): InstallationPlan {
            $plan = $workspace->installationPlans()->create([
                'managed_server_id' => $server->getKey(),
                'sending_domain_id' => $domain->getKey(),
                'pmta_version' => $version,
                'options' => $options,
                'preview' => $preview,
                'preview_sha256' => $digest,
                'state' => 'blocked',
                'created_by' => $actor->getKey(),
                'created_at' => now(),
            ]);

            $this->audit($workspace, $actor, 'installation.plan_created', 'installation_plan', $plan->getKey(), $requestId, [
                'preview_sha256' => $digest, 'state' => 'blocked',
            ]);

            return $plan;
        });
    }

    private function dnsPreview(SendingDomain $domain, array $options): array
    {
        $records = [];
        if ($options['spf']) {
            $records[] = ['type' => 'TXT', 'name' => $domain->name, 'action' => 'Merge sending IP into the existing single SPF record; inspect current TXT first.'];
        }
        if ($options['dkim']) {
            $records[] = ['type' => 'TXT', 'name' => '<selector>._domainkey.'.$domain->name, 'action' => 'Generate a protected private key and publish its public key.'];
        }
        if ($options['dmarc']) {
            $records[] = ['type' => 'TXT', 'name' => '_dmarc.'.$domain->name, 'action' => 'Review alignment and publish an initial monitoring policy.'];
        }
        if ($options['tracking']) {
            $records[] = ['type' => 'CNAME or A', 'name' => 'track.'.$domain->name, 'action' => 'Deploy tracking endpoint and verify HTTPS certificate before use.'];
        }

        return $records;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function audit(Workspace $workspace, User $actor, string $action, string $type, string $id, ?string $requestId, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'request_id' => $requestId,
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }
}
