<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'created_by', 'name', 'kind', 'status', 'is_active', 'idempotency_key', 'send_progress',
        'snapshot_build_stage', 'snapshot_build_percent', 'snapshot_build_processed', 'snapshot_build_total',
        'snapshot_build_error', 'snapshot_build_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'send_progress' => 'integer',
            'snapshot_build_percent' => 'integer',
            'snapshot_build_processed' => 'integer',
            'snapshot_build_total' => 'integer',
            'snapshot_build_updated_at' => 'immutable_datetime',
        ];
    }

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

    public function sendRuns(): HasMany
    {
        return $this->hasMany(CampaignSendRun::class)->orderByDesc('created_at');
    }
}
