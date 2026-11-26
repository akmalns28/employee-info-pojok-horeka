<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Keycloak;
use App\Services\KeycloakAccounts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class KeycloakController extends Controller
{
    public function __construct(private Keycloak $keycloak) {}

    public function login(): View
    {
        return view('auth.login');
    }

    public function redirect(Request $request): RedirectResponse
    {
        $challenge = [
            'state' => Str::random(64),
            'nonce' => Str::random(64),
            'verifier' => Str::random(64),
            'expires' => time() + 600,
        ];

        try {
            $url = $this->keycloak->authorizationUrl($challenge['state'], $challenge['nonce'], $challenge['verifier']);
        } catch (RuntimeException) {
            return $this->loginError('Login SSO belum tersedia.');
        }

        $request->session()->put('keycloak.challenge', $challenge);

        return $this->privateRedirect(redirect()->away($url));
    }

    public function callback(Request $request, KeycloakAccounts $accounts): RedirectResponse
    {
        $challenge = $request->session()->pull('keycloak.challenge');
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_array($challenge) || $challenge['expires'] <= time()
            || ! is_string($state) || ! hash_equals($challenge['state'], $state)
            || ! is_string($code) || $code === '' || $request->query->has('error')
        ) {
            return $this->loginError('Sesi login SSO tidak valid atau telah berakhir.');
        }

        try {
            if ($request->query->has('iss') && $request->query('iss') !== $this->keycloak->issuer()) {
                return $this->loginError('Login SSO gagal diverifikasi.');
            }

            $identity = $this->keycloak->authenticate($code, $challenge['nonce'], $challenge['verifier']);
        } catch (RuntimeException) {
            return $this->loginError('Login SSO gagal diverifikasi.');
        }

        try {
            $user = $accounts->synchronize($identity);
        } catch (AuthorizationException|UniqueConstraintViolationException) {
            return $this->loginError('Akun ini tidak dapat mengakses aplikasi. Hubungi administrator.');
        }

        if (! $user->hasApplicationAccess()) {
            return $this->loginError('Akun ini belum memiliki akses aplikasi. Hubungi administrator.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('keycloak.identity', [$identity['issuer'], $identity['subject']]);

        return $this->privateRedirect(redirect()->intended(route('home')));
    }

    public function logout(Request $request): RedirectResponse
    {
        $hasSsoSession = $request->session()->has('keycloak.identity');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($hasSsoSession) {
            try {
                $returnUrl = rtrim(config('app.url'), '/').'/login';

                return $this->privateRedirect(redirect()->away($this->keycloak->logoutUrl($returnUrl)));
            } catch (RuntimeException) {
                return $this->loginError('Sesi aplikasi berakhir. Logout SSO tidak tersedia.');
            }
        }

        return $this->privateRedirect(redirect()->route('login'));
    }

    private function loginError(string $message): RedirectResponse
    {
        return $this->privateRedirect(redirect()->route('login')->with('error', $message));
    }

    private function privateRedirect(RedirectResponse $response): RedirectResponse
    {
        return $response->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
