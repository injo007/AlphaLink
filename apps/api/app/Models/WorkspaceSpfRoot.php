<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSpfRoot extends Model
{
    protected $fillable = ['workspace_id', 'domain', 'status', 'record', 'cidrs', 'hosts', 'scanned_at'];

    protected function casts(): array
    {
        return ['cidrs' => 'array', 'hosts' => 'array', 'scanned_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
