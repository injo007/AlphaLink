<?php

namespace App\Services\Infrastructure;

class SshHostKeyFingerprint
{
    public function fromPublicKey(string|false $publicKey): string
    {
        if (! is_string($publicKey)) {
            throw new HostProbeException('The server host key signature could not be verified.');
        }

        $parts = preg_split('/\s+/', trim($publicKey), 3);
        $keyBlob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if (! is_string($keyBlob) || $keyBlob === '') {
            throw new HostProbeException('The server host key could not be validated.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBlob, true)), '=');
    }
}
