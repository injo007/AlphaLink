<?php

namespace App\Models;

use Database\Factories\WorkspaceDropVisitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceDropVisitor extends Model
{
    /** @use HasFactory<WorkspaceDropVisitorFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'workspace_drop_id', 'type', 'revenue', 'revenue_id', 'ip', 'country', 'state', 'city',
        'device', 'platform', 'browser', 'is_mobile', 'server_id', 'ip_id', 'domain_id', 'from_id', 'subject_id',
        'creative_id', 'isp_id', 'list_id', 'list_type', 'email_id', 'token_sha256',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'float',
            'is_mobile' => 'boolean',
        ];
    }

    public function drop(): BelongsTo
    {
        return $this->belongsTo(WorkspaceDrop::class, 'workspace_drop_id');
    }
}
