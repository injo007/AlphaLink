<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function campaignHeaders(): HasMany
    {
        return $this->hasMany(WorkspaceCampaignHeader::class);
    }

    public function campaignNegatives(): HasMany
    {
        return $this->hasMany(WorkspaceCampaignNegative::class);
    }

    public function globalTestLogs(): HasMany
    {
        return $this->hasMany(WorkspaceGlobalTestLog::class);
    }

    public function drops(): HasMany
    {
        return $this->hasMany(WorkspaceDrop::class);
    }

    public function seedLists(): HasMany
    {
        return $this->hasMany(WorkspaceSeedList::class);
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

    public function isps()
    {
        return $this->hasMany(Isp::class);
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(WorkspaceSponsor::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WorkspaceOffer::class);
    }

    public function serverProviders(): HasMany
    {
        return $this->hasMany(ServerProvider::class);
    }

    public function cloudProviders(): HasMany
    {
        return $this->hasMany(WorkspaceCloudProvider::class);
    }

    public function infrastructureSettings(): HasOne
    {
        return $this->hasOne(WorkspaceInfrastructureSettings::class);
    }

    public function appSettings(): HasOne
    {
        return $this->hasOne(WorkspaceAppSettings::class);
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(WorkspaceSubscriber::class);
    }

    public function contactSubmissions(): HasMany
    {
        return $this->hasMany(WorkspaceContactSubmission::class);
    }
}
