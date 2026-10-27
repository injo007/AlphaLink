<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceListHistory extends Model
{
    protected $fillable = [
        'workspace_id', 'audience_list_id', 'at',
        'fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe',
        'opt_out', 'proxy', 'bot', 'blacklist', 'hard_bounce',
    ];

    public function audienceList(): BelongsTo
    {
        return $this->belongsTo(AudienceList::class);
    }
}
