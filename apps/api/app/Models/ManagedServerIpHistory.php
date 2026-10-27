<?php

namespace App\Models;

use Database\Factories\ManagedServerIpHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedServerIpHistory extends Model
{
    /** @use HasFactory<ManagedServerIpHistoryFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = [
        'workspace_id',
        'managed_server_ip_id',
        'ptr',
        'ip_listed_spamhaus',
        'ip_listed_spamcop',
        'ip_listed_barracuda',
        'sender_score',
        'domain_listed_hostkarma',
        'domain_listed_spamhaus_dbl',
        'domain_listed_surbl',
    ];

    protected function casts(): array
    {
        return [
            'ip_listed_spamhaus' => 'boolean',
            'ip_listed_spamcop' => 'boolean',
            'ip_listed_barracuda' => 'boolean',
            'domain_listed_hostkarma' => 'boolean',
            'domain_listed_spamhaus_dbl' => 'boolean',
            'domain_listed_surbl' => 'boolean',
        ];
    }

    public function ip(): BelongsTo
    {
        return $this->belongsTo(ManagedServerIp::class, 'managed_server_ip_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subMonth()->endOfDay());
    }
}
