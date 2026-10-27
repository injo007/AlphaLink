<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServerProvider extends Model
{
    use SoftDeletes;

    protected $fillable = ['workspace_id', 'name', 'url', 'username', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function managedServers(): HasMany
    {
        return $this->hasMany(ManagedServer::class, 'servers_provider_id');
    }
}
