<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AudienceMixSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'created_by', 'isp_id', 'name', 'criteria', 'recipient_count',
        'status', 'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'recipient_count' => 'integer',
            'expired_at' => 'immutable_datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(AudienceMixSnapshotMember::class, 'snapshot_id');
    }

    public function isp(): BelongsTo
    {
        return $this->belongsTo(Isp::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
