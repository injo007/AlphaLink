<?php

namespace App\Models;

use Database\Factories\WorkspaceSeedEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSeedEmail extends Model
{
    /** @use HasFactory<WorkspaceSeedEmailFactory> */
    use HasFactory;

    protected $fillable = ['workspace_seed_list_id', 'email'];

    public function seedList(): BelongsTo
    {
        return $this->belongsTo(WorkspaceSeedList::class, 'workspace_seed_list_id');
    }
}
