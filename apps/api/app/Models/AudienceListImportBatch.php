<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceListImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'audience_list_id', 'created_by', 'status', 'phase',
        'source_format', 'membership_type', 'filter', 'storage_path', 'file_sha256', 'file_size_bytes',
        'checksum_verified', 'validation_offset', 'import_offset', 'processed_count',
        'valid_count', 'filtered_count', 'invalid_count', 'imported_count',
        'duplicate_count', 'failure_message', 'request_id', 'next_dispatch_at',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'checksum_verified' => 'boolean',
            'file_size_bytes' => 'integer',
            'validation_offset' => 'integer',
            'import_offset' => 'integer',
            'processed_count' => 'integer',
            'valid_count' => 'integer',
            'filtered_count' => 'integer',
            'invalid_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'next_dispatch_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function audienceList(): BelongsTo
    {
        return $this->belongsTo(AudienceList::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progressPercent(): int
    {
        if ($this->status === 'completed') {
            return 100;
        }

        if ($this->file_size_bytes < 1) {
            return 0;
        }

        $offset = $this->phase === 'validation' ? $this->validation_offset : $this->import_offset;
        $progress = $this->file_size_bytes > 0 ? ($offset / $this->file_size_bytes) : 0;
        if ($this->source_format === 'dms_csv' && $this->phase === 'validation') {
            $progress *= 0.5;
        } elseif ($this->source_format === 'dms_csv') {
            $progress = 0.5 + ($progress * 0.5);
        }

        return min(99, (int) floor($progress * 100));
    }

    /** @return array<string, int|string|null> */
    public function statusPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status,
            'phase' => $this->phase,
            'progress_percent' => $this->progressPercent(),
            'processed_count' => (int) $this->processed_count,
            'valid_count' => (int) $this->valid_count,
            'filtered_count' => (int) $this->filtered_count,
            'invalid_count' => (int) $this->invalid_count,
            'imported_count' => (int) $this->imported_count,
            'duplicate_count' => (int) $this->duplicate_count,
            'failure_message' => $this->failure_message,
        ];
    }
}
