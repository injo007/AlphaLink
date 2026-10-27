<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceContactSubmission extends Model
{
    protected $fillable = [
        'workspace_id', 'full_name', 'email', 'phone', 'message', 'ip',
        'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile',
    ];

    protected function casts(): array
    {
        return ['is_mobile' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
