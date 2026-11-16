<?php

namespace App\Services\Infrastructure;

use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use RuntimeException;

class DkimKeyMaterialManager
{
    /**
     * @return array{selector: string, private_key: string, public_record: string}
     */
    public function ensureForWorkspace(Workspace $workspace, ?string $preferredSelector = null, ?string $legacyPrivateKey = null): array
    {
        return DB::transaction(function () use ($workspace, $preferredSelector, $legacyPrivateKey): array {
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $settings = WorkspaceInfrastructureSettings::query()->firstOrCreate([
                'workspace_id' => $workspace->getKey(),
            ]);

            $privateKey = $settings->dkim_private_key;
            if (is_string($privateKey) && $privateKey !== '') {
                $this->parsePrivateKey($privateKey);
            } elseif (is_string($legacyPrivateKey) && $legacyPrivateKey !== '') {
                $this->parsePrivateKey($legacyPrivateKey);
                $privateKey = $legacyPrivateKey;
                $settings->dkim_private_key = $privateKey;
            } else {
                $privateKey = $this->generatePrivateKey();
                $settings->dkim_private_key = $privateKey;
            }

            $selector = is_string($settings->dkim_key) && trim($settings->dkim_key) !== ''
                ? trim($settings->dkim_key)
                : (is_string($preferredSelector) && trim($preferredSelector) !== ''
                    ? trim($preferredSelector)
                    : 'al'.bin2hex(random_bytes(8)));

            if ($settings->dkim_key !== $selector) {
                $settings->dkim_key = $selector;
            }
            if ($settings->isDirty()) {
                $settings->save();
            }

            return [
                'selector' => $selector,
                'private_key' => $privateKey,
                'public_record' => $this->publicRecord($privateKey),
            ];
        });
    }

    public function generatePrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('A workspace DKIM key could not be generated.');
        }

        $privateKey = '';
        if (! openssl_pkey_export($key, $privateKey) || $privateKey === '') {
            throw new RuntimeException('A workspace DKIM private key could not be exported.');
        }

        return $privateKey;
    }

    public function publicRecord(string $privateKey): string
    {
        $key = $this->parsePrivateKey($privateKey);
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || empty($details['key'])) {
            throw new RuntimeException('The workspace DKIM key is not a usable RSA key.');
        }

        $pem = $details['key'];
        $cleanBase64 = preg_replace('/-----[^-]+-----/', '', $pem);
        $cleanBase64 = str_replace(["\r", "\n", ' '], '', (string) $cleanBase64);

        return 'v=DKIM1; k=rsa; p='.$cleanBase64;
    }

    private function parsePrivateKey(string $privateKey): OpenSSLAsymmetricKey
    {
        $parsed = openssl_pkey_get_private($privateKey);
        if (! $parsed instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('The workspace DKIM private key is invalid.');
        }

        return $parsed;
    }
}
