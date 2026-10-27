<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSubscriber extends Model
{
    protected $fillable = [
        'workspace_id', 'email', 'ip', 'country', 'state', 'city',
        'device', 'platform', 'browser', 'is_mobile',
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
