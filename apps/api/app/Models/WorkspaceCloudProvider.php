<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceCloudProvider extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'linode',
        'server_space',
        'digitalocean',
        'vultr',
        'regru',
        'amazon',
        'azure',
        'hetzner',
    ];

    /** Consumer workflows currently implemented from the DMS reference. */
    public const WORKFLOW_TYPES = ['linode', 'digitalocean'];

    protected $fillable = ['workspace_id', 'type', 'name', 'tokens', 'allowed_users'];

    protected $hidden = ['tokens'];

    protected function casts(): array
    {
        return [
            'tokens' => 'encrypted:array',
            'allowed_users' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
