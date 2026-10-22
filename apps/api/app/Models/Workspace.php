<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'status', 'created_by', 'idempotency_key', 'request_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function audienceLists()
    {
        return $this->hasMany(AudienceList::class);
    }

    public function managedServers()
    {
        return $this->hasMany(ManagedServer::class);
    }

    public function sendingDomains()
    {
        return $this->hasMany(SendingDomain::class);
    }

    public function installationPlans()
    {
        return $this->hasMany(InstallationPlan::class);
    }
}
