<?php

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccessRolePolicy
{
    public function isFullAccess(?string $role): bool
    {
        return in_array($role, [User::ACCESS_ROLE_OWNER, User::ACCESS_ROLE_ADMIN], true);
    }

    public function canAccessWebRoute(User|string $userOrRole, string $routeName, string $method): bool
    {
        $user = $userOrRole instanceof User ? $userOrRole : null;
        $role = $user?->access_role ?? $userOrRole;
        if ($this->isFullAccess($role)) {
            return true;
        }

        $routeName = preg_replace('/^clean\./', '', $routeName) ?? $routeName;
        if ($this->matchesAny($routeName, ['console.workspace-communications.*'])) {
            return $role === User::ACCESS_ROLE_OPERATOR;
        }

        if ($user instanceof User && $user->roles !== null) {
            $permissionGroup = $this->webRoutePermissionGroup($routeName);
            if ($permissionGroup === null) {
                return false;
            }

            $method = strtoupper($method);
            $isReadOnlyQuery = $routeName === 'console.infrastructure.list-history.data' && $method === 'POST';
            $safeMethod = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $requiresManage = $safeMethod && $this->webReadRequiresManage($routeName);
            $permission = ($isReadOnlyQuery || ($safeMethod && ! $requiresManage))
                ? $permissionGroup.'.view'
                : $permissionGroup.'.manage';

            return $user->hasPermission($permission);
        }

        return $this->canAccessDefaultRoleRoute((string) $role, $routeName, strtoupper($method));
    }

    public function canAccessCustomApiRequest(User $user, Request $request): bool
    {
        if ($this->isFullAccess($user->access_role) || $user->roles === null) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        $method = strtoupper($request->method());
        $permissionGroup = $this->apiRoutePermissionGroup($path, $method);
        if ($permissionGroup === null) {
            return false;
        }

        $safeMethod = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        $requiresManage = $safeMethod && preg_match('#^/api/v1/workspaces/[^/]+/campaigns/[^/]+/?$#', $path) === 1;
        $permission = $safeMethod && ! $requiresManage
            ? $permissionGroup.'.view'
            : $permissionGroup.'.manage';

        return $user->hasPermission($permission);
    }

    /** @param array<string, mixed> $items
     *  @return array<string, mixed>
     */
    public function filterNavigation(array $items, User $user, Router $router): array
    {
        $filtered = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $children = isset($item['items']) && is_array($item['items'])
                ? $this->filterNavigation($item['items'], $user, $router)
                : [];
            $hrefAllowed = isset($item['href']) && is_string($item['href'])
                ? $this->navigationDestinationAllowed($item['href'], $user, $router)
                : $this->isFullAccess($user->access_role);

            if (isset($item['href']) && ! $hrefAllowed && $children !== []) {
                unset($item['href']);
            }

            if ($children !== []) {
                $item['items'] = $children;
                $filtered[] = $item;
                continue;
            }

            if ($hrefAllowed || ($this->isFullAccess($user->access_role) && ($item['disabled'] ?? false))) {
                $filtered[] = $item;
            }
        }

        return array_values($filtered);
    }

    private function navigationDestinationAllowed(string $href, User $user, Router $router): bool
    {
        try {
            $route = $router->getRoutes()->match(Request::create($href, 'GET'));
        } catch (NotFoundHttpException) {
            return false;
        }

        $name = $route->getName();

        return is_string($name) && $this->canAccessWebRoute($user, $name, 'GET');
    }

    private function canAccessDefaultRoleRoute(string $role, string $routeName, string $method): bool
    {
        if ($role === User::ACCESS_ROLE_OPERATOR) {
            return $this->matchesAny($routeName, [
                'console.index',
                'console.dashboard.main',
                'console.dashboard.legacy-null',
                'console.workspace.dashboard.main',
                'console.analytics.*',
                'console.campaigns.*',
                'console.mailer.campaigns',
                'console.mailer.campaign-logs',
                'console.campaign-logs.*',
                'console.campaign-headers.*',
                'console.campaign-statistics.*',
                'console.warmups.*',
                'console.warmups.seeds.*',
                'console.drops.*',
                'console.audience-mixes.*',
                'console.audiences.*',
                'console.infrastructure.list-history.*',
                'console.campaign-negatives.*',
                'console.global-tests.*',
            ]);
        }

        if ($role === User::ACCESS_ROLE_VIEWER) {
            $isReadOnlyListHistoryQuery = $routeName === 'console.infrastructure.list-history.data'
                && $method === 'POST';
            if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && ! $isReadOnlyListHistoryQuery) {
                return false;
            }

            return $this->matchesAny($routeName, [
                'console.index',
                'console.dashboard.main',
                'console.dashboard.legacy-null',
                'console.workspace.dashboard.main',
                'console.analytics.*',
                'console.campaigns.index',
                'console.mailer.campaigns',
                'console.mailer.campaign-logs',
                'console.campaign-logs.index',
                'console.campaign-statistics.index',
                'console.warmups.index',
                'console.warmups.statistics',
                'console.warmups.seeds.index',
                'console.drops.monitoring',
                'console.drops.statistics',
                'console.audience-mixes.history',
                'console.audiences.index',
                'console.audiences.show',
                'console.infrastructure.list-history.*',
            ]);
        }

        if ($role === User::ACCESS_ROLE_PRIVACY_OFFICER) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                return $this->matchesAny($routeName, [
                    'console.index',
                    'console.dashboard.main',
                    'console.dashboard.legacy-null',
                    'console.workspace.dashboard.main',
                    'console.analytics.index',
                    'console.miscellaneous.contacts',
                    'console.miscellaneous.subscribers',
                    'console.infrastructure.emails.*',
                    'console.infrastructure.list-history.*',
                    'console.audience-mixes.history',
                    'console.audiences.index',
                    'console.audiences.show',
                    'console.campaign-negatives.index',
                    'console.campaign-negatives.create',
                    'console.campaign-negatives.download',
                    'console.drops.suppression',
                    'console.drops.suppression.catalog',
                    'console.drops.suppression.monitor',
                ]);
            }

            return $this->matchesAny($routeName, [
                'console.infrastructure.emails.search',
                'console.infrastructure.emails.action',
                'console.campaign-negatives.create',
                'console.campaign-negatives.store',
                'console.campaign-negatives.destroy',
                'console.drops.suppression.start',
                'console.drops.suppression.kill',
            ]);
        }

        return false;
    }

    private function webRoutePermissionGroup(string $routeName): ?string
    {
        if ($this->matchesAny($routeName, ['console.index', 'console.dashboard.*', 'console.workspace.dashboard.*', 'console.workspace'])) {
            return 'dashboard';
        }
        if ($this->matchesAny($routeName, ['console.analytics.*'])) {
            return 'analytics';
        }
        if ($this->matchesAny($routeName, ['console.networks.offers.*'])) {
            return 'offers';
        }
        if ($this->matchesAny($routeName, ['console.campaign-statistics.*'])) {
            return 'campaign_statistics';
        }
        if ($this->matchesAny($routeName, ['console.campaign-logs.*', 'console.mailer.campaign-logs'])) {
            return 'campaign_logs';
        }
        if ($this->matchesAny($routeName, ['console.campaign-headers.*'])) {
            return 'campaign_headers';
        }
        if ($this->matchesAny($routeName, ['console.global-tests.*'])) {
            return 'global_tests';
        }
        if ($this->matchesAny($routeName, ['console.campaign-test-addresses.*'])) {
            return 'global_tests';
        }
        if ($this->matchesAny($routeName, ['console.campaign-tools.*'])) {
            return 'campaigns';
        }
        if ($this->matchesAny($routeName, ['console.campaigns.*', 'console.mailer.campaigns'])) {
            return 'campaigns';
        }
        if ($this->matchesAny($routeName, ['console.warmups.*'])) {
            return 'warmups';
        }
        if ($this->matchesAny($routeName, ['console.campaign-negatives.*', 'console.drops.suppression*'])) {
            return 'suppressions';
        }
        if ($this->matchesAny($routeName, ['console.drops.*'])) {
            return 'drops';
        }
        if ($this->matchesAny($routeName, ['console.infrastructure.list-history.*'])) {
            return 'list_history';
        }
        if ($this->matchesAny($routeName, [
            'console.audiences.*',
            'console.audience-mixes.*',
            'console.infrastructure.lists.*',
            'console.infrastructure.isps.lists',
        ])) {
            return 'audiences';
        }
        if ($this->matchesAny($routeName, ['console.miscellaneous.contacts', 'console.miscellaneous.subscribers', 'console.infrastructure.emails.*'])) {
            return 'privacy_data';
        }
        if ($this->matchesAny($routeName, ['console.infrastructure.isps.*'])) {
            return 'isp_geo';
        }
        if ($this->matchesAny($routeName, ['console.infrastructure.domains.*'])) {
            return 'domains';
        }
        if ($this->matchesAny($routeName, ['console.infrastructure.blacklist-checker*'])) {
            return 'tools';
        }
        if ($this->matchesAny($routeName, [
            'console.infrastructure.server-providers.*',
            'console.infrastructure.domain-providers.*',
            'console.infrastructure.cloud-providers.*',
            'console.clouds.*',
            'console.networks.*',
            'console.sponsors.*',
        ])) {
            return 'provider_network';
        }
        if ($this->matchesAny($routeName, ['console.offers.*'])) {
            return 'offers';
        }
        if ($this->matchesAny($routeName, [
            'console.infrastructure.*',
            'console.my-servers.*',
            'console.my-servers.index',
        ])) {
            return 'mail_servers';
        }
        if ($this->matchesAny($routeName, [
            'console.pmta-admin.*',
            'console.pmta-scheduler.*',
            'console.pmta-settings.*',
            'console.pmta-vmta.*',
            'console.pmta-smtp.*',
            'console.pmta-errors.*',
            'console.pmta-config-history.*',
        ])) {
            return 'pmta';
        }
        if ($this->matchesAny($routeName, [
            'console.tools.*',
            'console.gcampaigns.*',
            'console.miscellaneous.app-health',
            'console.miscellaneous.app-errors',
            'console.miscellaneous.horizon',
        ])) {
            return 'tools';
        }
        if ($this->matchesAny($routeName, ['console.redirects.*', 'console.rotators.*'])) {
            return 'redirects';
        }
        if ($this->matchesAny($routeName, ['console.app-settings.*'])) {
            return 'settings';
        }

        return null;
    }

    private function webReadRequiresManage(string $routeName): bool
    {
        if ($this->matchesAny($routeName, [
            'console.campaign-test-addresses.*',
            'console.campaign-tools.*',
            'console.gcampaigns.*',
            'console.miscellaneous.horizon',
            'console.app-settings.secret',
            'console.pmta-config-history.contents',
            'console.infrastructure.bulk-install',
            'console.infrastructure.change-domain.index',
            'console.infrastructure.install.index',
            'console.infrastructure.install-settings.edit',
            'console.infrastructure.install-settings.domains.load',
            'console.infrastructure.install-username.edit',
            'console.infrastructure.reinstall.index',
            'console.infrastructure.servers.create',
            'console.infrastructure.servers.edit',
            'console.infrastructure.server-providers.create',
            'console.infrastructure.server-providers.edit',
            'console.infrastructure.domain-providers.create',
            'console.infrastructure.domain-providers.edit',
            'console.infrastructure.cloud-providers.create',
            'console.infrastructure.cloud-providers.edit',
            'console.offers.create',
            'console.offers.edit',
            'console.offers.share',
            'console.sponsors.create',
            'console.sponsors.edit',
            'console.tools.workspace.accounts.create',
            'console.tools.workspace.projects.create',
            'console.tools.workspace.projects.edit',
            'console.tools.workspace.projects.access',
        ])) {
            return true;
        }

        if (str_starts_with($routeName, 'console.campaigns.')) {
            return ! in_array($routeName, ['console.campaigns.index'], true);
        }
        if (str_starts_with($routeName, 'console.campaign-logs.')) {
            return $routeName !== 'console.campaign-logs.index';
        }
        if (str_starts_with($routeName, 'console.warmups.')) {
            return ! in_array($routeName, [
                'console.warmups.index',
                'console.warmups.statistics',
                'console.warmups.seeds.index',
            ], true);
        }
        if (str_starts_with($routeName, 'console.drops.')) {
            return ! in_array($routeName, ['console.drops.monitoring', 'console.drops.statistics'], true);
        }
        if (str_starts_with($routeName, 'console.audience-mixes.')) {
            return $routeName !== 'console.audience-mixes.history';
        }
        if (str_starts_with($routeName, 'console.infrastructure.lists.')) {
            return ! in_array($routeName, ['console.infrastructure.lists.index', 'console.infrastructure.lists.import-example'], true);
        }
        if (str_starts_with($routeName, 'console.infrastructure.isps.')) {
            return ! in_array($routeName, ['console.infrastructure.isps.index', 'console.infrastructure.isps.lists'], true);
        }
        if (str_starts_with($routeName, 'console.infrastructure.domains.')) {
            return $routeName !== 'console.infrastructure.domains.index';
        }

        return false;
    }

    private function apiRoutePermissionGroup(string $path, string $method): ?string
    {
        if (preg_match('#^/api/v1/workspaces/[^/]+/contacts/[^/]+/suppressions/?$#', $path) === 1) {
            return 'suppressions';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/contacts(?:/[^/]+)?/consent-records/?$#', $path) === 1
            || preg_match('#^/api/v1/workspaces/[^/]+/contacts/[^/]+/suppression-events/?$#', $path) === 1
            || (strtoupper($method) === 'GET' && preg_match('#^/api/v1/workspaces/[^/]+/contacts(?:/[^/]+)?/?$#', $path) === 1)) {
            return 'privacy_data';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/audience-lists/[^/]+/members(?:/.*)?$#', $path) === 1) {
            return strtoupper($method) === 'GET' ? 'privacy_data' : 'audiences';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/audience-lists(?:/.*)?$#', $path) === 1) {
            return 'audiences';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/contacts/?$#', $path) === 1) {
            return strtoupper($method) === 'GET' ? 'privacy_data' : 'audiences';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/campaigns(?:/.*)?$#', $path) === 1) {
            return 'campaigns';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/infrastructure/?$#', $path) === 1
            || preg_match('#^/api/v1/workspaces/[^/]+/servers(?:/.*)?$#', $path) === 1
            || preg_match('#^/api/v1/workspaces/[^/]+/installation-plans/?$#', $path) === 1) {
            return 'mail_servers';
        }
        if (preg_match('#^/api/v1/workspaces/[^/]+/domains(?:/.*)?$#', $path) === 1) {
            return 'domains';
        }

        return null;
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $routeName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                if (str_starts_with($routeName, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($routeName, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($routeName === $pattern) {
                return true;
            }
        }

        return false;
    }
}
