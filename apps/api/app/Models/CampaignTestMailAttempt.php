<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CampaignTestMailAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'campaign_id', 'revision_id', 'actor_user_id',
        'recipient_sha256', 'idempotency_key_hash', 'mailer', 'status',
        'managed_server_id', 'request_fingerprint',
        'exception_type', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function revision()
    {
        return $this->belongsTo(CampaignRevision::class, 'revision_id');
    }
}
