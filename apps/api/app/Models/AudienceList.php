<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AudienceList extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'created_by', 'name', 'slug', 'description', 'status',
        'idempotency_key', 'request_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function memberships()
    {
        return $this->hasMany(ContactListMembership::class);
    }
}
