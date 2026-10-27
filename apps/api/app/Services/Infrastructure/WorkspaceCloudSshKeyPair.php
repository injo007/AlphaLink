<?php

namespace App\Services\Infrastructure;

use App\Models\Workspace;
use phpseclib3\Crypt\RSA;
use RuntimeException;

/** Keeps the workspace cloud key used to provision and later access key-based cloud instances. */
class WorkspaceCloudSshKeyPair
{
    /** @return array{private_key:string,public_key:string} */
    public function getOrCreate(Workspace $workspace): array
    {
        $settings = $workspace->infrastructureSettings()->firstOrCreate([]);
        $saved = $settings->cloud_ssh_key_pair;
        if (is_array($saved)
            && is_string($saved['private_key'] ?? null)
            && is_string($saved['public_key'] ?? null)
            && $saved['private_key'] !== ''
            && $saved['public_key'] !== '') {
            return $saved;
        }

        try {
            $privateKey = RSA::createKey(2048);
            $pair = [
                'private_key' => $privateKey->toString('PKCS8'),
                'public_key' => $privateKey->getPublicKey()->toString('OpenSSH'),
            ];
        } catch (\Throwable) {
            throw new RuntimeException('AlphaLink could not create the workspace cloud SSH key.');
        }

        $settings->cloud_ssh_key_pair = $pair;
        $settings->save();

        return $pair;
    }
}
