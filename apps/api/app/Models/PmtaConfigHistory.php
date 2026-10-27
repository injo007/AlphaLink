<?php

namespace App\Models;

use Database\Factories\PmtaConfigHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmtaConfigHistory extends Model
{
    /** @use HasFactory<PmtaConfigHistoryFactory> */
    use HasFactory;

    protected $fillable = ['workspace_id', 'managed_server_id', 'actor_user_id', 'file', 'action', 'contents', 'result', 'succeeded'];

    protected function casts(): array
    {
        return ['contents' => 'encrypted', 'succeeded' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
