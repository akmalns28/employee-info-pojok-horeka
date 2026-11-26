<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Divisi extends Model
{
    use HasUuid;

    protected $fillable = ['uuid', 'uuid_departemen', 'kode_divisi', 'nama_divisi', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function departemen(): BelongsTo
    {
        return $this->belongsTo(Departemen::class, 'uuid_departemen', 'uuid');
    }

    public function posisis(): HasMany
    {
        return $this->hasMany(Posisi::class, 'divisi_uuid', 'uuid');
    }

    public function karyawans(): HasMany
    {
        return $this->hasMany(
            Karyawan::class,
            'divisi_uuid',
            'uuid'
        );
    }
}
