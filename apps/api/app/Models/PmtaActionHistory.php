<?php

namespace App\Models;

use Database\Factories\PmtaActionHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PmtaActionHistory extends Model
{
    /** @use HasFactory<PmtaActionHistoryFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = ['workspace_id', 'managed_server_id', 'actor_user_id', 'action', 'result', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subMonth()->endOfDay());
    }

    protected static function booted(): void
    {
        static::updating(fn (self $history) => throw new LogicException('PMTA action history is append-only.'));
        static::deleting(fn (self $history) => throw new LogicException('PMTA action history is removed only by retention pruning.'));
    }
}
