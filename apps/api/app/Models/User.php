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

#[Fillable(['name', 'email', 'password', 'username', 'type', 'roles', 'is_active', 'seen_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ADMIN = 'admin';

    public const MAILER = 'mailer';

    public const TYPES = [self::ADMIN, self::MAILER];

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

    public function hasDmsRole(string $role): bool
    {
        return $this->type === self::ADMIN || in_array($role, $this->roles ?? [], true);
    }

    public function canImpersonate(): bool
    {
        return $this->type === self::ADMIN;
    }

    public function canBeImpersonated(): bool
    {
        return $this->type === self::MAILER;
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }
}
