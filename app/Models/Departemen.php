<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departemen extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'kode_departemen',
        'departemen',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function divisis(): HasMany
    {
        return $this->hasMany(Divisi::class, 'uuid_departemen', 'uuid');
    }
}
