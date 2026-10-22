<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'email', 'email_lookup_hash', 'lookup_key_version',
        'status', 'idempotency_key',
    ];

    protected $hidden = ['email', 'email_lookup_hash', 'lookup_key_version', 'idempotency_key'];

    protected function casts(): array
    {
        return ['email' => 'encrypted'];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function consentRecords()
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function suppressionEvents()
    {
        return $this->hasMany(SuppressionEvent::class);
    }
}
