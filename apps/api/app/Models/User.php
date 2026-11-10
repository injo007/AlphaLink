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

    public function hasRole(string $role): bool
    {
        if ($role === 'manage_users') {
            return in_array($this->access_role, [self::ACCESS_ROLE_OWNER, self::ACCESS_ROLE_ADMIN], true);
        }

        if ($role === 'share_headers') {
            return $this->hasFullAccess();
        }

        return in_array($role, $this->roles ?? [], true);
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
