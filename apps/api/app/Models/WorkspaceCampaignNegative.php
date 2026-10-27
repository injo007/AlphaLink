<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceCampaignNegative extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'path',
        'original_filename',
        'sha256',
        'size_bytes',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
