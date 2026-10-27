<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainProvider extends Model
{
    public const TYPES = ['namecheap', 'godaddy', 'cloudflare'];

    protected $fillable = ['workspace_id', 'name', 'type', 'username', 'password', 'api_key', 'api_secret', 'is_active'];

    protected $hidden = ['password', 'api_key', 'api_secret'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sendingDomains(): HasMany
    {
        return $this->hasMany(SendingDomain::class, 'domains_provider_id');
    }
}
