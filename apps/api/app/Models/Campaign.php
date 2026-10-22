<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'created_by', 'name', 'kind', 'status', 'idempotency_key'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function revisions()
    {
        return $this->hasMany(CampaignRevision::class)->orderByDesc('revision_number');
    }

    public function latestRevision()
    {
        return $this->hasOne(CampaignRevision::class)
            ->whereRaw('campaign_revisions.revision_number = (SELECT MAX(latest_revisions.revision_number) FROM campaign_revisions AS latest_revisions WHERE latest_revisions.campaign_id = campaign_revisions.campaign_id)');
    }
}
