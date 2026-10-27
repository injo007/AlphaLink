<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceCampaignTestAddress extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'email'];

    protected function casts(): array
    {
        return ['email' => 'encrypted'];
    }
}
