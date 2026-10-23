<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;

/**
 * Resolves a DMS-eligible row and performs only the installer's read-only IP-discovery phase.
 */
class DmsBulkInstallRowPreparation
{
    public function __construct(
        private readonly DmsBulkInstallEligibility $eligibility,
        private readonly DmsBulkInstallRemoteIpDiscovery $ipDiscovery,
    ) {}

    /**
     * @return array{server: ManagedServer, domain: SendingDomain, ips: list<string>}
     *
     * @throws HostProbeException
     * @throws ModelNotFoundException
     */
    public function prepare(User $user, Workspace $workspace, string $serverId, string $domainId): array
    {
        if (! $workspace->users()->whereKey($user->getKey())->wherePivotIn('role', ['owner', 'admin'])->exists()) {
            throw (new ModelNotFoundException)->setModel(ManagedServer::class, [$serverId]);
        }

        $server = $this->eligibility->serverForSelection($user, $workspace, $serverId);
        $domain = $this->eligibility->domainForSelection($workspace, $domainId);

        if ($server === null) {
            throw (new ModelNotFoundException)->setModel(ManagedServer::class, [$serverId]);
        }

        if ($domain === null) {
            throw (new ModelNotFoundException)->setModel(SendingDomain::class, [$domainId]);
        }

        if ($server->state !== 'verified' || ! is_string($server->expected_host_fingerprint) || $server->expected_host_fingerprint === '') {
            throw new HostProbeException('Verify the server host before installation.');
        }

        $credential = ServerCredential::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('managed_server_id', $server->getKey())
            ->first();

        if ($credential === null) {
            throw new HostProbeException('The stored SSH credential is missing.');
        }

        try {
            $secret = Crypt::decryptString($credential->encrypted_secret);
        } catch (DecryptException) {
            throw new HostProbeException('The stored SSH credential could not be decrypted.');
        }

        $ips = $this->ipDiscovery->discover($server, $secret, $credential->credential_type);

        return ['server' => $server, 'domain' => $domain, 'ips' => $ips];
    }
}
