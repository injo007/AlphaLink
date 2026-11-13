<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSpfSearchResult extends Model
{
    protected $fillable = ['search_run_id', 'root_id', 'matched_ip', 'root', 'include', 'network'];

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSpfSearchRun::class, 'search_run_id');
    }

    public function rootRecord(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSpfRoot::class, 'root_id');
    }
}
