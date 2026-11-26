<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $defaultAdminEmail = config('app.default_admin_email');
        if (is_string($defaultAdminEmail)) {
            $defaultAdminEmail = strtolower(trim($defaultAdminEmail));
        }

        if ($defaultAdminEmail !== null && $defaultAdminEmail !== '' && (
            ! is_string($defaultAdminEmail)
            || ! filter_var($defaultAdminEmail, FILTER_VALIDATE_EMAIL)
        )) {
            throw new InvalidArgumentException('DEFAULT_ADMIN_EMAIL must be empty or a valid email address.');
        }

        config(['app.default_admin_email' => $defaultAdminEmail === '' ? null : $defaultAdminEmail]);

        Gate::before(function ($user, $ability) {
            if ($user->hasAnyRole(['admin', 'super admin'])) {
                return true;
            }

            return null;
        });

        Paginator::useBootstrapFive();
    }
}
