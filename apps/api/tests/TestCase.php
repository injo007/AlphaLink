<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most feature tests build URLs from the original workspace-scoped route
     * names. Exercise their ID-free browser destination directly; the legacy
     * redirect itself is covered explicitly by CleanWorkspaceEntryRoutesTest.
     */
    public function get($uri, array $headers = []): TestResponse
    {
        Auth::shouldUse('web');

        if (is_string($uri) && ($legacy = $this->legacyWorkspaceUrlParts($uri)) !== null) {
            [$cleanUri, $workspaceId] = $legacy;
            $user = Auth::guard('web')->user();
            if ($user !== null && $user->workspaces()->whereKey($workspaceId)->exists()) {
                $this->withSession(['active_workspace_id' => $workspaceId]);
            } else {
                return parent::get($uri, $headers);
            }
            $uri = $cleanUri;
        }

        return parent::get($uri, $headers);
    }

    protected function getLegacyWorkspacePage(string $uri, array $headers = []): TestResponse
    {
        return parent::get($uri, $headers);
    }

    protected function cleanWorkspaceUrl(string $uri): string
    {
        return $this->legacyWorkspaceUrlParts($uri)[0] ?? $uri;
    }

    /** @return array{string, string}|null */
    private function legacyWorkspaceUrlParts(string $uri): ?array
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if (! preg_match('~^/app/workspaces/([0-9a-f-]{36})(?:/(.*))?$~i', $path, $matches)) {
            return null;
        }

        $workspaceId = $matches[1];
        $suffix = trim($matches[2] ?? '', '/');
        $cleanPath = match ($suffix) {
            '', 'analytics' => '/dashboard/main',
            'campaigns' => '/mailer/campaigns',
            default => str_starts_with($suffix, 'campaigns/')
                ? '/mailer/campaigns/'.substr($suffix, strlen('campaigns/'))
                : '/'.$suffix,
        };
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return [$origin.$cleanPath.$query.$fragment, $workspaceId];
    }
}
