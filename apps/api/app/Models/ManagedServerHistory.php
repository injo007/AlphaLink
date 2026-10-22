<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedServerHistory extends Model
{
    use MassPrunable;

    protected $fillable = [
        'workspace_id',
        'managed_server_id',
        'is_ssh_down',
        'is_apache_down',
        'is_pmta_down',
    ];

    protected function casts(): array
    {
        return [
            'is_ssh_down' => 'boolean',
            'is_apache_down' => 'boolean',
            'is_pmta_down' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subMonths(3)->endOfDay());
    }
}
