<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['uuid', 'slug', 'nama_depan', 'nama_belakang', 'email', 'google_id', 'avatar','password'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password', 'remember_token', 'keycloak_issuer', 'keycloak_subject',
        'keycloak_verified_at', 'keycloak_bootstrap_admin',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'keycloak_bootstrap_admin' => 'boolean',
            'keycloak_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function departemen()
    {
        return $this->belongsTo(Departemen::class, 'departemen_uuid');
    }

    public function karyawan()
    {
        return $this->hasOne(Karyawan::class, 'user_uuid', 'uuid');
    }

    public function hasActiveAccount(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $employee = $this->karyawan;

        return $employee
            ? (bool) $employee->status
            : ! Karyawan::whereRaw('LOWER(email) = ?', [strtolower($this->email)])->where('status', false)->exists();
    }

    public function hasApplicationAccess(): bool
    {
        if ($this->keycloak_bootstrap_admin && $this->email !== config('app.default_admin_email') && $this->hasRole('admin')) {
            return false;
        }

        return $this->roles->isNotEmpty() || $this->permissions->isNotEmpty();
    }
}
