<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InstallationPlan extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'managed_server_id', 'sending_domain_id', 'pmta_version',
        'options', 'preview', 'preview_sha256', 'state', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'preview' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Installation plans are immutable.'));
        static::deleting(fn () => throw new LogicException('Installation plans are immutable.'));
    }
}
