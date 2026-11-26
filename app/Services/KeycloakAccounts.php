<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class KeycloakAccounts
{
    /** @param array{issuer: string, subject: string, email: string, first_name: string, last_name: ?string} $identity */
    public function synchronize(array $identity): User
    {
        return DB::transaction(function () use ($identity) {
            $user = User::where('keycloak_subject', $identity['subject'])->lockForUpdate()->first();

            if ($user && ($user->keycloak_issuer !== $identity['issuer'] || $user->keycloak_subject !== $identity['subject'])) {
                throw new AuthorizationException;
            }

            if ($user && $user->keycloak_verified_at === null && strtolower($user->email) !== $identity['email']) {
                throw new AuthorizationException;
            }

            $emailOwner = User::whereRaw('LOWER(email) = ?', [$identity['email']]);
            if ($user) {
                $emailOwner->whereKeyNot($user->getKey());
            }
            if ($emailOwner->exists()) {
                throw new AuthorizationException;
            }

            $user ??= new User(['password' => Str::random(64)]);
            $user->forceFill([
                'keycloak_issuer' => $identity['issuer'],
                'keycloak_subject' => $identity['subject'],
                'keycloak_verified_at' => $user->keycloak_verified_at ?? now(),
                'is_active' => $user->exists ? $user->is_active : true,
                'email' => $identity['email'],
                'nama_depan' => $identity['first_name'],
                'nama_belakang' => $identity['last_name'],
                'slug' => Str::slug(trim($identity['first_name'].' '.$identity['last_name'])),
            ]);

            if (! $user->hasActiveAccount()) {
                throw new AuthorizationException;
            }

            $isDefaultAdmin = $identity['email'] === config('app.default_admin_email');
            if (! $isDefaultAdmin && $user->keycloak_bootstrap_admin) {
                if ($user->hasRole('admin')) {
                    $user->removeRole('admin');
                }
                $user->keycloak_bootstrap_admin = false;
            }

            $user->save();

            if ($isDefaultAdmin && ! $user->hasAnyRole(['admin', 'super admin'])) {
                $user->assignRole(Role::findOrCreate('admin', 'web'));
                $user->keycloak_bootstrap_admin = true;
                $user->save();
            }

            return $user;
        }, attempts: 3);
    }
}
