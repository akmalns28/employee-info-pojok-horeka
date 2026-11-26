<?php

namespace App\Http\Middleware;

use App\Services\Keycloak;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EnsureKeycloakAccess
{
    public function __construct(private Keycloak $keycloak) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $issuer = $this->keycloak->issuer();
        } catch (RuntimeException) {
            $issuer = null;
        }

        $user = $request->user();
        if ($user && $issuer !== null && $user->keycloak_issuer === $issuer && $user->keycloak_subject !== null
            && $user->keycloak_verified_at !== null
            && $request->session()->get('keycloak.identity') === [$issuer, $user->keycloak_subject]
            && $user->hasActiveAccount() && $user->hasApplicationAccess()
        ) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403);
    }
}
