<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContactListMembership extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'audience_list_id', 'contact_id', 'email_type', 'status',
        'joined_at', 'left_at', 'last_event_id', 'profile', 'profile_sex_index',
        'email_domain_index', 'attribute_index_version',
    ];

    protected $hidden = ['profile', 'profile_sex_index', 'email_domain_index', 'attribute_index_version'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'profile' => 'encrypted:array',
            'attribute_index_version' => 'integer',
        ];
    }

    public const CAMPAIGN_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    public const HISTORY_TRACKED_TYPES = ['opt_out', 'bot', 'proxy', 'blacklist', 'hard_bounce'];

    public function audienceList()
    {
        return $this->belongsTo(AudienceList::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
