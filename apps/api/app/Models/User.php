<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'username', 'type', 'roles', 'access_role', 'is_active', 'seen_at', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'avatar_path'])]
class User extends Authenticatable
{
    public const ADMIN = 'admin';

    public const MAILER = 'mailer';

    public const TYPES = [self::ADMIN, self::MAILER];

    public const ACCESS_ROLE_OWNER = 'owner';

    public const ACCESS_ROLE_ADMIN = 'admin';

    public const ACCESS_ROLE_OPERATOR = 'operator';

    public const ACCESS_ROLE_VIEWER = 'viewer';

    public const ACCESS_ROLE_PRIVACY_OFFICER = 'privacy_officer';

    public const ACCESS_ROLES = [
        self::ACCESS_ROLE_OWNER,
        self::ACCESS_ROLE_ADMIN,
        self::ACCESS_ROLE_OPERATOR,
        self::ACCESS_ROLE_VIEWER,
        self::ACCESS_ROLE_PRIVACY_OFFICER,
    ];

    public const ACCESS_ROLE_LABELS = [
        self::ACCESS_ROLE_OWNER => 'Owner',
        self::ACCESS_ROLE_ADMIN => 'Admin',
        self::ACCESS_ROLE_OPERATOR => 'Operator',
        self::ACCESS_ROLE_VIEWER => 'Viewer',
        self::ACCESS_ROLE_PRIVACY_OFFICER => 'Privacy Officer',
    ];

    public const ROLES = [
        'dashboard' => 'dashboard',
        'all_stats' => 'All Statistics',
        'isps_stats' => 'Statistics Of Isps',
        'mailers_stats' => 'Statistics Of Mailers',
        'manage_users' => 'Manage Users',
        'manage_isps' => 'Manage Isps',
        'manage_domains' => 'Manage Domains',
        'manage_servers' => 'Manage Servers',
        'manage_sponsors' => 'Manage Sponsors',
        'manage_offers' => 'Manage Offers',
        'wildcard_pmta' => 'Wildcard PMTA */*',
        'share_mta' => "Share Mta's",
        'share_headers' => 'Share Headers',
        'share_stats' => 'Share Statistics',
        'manage_clouds' => 'Manage Clouds',
    ];

    /** @var array<string, array{label: string, description: string, manage: bool}> */
    public const PERMISSION_GROUPS = [
        'dashboard' => ['label' => 'Operations overview', 'description' => 'Workspace overview and core status.', 'manage' => false],
        'analytics' => ['label' => 'Analytics', 'description' => 'Workspace analytics and performance reports.', 'manage' => false],
        'campaigns' => ['label' => 'Campaigns and sending', 'description' => 'Create, edit, test, send, and manage campaigns.', 'manage' => true],
        'campaign_logs' => ['label' => 'Campaign logs', 'description' => 'Review tests, updates, sends, and restrictions.', 'manage' => true],
        'campaign_statistics' => ['label' => 'Campaign statistics', 'description' => 'Review campaign activity summaries.', 'manage' => false],
        'campaign_headers' => ['label' => 'Saved headers', 'description' => 'View and manage shared campaign headers.', 'manage' => true],
        'global_tests' => ['label' => 'Global tests', 'description' => 'Run and review message tests.', 'manage' => true],
        'warmups' => ['label' => 'Warmups', 'description' => 'Manage warmup campaigns, seeds, and reports.', 'manage' => true],
        'drops' => ['label' => 'Drop campaigns', 'description' => 'Manage Drop drafts, monitoring, and reports.', 'manage' => true],
        'audiences' => ['label' => 'Lists and audiences', 'description' => 'View and manage audience lists and mixes.', 'manage' => true],
        'list_history' => ['label' => 'List history', 'description' => 'Review recipient-state history and consent changes.', 'manage' => true],
        'privacy_data' => ['label' => 'Privacy data', 'description' => 'Review and manage contacts, subscribers, and consent records.', 'manage' => true],
        'suppressions' => ['label' => 'Suppressions', 'description' => 'Review and manage negative lists and suppression workflows.', 'manage' => true],
        'isp_geo' => ['label' => 'ISP and Geo', 'description' => 'Manage ISPs, countries, and related audience configuration.', 'manage' => true],
        'domains' => ['label' => 'Domains', 'description' => 'Manage sending domains and DNS records.', 'manage' => true],
        'mail_servers' => ['label' => 'Mail servers', 'description' => 'View and manage servers, installs, and IP assignments.', 'manage' => true],
        'provider_network' => ['label' => 'Provider network', 'description' => 'Manage cloud, domain, server providers, and sponsors.', 'manage' => true],
        'offers' => ['label' => 'Offers', 'description' => 'View and manage sponsor offers and assets.', 'manage' => true],
        'pmta' => ['label' => 'PMTA management', 'description' => 'View and operate PowerMTA controls and configuration.', 'manage' => true],
        'tools' => ['label' => 'Tools and utilities', 'description' => 'Use workspace tools, health, errors, and queue views.', 'manage' => true],
        'redirects' => ['label' => 'Redirects and rotators', 'description' => 'View and manage redirect services.', 'manage' => true],
        'settings' => ['label' => 'Workspace settings', 'description' => 'View and update workspace configuration.', 'manage' => true],
    ];

    /** @var array<string, list<string>> */
    public const DEFAULT_PERMISSIONS_BY_ACCESS_ROLE = [
        self::ACCESS_ROLE_OPERATOR => [
            'dashboard.view', 'analytics.view', 'campaigns.view', 'campaigns.manage',
            'campaign_logs.view', 'campaign_logs.manage', 'campaign_statistics.view',
            'campaign_headers.view', 'campaign_headers.manage', 'global_tests.view', 'global_tests.manage',
            'warmups.view', 'warmups.manage', 'drops.view', 'drops.manage',
            'audiences.view', 'audiences.manage', 'list_history.view', 'list_history.manage',
            'suppressions.view', 'suppressions.manage', 'offers.view', 'provider_network.view',
            'isp_geo.view', 'domains.view', 'mail_servers.view', 'privacy_data.view', 'privacy_data.manage',
        ],
        self::ACCESS_ROLE_VIEWER => [
            'dashboard.view', 'analytics.view', 'campaigns.view', 'campaign_logs.view',
            'campaign_statistics.view', 'warmups.view', 'drops.view', 'audiences.view', 'list_history.view',
        ],
        self::ACCESS_ROLE_PRIVACY_OFFICER => [
            'dashboard.view', 'analytics.view', 'audiences.view', 'list_history.view',
            'privacy_data.view', 'privacy_data.manage', 'suppressions.view', 'suppressions.manage',
        ],
    ];

    /** @var list<string> */
    public const MAILER_SENDING_PERMISSIONS = [
        'campaigns.view', 'campaigns.manage', 'campaign_logs.view', 'campaign_headers.view',
        'campaign_headers.manage', 'global_tests.view', 'global_tests.manage', 'audiences.view',
        'offers.view', 'provider_network.view', 'isp_geo.view', 'domains.view', 'mail_servers.view',
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'is_active' => 'boolean',
            'seen_at' => 'datetime',
        ];
    }

    public static function getRoles(): array
    {
        return self::ROLES;
    }

    /** @return array<string, string> */
    public static function permissionChoices(): array
    {
        $choices = [];
        foreach (self::PERMISSION_GROUPS as $key => $group) {
            $choices[$key.'.view'] = 'View '.$group['label'];
            if ($group['manage']) {
                $choices[$key.'.manage'] = 'Manage '.$group['label'];
            }
        }

        return $choices;
    }

    /** @return list<string> */
    public static function defaultPermissions(string $accessRole): array
    {
        return self::DEFAULT_PERMISSIONS_BY_ACCESS_ROLE[$accessRole] ?? [];
    }

    /** @param list<string> $permissions
     *  @return list<string>
     */
    public static function normalizePermissions(array $permissions): array
    {
        $legacyPermissions = [
            'dashboard' => 'dashboard.view',
            'all_stats' => 'analytics.view',
            'isps_stats' => 'analytics.view',
            'mailers_stats' => 'analytics.view',
            'share_stats' => 'analytics.view',
            'manage_isps' => ['isp_geo.manage', 'audiences.manage'],
            'manage_domains' => 'domains.manage',
            'manage_servers' => 'mail_servers.manage',
            'manage_sponsors' => 'provider_network.manage',
            'manage_offers' => 'offers.manage',
            'wildcard_pmta' => 'pmta.manage',
            'share_mta' => 'pmta.manage',
            'share_headers' => 'campaign_headers.manage',
            'manage_clouds' => 'provider_network.manage',
        ];
        $allowed = self::permissionChoices();
        $normalized = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }

            $mappedPermissions = $legacyPermissions[$permission] ?? [$permission];
            foreach ((array) $mappedPermissions as $mappedPermission) {
                if (isset($allowed[$mappedPermission])) {
                    $normalized[$mappedPermission] = $mappedPermission;
                }
            }
        }

        return array_values($normalized);
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        if ($this->hasFullAccess()) {
            return ['*'];
        }

        return self::normalizePermissions($this->roles ?? self::defaultPermissions((string) $this->access_role));
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->hasFullAccess()) {
            return true;
        }

        $permissions = $this->effectivePermissions();
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        if (str_ends_with($permission, '.view')) {
            return in_array(substr($permission, 0, -5).'.manage', $permissions, true);
        }

        return false;
    }

    public function hasRole(string $role): bool
    {
        if ($role === 'manage_users') {
            return in_array($this->access_role, [self::ACCESS_ROLE_OWNER, self::ACCESS_ROLE_ADMIN], true);
        }

        if ($role === 'share_headers') {
            return $this->hasFullAccess() || $this->hasPermission('campaign_headers.manage');
        }

        return in_array($role, $this->roles ?? [], true)
            || in_array($role, self::normalizePermissions($this->roles ?? []), true);
    }

    public function isOwner(): bool
    {
        return $this->access_role === self::ACCESS_ROLE_OWNER;
    }

    public function hasFullAccess(): bool
    {
        return in_array($this->access_role, [self::ACCESS_ROLE_OWNER, self::ACCESS_ROLE_ADMIN], true);
    }

    public function canImpersonate(): bool
    {
        return $this->hasFullAccess();
    }

    public function canBeImpersonated(): bool
    {
        return $this->type === self::MAILER && ! $this->hasFullAccess();
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }
}
