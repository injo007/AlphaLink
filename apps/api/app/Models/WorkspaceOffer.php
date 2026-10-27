<?php

namespace App\Models;

use Database\Factories\WorkspaceOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceOffer extends Model
{
    /** @use HasFactory<WorkspaceOfferFactory> */
    use HasFactory, SoftDeletes;

    public const TYPES = ['CPA', 'CPC', 'CPS', 'CPL', 'REVSHARE'];

    public const STATUSES = ['live', 'issue', 'paused'];

    public const ALLOWED_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    protected $fillable = [
        'workspace_id', 'sponsor_id', 'offer_id', 'name', 'type', 'vertical', 'geo_target',
        'payout', 'redirect', 'unsubscribe', 'note', 'allowed_days', 'status',
        'suppression_path', 'suppression_sha256', 'suppression_format', 'suppression_updated_at', 'allowed_users', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'geo_target' => 'array',
            'allowed_days' => 'array',
            'suppression_updated_at' => 'date',
            'allowed_users' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSponsor::class, 'sponsor_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WorkspaceOfferAsset::class, 'offer_id');
    }

    public function fromAssets(): HasMany
    {
        return $this->assets()->where('type', 'from');
    }

    public function subjectAssets(): HasMany
    {
        return $this->assets()->where('type', 'subject');
    }
}
