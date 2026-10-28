<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceBlacklistProcess extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'created_by', 'status', 'email_type', 'input_mode',
        'original_filename', 'storage_path', 'file_size_bytes',
        'byte_offset', 'processed_count', 'matched_count', 'updated_count',
        'invalid_count', 'failure_message', 'started_at', 'finished_at',
        'history_recalculated_at',
    ];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'byte_offset' => 'integer',
            'processed_count' => 'integer',
            'matched_count' => 'integer',
            'updated_count' => 'integer',
            'invalid_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'history_recalculated_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, int|string|null> */
    public function statusPayload(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'user' => $this->createdBy?->name ?? 'Deleted user',
            'file' => $this->original_filename,
            'type' => $this->email_type,
            'status' => $this->status,
            'processed_count' => (int) $this->processed_count,
            'matched_count' => (int) $this->matched_count,
            'updated_count' => (int) $this->updated_count,
            'invalid_count' => (int) $this->invalid_count,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'can_cancel' => in_array($this->status, ['queued', 'running', 'cancelling'], true),
        ];
    }
}
