<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerProvider extends Model
{
    protected $fillable = ['name'];

    public function managedServers(): HasMany
    {
        return $this->hasMany(ManagedServer::class, 'servers_provider_id');
    }
}
