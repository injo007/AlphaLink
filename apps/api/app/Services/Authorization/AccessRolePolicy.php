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

    public function canAccessWebRoute(string $role, string $routeName, string $method): bool
    {
        if ($this->isFullAccess($role)) {
            return true;
        }

        $routeName = preg_replace('/^clean\./', '', $routeName) ?? $routeName;

        if ($role === User::ACCESS_ROLE_OPERATOR) {
            return $this->matchesAny($routeName, [
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
                && strtoupper($method) === 'POST';
            if (! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) && ! $isReadOnlyListHistoryQuery) {
                return false;
            }

            return $this->matchesAny($routeName, [
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
            if (strtoupper($method) === 'GET' || strtoupper($method) === 'HEAD') {
                return $this->matchesAny($routeName, [
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

        return is_string($name) && $this->canAccessWebRoute($user->access_role, $name, 'GET');
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

            if ($routeName === $pattern) {
                return true;
            }
        }

        return false;
    }
}
