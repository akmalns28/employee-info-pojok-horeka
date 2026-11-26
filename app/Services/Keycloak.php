<?php

namespace App\Services;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use TypeError;
use UnexpectedValueException;

class Keycloak
{
    public function issuer(): string
    {
        $baseUrl = $this->secureUrl(config('services.keycloak.base_url'));
        $realm = config('services.keycloak.realm');

        if (! is_string($realm) || ! preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\z/', $realm)) {
            throw new RuntimeException('Invalid Keycloak realm configuration.');
        }

        $issuer = rtrim($baseUrl, '/').'/realms/'.$realm;

        if (strlen($issuer) > 255) {
            throw new RuntimeException('Invalid Keycloak issuer configuration.');
        }

        return $issuer;
    }

    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $this->setting('client_secret');

        return $this->endpoint('auth').'?'.http_build_query([
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $this->secureUrl(config('services.keycloak.redirect_uri')),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /** @return array{issuer: string, subject: string, email: string, first_name: string, last_name: ?string} */
    public function authenticate(#[\SensitiveParameter] string $code, string $nonce, #[\SensitiveParameter] string $verifier): array
    {
        $issuer = $this->issuer();
        $clientId = $this->setting('client_id');
        $http = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);

        try {
            $response = $http->asForm()->post($this->endpoint('token'), [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $this->setting('client_secret'),
                'redirect_uri' => $this->secureUrl(config('services.keycloak.redirect_uri')),
                'code' => $code,
                'code_verifier' => $verifier,
            ]);

            $token = $response->successful() ? $response->json('id_token') : null;
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Keycloak code exchange failed.');
            }

            $response = $http->get($this->endpoint('certs'));
            $keys = $response->successful() ? $response->json('keys') : null;
        } catch (ConnectionException) {
            throw new RuntimeException('Keycloak is unavailable.');
        }

        if (! is_array($keys)) {
            throw new RuntimeException('Keycloak signing keys are unavailable.');
        }

        $signingKeys = array_filter($keys, static fn ($key) => is_array($key)
            && ($key['kty'] ?? null) === 'RSA'
            && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? 'RS256') === 'RS256');

        try {
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => $signingKeys], 'RS256'));
        } catch (DomainException|InvalidArgumentException|TypeError|UnexpectedValueException) {
            throw new RuntimeException('Keycloak ID token validation failed.');
        }

        $audiences = $claims->aud ?? null;
        $audiences = is_string($audiences) ? [$audiences] : $audiences;
        $subject = $claims->sub ?? null;
        $email = is_string($claims->email ?? null) ? strtolower(trim($claims->email)) : '';

        if (($claims->iss ?? null) !== $issuer
            || $audiences !== [$clientId]
            || (property_exists($claims, 'azp') && $claims->azp !== $clientId)
            || ! is_int($claims->exp ?? null) || ! is_int($claims->iat ?? null)
            || ! is_string($claims->nonce ?? null) || ! hash_equals($nonce, $claims->nonce)
            || ! self::validSubject($subject)
            || ($claims->email_verified ?? false) !== true
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('Keycloak identity claims are invalid.');
        }

        $firstName = $this->name($claims->given_name ?? null)
            ?? $this->name($claims->name ?? null)
            ?? $email;

        return [
            'issuer' => $issuer,
            'subject' => $subject,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $this->name($claims->family_name ?? null),
        ];
    }

    public function logoutUrl(string $returnUrl): string
    {
        return $this->endpoint('logout').'?'.http_build_query([
            'client_id' => $this->setting('client_id'),
            'post_logout_redirect_uri' => $this->secureUrl($returnUrl),
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public static function validSubject(mixed $subject): bool
    {
        return is_string($subject) && preg_match('/\A[!-~]{1,255}\z/', $subject) === 1;
    }

    private function endpoint(string $name): string
    {
        return $this->issuer().'/protocol/openid-connect/'.$name;
    }

    private function setting(string $key): string
    {
        $value = config('services.keycloak.'.$key);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Keycloak client configuration is incomplete.');
        }

        return $value;
    }

    private function secureUrl(mixed $url): string
    {
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        $isLocal = app()->environment('local', 'testing')
            && in_array($parts['host'] ?? null, ['localhost', '127.0.0.1', '[::1]'], true);

        if (! $parts || ! isset($parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            || ! in_array($parts['scheme'] ?? null, $isLocal ? ['https', 'http'] : ['https'], true)
        ) {
            throw new RuntimeException('Keycloak requires valid HTTPS endpoint URLs.');
        }

        return $url;
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : null;
    }
}
