<?php

namespace App\Models;

use Database\Factories\SendingDomainDkimIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SendingDomainDkimIdentity extends Model
{
    /** @use HasFactory<SendingDomainDkimIdentityFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'sending_domain_id', 'hostname', 'selector',
        'private_key', 'public_record', 'is_active',
    ];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class, 'sending_domain_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ManagedServerIpDomain::class, 'sending_domain_dkim_identity_id');
    }
}
