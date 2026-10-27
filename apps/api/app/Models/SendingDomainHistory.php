<?php

namespace App\Models;

use Database\Factories\SendingDomainHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SendingDomainHistory extends Model
{
    /** @use HasFactory<SendingDomainHistoryFactory> */
    use HasFactory;

    use MassPrunable;

    protected $fillable = [
        'workspace_id', 'sending_domain_id', 'a_record', 'spf', 'dkim', 'dmarc',
        'is_listed_spamhaus', 'is_listed_hostkarma',
    ];

    protected function casts(): array
    {
        return [
            'is_listed_spamhaus' => 'boolean',
            'is_listed_hostkarma' => 'boolean',
        ];
    }

    public function sendingDomain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now('Africa/Casablanca')->subMonth()->endOfDay());
    }
}
