<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceCommunication extends Model
{
    public const TONE_LABELS = [
        'standard' => 'Standard',
        'info' => 'Informational',
        'warning' => 'Attention',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'workspace_id', 'created_by_id', 'target_operator_id', 'kind', 'tone', 'audience', 'title', 'body', 'due_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function targetOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_operator_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WorkspaceCommunicationRecipient::class, 'communication_id');
    }
}
