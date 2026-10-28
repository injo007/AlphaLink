<?php

namespace App\Services\Audiences;

class AudienceMembershipFilterIndexer
{
    public function isReady(): bool
    {
        $key = config('alphalink.contact_lookup_key');
        $version = $this->version();

        return is_string($key) && strlen($key) >= 32 && $version > 0 && $version <= 32767;
    }

    public function version(): int
    {
        return (int) config('alphalink.contact_lookup_key_version', 1);
    }

    /** @return array{profile_sex_index: string, email_domain_index: string, attribute_index_version: int} */
    public function values(int|string $workspaceId, int|string $listId, ?array $profile, string $email): array
    {
        return [
            'profile_sex_index' => $this->sexIndex($workspaceId, $listId, $profile),
            'email_domain_index' => $this->domainIndex($workspaceId, $listId, $email),
            'attribute_index_version' => $this->version(),
        ];
    }

    public function sexIndex(int|string $workspaceId, int|string $listId, ?array $profile): string
    {
        $value = strtolower((string) ($profile['sex'] ?? $profile['gender'] ?? ''));

        return $this->index($workspaceId, $listId, 'sex', $value);
    }

    public function domainIndex(int|string $workspaceId, int|string $listId, string $email): string
    {
        $value = strtolower((string) str($email)->afterLast('@'));

        return $this->index($workspaceId, $listId, 'email-domain', $value);
    }

    private function index(int|string $workspaceId, int|string $listId, string $attribute, string $value): string
    {
        $key = config('alphalink.contact_lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('The contact lookup key is not configured for audience filter indexing.');
        }

        $version = $this->version();
        if ($version < 1 || $version > 32767) {
            throw new \RuntimeException('The audience filter index version is invalid.');
        }

        $payload = implode("\0", [
            'audience-mix-filter',
            (string) $version,
            (string) $workspaceId,
            (string) $listId,
            $attribute,
            $value,
        ]);

        return substr(hash_hmac('sha256', $payload, $key), 0, 32);
    }
}
