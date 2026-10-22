<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ServerCredential extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'managed_server_id', 'credential_type', 'encrypted_secret', 'created_by', 'rotated_at'];

    protected $hidden = ['encrypted_secret'];
}
