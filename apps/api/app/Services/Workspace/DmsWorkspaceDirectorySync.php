<?php

namespace App\Services\Workspace;

use App\Models\WorkspaceGoogleAccount;
use App\Models\WorkspaceGoogleProject;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only Google Admin Directory sync used by DMS Workspace projects. */
class DmsWorkspaceDirectorySync
{
    private const SCOPE = 'https://www.googleapis.com/auth/admin.directory.user';

    public function sync(WorkspaceGoogleProject $project): int
    {
        $config = json_decode($project->service_account_json, true);
        if (! is_array($config) || ! filter_var($config['client_email'] ?? null, FILTER_VALIDATE_EMAIL)
            || ! is_string($config['private_key'] ?? null) || ! str_contains($config['private_key'], 'PRIVATE KEY')) {
            throw new RuntimeException('Invalid service account JSON.');
        }

        $tokenUri = $config['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        if ($tokenUri !== 'https://oauth2.googleapis.com/token') {
            throw new RuntimeException('The service account token URL must be https://oauth2.googleapis.com/token.');
        }
        $accessToken = $this->accessToken((string) $config['client_email'], (string) $config['private_key'], $project->name, $tokenUri);

        $emails = [];
        $pageToken = null;
        do {
            $query = ['customer' => 'my_customer', 'maxResults' => 500];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = Http::withToken($accessToken)->timeout(30)->get('https://admin.googleapis.com/admin/directory/v1/users', $query);
            if (! $response->successful()) {
                throw new RuntimeException('Google Admin Directory rejected the account sync (HTTP '.$response->status().').');
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Google Admin Directory returned an invalid account response.');
            }
            foreach ($payload['users'] ?? [] as $user) {
                $email = strtolower(trim((string) ($user['primaryEmail'] ?? '')));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = true;
                }
            }
            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null && $pageToken !== '');

        $emails = array_keys($emails);
        if ($emails === []) {
            $project->accounts()->delete();
        } else {
            $project->accounts()->whereNotIn('email', $emails)->delete();
            foreach ($emails as $email) {
                WorkspaceGoogleAccount::query()->firstOrCreate([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'email' => $email,
                ], ['is_active' => true]);
            }
        }

        $project->forceFill(['synced_at' => now(), 'sync_error' => null])->save();

        return count($emails);
    }

    private function accessToken(string $clientEmail, string $privateKey, string $subject, string $tokenUri): string
    {
        $issuedAt = time();
        $assertion = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$this->base64Url(json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
                'sub' => $subject,
            ], JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign($assertion, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account private key could not sign the Google access token.');
        }
        $jwt = $assertion.'.'.$this->base64Url($signature);
        $response = Http::asForm()->timeout(30)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google OAuth did not return an access token (HTTP '.$response->status().').');
        }

        return $token;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
