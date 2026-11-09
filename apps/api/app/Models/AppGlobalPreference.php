<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppGlobalPreference extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
