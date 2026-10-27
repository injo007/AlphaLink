<?php

namespace App\Models;

use Database\Factories\WorkspaceSponsorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceSponsor extends Model
{
    /** @use HasFactory<WorkspaceSponsorFactory> */
    use HasFactory, SoftDeletes;

    public const TYPES = ['hitpath', 'newhitpath', 'cake', 'hasoffers', 'everflow'];

    public const IMPORT_TYPES = ['hitpath', 'newhitpath', 'cake', 'hasoffers', 'everflow'];

    protected $fillable = [
        'workspace_id',
        'name',
        'affiliate_id',
        'url',
        'username',
        'password',
        'api_key',
        'type',
        'allowed_users',
        'is_active',
    ];

    protected $hidden = ['username', 'password', 'api_key'];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'allowed_users' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WorkspaceOffer::class, 'sponsor_id');
    }
}
