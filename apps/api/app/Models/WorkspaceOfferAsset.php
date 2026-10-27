<?php

namespace App\Models;

use Database\Factories\WorkspaceOfferAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceOfferAsset extends Model
{
    /** @use HasFactory<WorkspaceOfferAssetFactory> */
    use HasFactory;

    protected $fillable = ['offer_id', 'workspace_id', 'type', 'value', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(WorkspaceOffer::class, 'offer_id');
    }
}
