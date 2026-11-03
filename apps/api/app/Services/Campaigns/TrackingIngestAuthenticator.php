<?php

namespace App\Services\Campaigns;

use App\Models\Workspace;

class TrackingIngestAuthenticator
{
    public function expectedKey(Workspace $workspace): ?string
    {
        $workspaceKey = $workspace->infrastructureSettings?->mini_app_key;
        if (is_string($workspaceKey) && $workspaceKey !== '') {
            return $workspaceKey;
        }

        foreach ([config('tracking.ingest_key'), config('infrastructure.bulk_install_settings.mini_app_key')] as $key) {
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return null;
    }

    public function assertValid(Workspace $workspace, string $candidate): void
    {
        $savedSettings = $workspace->infrastructureSettings;
        $workspaceKey = $savedSettings?->mini_app_key;
        $fallbackInstallKey = config('infrastructure.bulk_install_settings.mini_app_key');
        $keys = is_string($workspaceKey) && $workspaceKey !== ''
            ? [$workspaceKey]
            : [config('tracking.ingest_key'), $workspaceKey ?? $fallbackInstallKey];
        $availableKeys = [];

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '' && ! in_array($key, $availableKeys, true)) {
                $availableKeys[] = $key;
            }
        }

        abort_if($availableKeys === [], 503, 'Campaign tracking is not configured.');

        foreach ($availableKeys as $key) {
            if (hash_equals($key, $candidate)) {
                return;
            }
        }

        abort(403, 'Invalid tracking key.');
    }
}
