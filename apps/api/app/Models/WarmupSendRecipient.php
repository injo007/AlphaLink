<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmupSendRecipient extends Model
{
    protected $fillable = [
        'workspace_id', 'send_run_id', 'position', 'recipient_ciphertext', 'recipient_sha256',
        'state', 'transport_code', 'error_code', 'response_sha256', 'finished_at',
    ];

    protected $hidden = ['recipient_ciphertext', 'recipient_sha256', 'response_sha256'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'finished_at' => 'immutable_datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WarmupSendRun::class, 'send_run_id');
    }
}
