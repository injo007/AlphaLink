<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceMixSnapshotMember extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'snapshot_id', 'contact_id', 'source_list_id', 'source_membership_id', 'email_type',
        'position', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AudienceMixSnapshot::class, 'snapshot_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sourceList(): BelongsTo
    {
        return $this->belongsTo(AudienceList::class, 'source_list_id');
    }
}
