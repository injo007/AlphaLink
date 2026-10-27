<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AudienceList extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'created_by', 'name', 'slug', 'description', 'status',
        'idempotency_key', 'request_fingerprint', 'isp_id', 'country',
        'allowed_users', 'allowed_sponsors',
        'is_tracked', 'is_fresh_locked', 'is_delivery_locked', 'is_open_locked',
        'is_click_locked', 'is_lead_locked', 'is_unsubscribe_locked',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected function casts(): array
    {
        return [
            'allowed_users' => 'array',
            'allowed_sponsors' => 'array',
            'is_tracked' => 'boolean',
            'is_fresh_locked' => 'boolean',
            'is_delivery_locked' => 'boolean',
            'is_open_locked' => 'boolean',
            'is_click_locked' => 'boolean',
            'is_lead_locked' => 'boolean',
            'is_unsubscribe_locked' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isp(): BelongsTo
    {
        return $this->belongsTo(Isp::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ContactListMembership::class);
    }

    public function history(): HasOne
    {
        return $this->hasOne(AudienceListHistory::class)->latestOfMany('id')->withDefault([
            'fresh' => 'NA',
            'delivery' => 'NA',
            'open' => 'NA',
            'click' => 'NA',
            'lead' => 'NA',
            'unsubscribe' => 'NA',
        ]);
    }
}
