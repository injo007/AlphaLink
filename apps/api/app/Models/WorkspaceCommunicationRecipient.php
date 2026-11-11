<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceCommunicationRecipient extends Model
{
    protected $fillable = ['communication_id', 'user_id', 'read_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCommunication::class, 'communication_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
