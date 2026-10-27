<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceDropSuppression extends Model
{
    protected $fillable = [
        'workspace_id', 'created_by', 'sponsor_id', 'offer_id', 'isps', 'lists',
        'status', 'found', 'elapsed', 'failure_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'isps' => 'array',
            'lists' => 'array',
            'found' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSponsor::class, 'sponsor_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(WorkspaceOffer::class, 'offer_id');
    }

    public function offerListSuppressions(): HasMany
    {
        return $this->hasMany(WorkspaceOfferListSuppression::class, 'suppression_run_id');
    }
}
