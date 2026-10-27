<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\ServerCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Resolves a saved workspace credential only for short-lived installer authentication. */
class DmsInstallerServerCredentialResolver
{
    /**
     * @return array{secret: string, type: 'password'|'ssh_key'}
     *
     * @throws HostProbeException
     */
    public function resolve(ManagedServer $server): array
    {
        if (! $server->exists || ! $server->workspace_id) {
            throw new HostProbeException('The stored SSH credential is missing.');
        }

        $credential = ServerCredential::query()
            ->where('workspace_id', $server->workspace_id)
            ->where('managed_server_id', $server->getKey())
            ->first();

        if ($credential === null) {
            throw new HostProbeException('The stored SSH credential is missing.');
        }

        if (! in_array($credential->credential_type, ['password', 'ssh_key'], true)) {
            throw new HostProbeException('The stored SSH credential type is unsupported.');
        }

        try {
            $secret = Crypt::decryptString($credential->encrypted_secret);
        } catch (DecryptException) {
            throw new HostProbeException('The stored SSH credential could not be decrypted.');
        }

        if ($secret === '') {
            throw new HostProbeException('The stored SSH credential is empty.');
        }

        return ['secret' => $secret, 'type' => $credential->credential_type];
    }
}
