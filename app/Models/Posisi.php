<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Posisi extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'uuid',
        'nama_posisi',
        'kode_posisi',
        'divisi_uuid',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class, 'divisi_uuid', 'uuid');
    }

    public function hasActiveHierarchy(): bool
    {
        return $this->is_active && $this->divisi->is_active && $this->divisi->departemen->is_active;
    }

    public function karyawans(): HasMany
    {
        return $this->hasMany(
            Karyawan::class,
            'uuid_posisi',
            'uuid'
        );
    }
}
