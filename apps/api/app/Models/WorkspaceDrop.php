<?php

namespace App\Models;

use Database\Factories\WorkspaceDropFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceDrop extends Model
{
    /** @use HasFactory<WorkspaceDropFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'user_id', 'sponsor_id', 'offer_id', 'isp_id', 'send_type', 'servers', 'lists',
        'filters', 'countries', 'send_status', 'send_progress', 'send_count', 'is_processed', 'is_scheduled',
        'scheduled_at', 'is_restricted',
    ];

    protected function casts(): array
    {
        return [
            'servers' => 'array',
            'lists' => 'array',
            'filters' => 'array',
            'countries' => 'array',
            'send_progress' => 'integer',
            'send_count' => 'integer',
            'is_processed' => 'boolean',
            'is_scheduled' => 'boolean',
            'is_restricted' => 'boolean',
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSponsor::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(WorkspaceOffer::class);
    }

    public function isp(): BelongsTo
    {
        return $this->belongsTo(Isp::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(WorkspaceDropVisitor::class, 'workspace_drop_id')
            ->where('workspace_id', $this->workspace_id);
    }
}
