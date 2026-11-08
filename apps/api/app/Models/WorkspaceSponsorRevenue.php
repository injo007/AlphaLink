<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceSponsorRevenue extends Model
{
    protected $fillable = [
        'workspace_id', 'sponsor_id', 'provider_type', 'provider_conversion_id', 'amount', 'currency_code', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
