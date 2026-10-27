<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Isp extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'is_active',
        'allowed_users',
        'allowed_sponsors',
        'tracked_countries',
        'quality_emails',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allowed_users' => 'array',
            'allowed_sponsors' => 'array',
            'tracked_countries' => 'array',
            'quality_emails' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lists(): HasMany
    {
        return $this->hasMany(AudienceList::class);
    }
}
