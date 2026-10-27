<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceOfferListSuppression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'offer_id', 'audience_list_id', 'contact_id', 'suppression_run_id',
        'suppression_sha256', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
