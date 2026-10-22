<?php

namespace App\Services\Infrastructure;

use App\Models\AuditEvent;
use App\Models\InstallationPlan;
use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\ServerProvider;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InfrastructureManager
{
    public function __construct(
        private readonly DomainTxtResolver $dns,
        private readonly PmtaCatalog $catalog,
        private readonly SshHostProbe $hostProbe,
    ) {}

    /** @return array{added:int, restored:int, servers:Collection<int, ManagedServer>} */
    public function registerServers(Workspace $workspace, User $actor, array $data, ?string $requestId = null): array
    {
        $provider = ServerProvider::query()->findOrFail($data['servers_provider_id']);
        $credentialType = $data['login_type'];
        $credential = $data['password'];
        $name = strtoupper($data['name']);
        $result = ['added' => 0, 'restored' => 0, 'servers' => collect()];
        $newRows = [];

        // DMS restores deleted rows as it walks the IPs, then performs one bulk insert for new rows.
        // Keep that ordering: a later new-row failure does not roll back an earlier restore.
        foreach ($data['ips'] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $ip = $candidate;
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $deleted = ManagedServer::onlyTrashed()
                ->where('workspace_id', $workspace->getKey())
                ->where('ip_address', $ip)
                ->first();

            $attributes = [
                'name' => $name,
                'provider' => $provider->name,
                'servers_provider_id' => $provider->getKey(),
                'ip_address' => $ip,
                'ssh_port' => (int) $data['port'],
                'ssh_username' => $data['username'],
                'login_type' => $credentialType,
                'expire_at' => $data['expire_at'],
            ];

            if ($deleted) {
                $server = DB::transaction(function () use ($workspace, $actor, $deleted, $attributes, $credentialType, $credential, $requestId, $ip) {
                    $server = ManagedServer::onlyTrashed()
                        ->where('workspace_id', $workspace->getKey())
                        ->whereKey($deleted->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $server->fill($attributes);
                    $server->restore();
                    $this->storeCredential($server, $workspace, $actor, $credentialType, $credential);
                    $this->audit($workspace, $actor, 'server.restored', 'managed_server', $server->getKey(), $requestId, [
                        'server_provider_id' => $attributes['servers_provider_id'],
                        'ip_address' => $ip,
                    ]);

                    return $server;
                });
                $result['restored']++;
                $result['servers']->push($server);

                continue;
            }

            if ($workspace->managedServers()->where('ip_address', $ip)->exists()) {
                continue;
            }

            $newRows[] = [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                ...$attributes,
                'state' => 'pending_preflight',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => $this->digest([
                    ...$attributes,
                    'credential_proof' => hash_hmac('sha256', $credential, config('app.key')),
                ]),
                'created_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($newRows !== []) {
            DB::transaction(function () use ($newRows, $workspace, $actor, $credentialType, $credential, $requestId, &$result): void {
                DB::table('managed_servers')->insertOrIgnore($newRows);
                foreach ($newRows as $row) {
                    $server = ManagedServer::query()->find($row['id']);
                    if (! $server) {
                        continue;
                    }

                    $this->storeCredential($server, $workspace, $actor, $credentialType, $credential);
                    $this->audit($workspace, $actor, 'server.registered', 'managed_server', $server->getKey(), $requestId, [
                        'server_provider_id' => $row['servers_provider_id'],
                        'state' => $server->state,
                    ]);
                    $result['added']++;
                    $result['servers']->push($server);
                }
            });
        }

        return $result;
    }

    /** @param array<int, string> $ips */
    public function testServerConnections(array $ips, int $port, string $username, string $loginType, string $credential): array
    {
        return collect($ips)->map(function ($ip) use ($port, $username, $loginType, $credential): array {
            $ip = is_string($ip) ? $ip : '';

            return $this->hostProbe->testConnection($ip, $port, $username, $loginType, $credential);
        })->all();
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

    public function verifyServerHost(Workspace $workspace, User $actor, ManagedServer $server, ?string $requestId = null): ManagedServer
    {
        abort_unless($server->workspace_id === $workspace->getKey(), 404);

        $credential = ServerCredential::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->firstOrFail();
        $result = $this->hostProbe->inspect($server, Crypt::decryptString($credential->encrypted_secret), $credential->credential_type);

        DB::transaction(function () use ($workspace, $actor, $server, $result, $requestId): void {
            $server->update([
                'expected_host_fingerprint' => $result['fingerprint'],
                'observed_host_fingerprint' => $result['fingerprint'],
                'host_facts' => $result['facts'],
                'last_probe_at' => now(),
                'state' => 'verified',
            ]);
            $this->audit($workspace, $actor, 'server.preflight_verified', 'managed_server', $server->getKey(), $requestId, [
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
            $blockers[] = 'SSH access and read-only OS preflight have not been verified.';
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

    private function storeCredential(ManagedServer $server, Workspace $workspace, User $actor, string $credentialType, string $credential): void
    {
        $record = ServerCredential::query()->firstOrNew(['managed_server_id' => $server->getKey()]);
        $record->fill([
            'workspace_id' => $workspace->getKey(),
            'credential_type' => $credentialType,
            'encrypted_secret' => Crypt::encryptString($credential),
            'created_by' => $actor->getKey(),
            'rotated_at' => now(),
        ])->save();
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
