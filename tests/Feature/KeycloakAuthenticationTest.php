<?php

namespace Tests\Feature;

use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Posisi;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KeycloakAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://sso.example.test/realms/company-dev';

    private const ENDPOINT = self::ISSUER.'/protocol/openid-connect/';

    private const REDIRECT_URI = 'https://employees.example.test/auth/keycloak/callback';

    private const ADMIN_EMAIL = 'admin@example.test';

    private static OpenSSLAsymmetricKey $privateKey;

    private string $idToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'app.url' => 'https://employees.example.test',
            'app.default_admin_email' => self::ADMIN_EMAIL,
            'services.keycloak' => [
                'base_url' => 'https://sso.example.test',
                'realm' => 'company-dev',
                'client_id' => 'employee-info',
                'client_secret' => 'test-client-secret',
                'redirect_uri' => self::REDIRECT_URI,
            ],
        ]);

        self::$privateKey ??= $this->generateSigningKey();
        $rsa = openssl_pkey_get_details(self::$privateKey)['rsa'];

        Http::preventStrayRequests();
        Http::fake([
            self::ENDPOINT.'token' => fn () => Http::response([
                'id_token' => $this->idToken,
                'access_token' => 'discarded-access-token',
                'refresh_token' => 'discarded-refresh-token',
            ]),
            self::ENDPOINT.'certs' => Http::response(['keys' => [[
                'kid' => 'signing-key',
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => JWT::urlsafeB64Encode($rsa['n']),
                'e' => JWT::urlsafeB64Encode($rsa['e']),
            ]]]),
        ]);
    }

    public function test_verified_default_admin_is_provisioned_and_logs_out_of_sso(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $query = $this->startSso();
        $initialSessionId = session()->getId();
        $verifier = session('keycloak.challenge.verifier');

        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $verifier, true)), $query['code_challenge']);
        $this->assertArrayNotHasKey('client_secret', $query);

        $callback = $this->completeSso($query, ['email' => strtoupper(self::ADMIN_EMAIL)]);
        $callback->assertRedirect(route('dashboard'))->assertHeader('Referrer-Policy', 'no-referrer');
        $callback->assertSessionMissing('keycloak.challenge');
        $this->assertNotSame($initialSessionId, session()->getId());

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::ADMIN_EMAIL, $user->email);
        $this->assertSame('Alex', $user->nama_depan);
        $this->assertSame('Employee', $user->nama_belakang);
        $this->assertSame('subject-1', $user->keycloak_subject);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame([self::ISSUER, 'subject-1'], session('keycloak.identity'));
        $this->assertStringNotContainsString($this->idToken, json_encode(session()->all()));
        $this->assertStringNotContainsString('discarded-access-token', json_encode(session()->all()));
        $this->get(route('dashboard'))->assertOk()->assertSeeText('Pojok Horeka Employee Info');
        $this->get(route('user.index'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT.'token'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'test-client-secret'
            && $request['code_verifier'] === $verifier
            && $request['redirect_uri'] === self::REDIRECT_URI);

        $logout = $this->post(route('logout'));
        $logout->assertRedirect(self::ENDPOINT.'logout?client_id=employee-info&post_logout_redirect_uri=https%3A%2F%2Femployees.example.test%2Flogin');
        $logout->assertSessionMissing('keycloak.identity');
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get('/auth/google')->assertNotFound();
    }

    public function test_unapproved_accounts_cannot_sign_in_until_explicitly_authorized(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->completeSso($this->startSso(), ['realm_access' => ['roles' => ['admin']]])->assertRedirect(route('login'));
        $this->assertGuest();
        $user = User::sole();
        $this->assertFalse($user->hasApplicationAccess());

        $user->givePermissionTo(['dashboard.view', 'user.view']);
        $this->completeSso($this->startSso(), ['given_name' => 'Updated', 'email' => 'updated@example.test'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('updated@example.test', $user->fresh()->email);
        $this->assertSame('Updated', $user->fresh()->nama_depan);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('user.index'))->assertForbidden();
        $this->get(route('karyawan.index'))->assertForbidden();

        $user->syncPermissions([]);
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_legacy_account_requires_explicit_link_and_a_matching_email_on_first_login(): void
    {
        $existing = User::create([
            'slug' => 'legacy-admin',
            'nama_depan' => 'Legacy Admin',
            'email' => strtoupper(self::ADMIN_EMAIL),
            'google_id' => 'legacy-identity',
            'password' => 'test-password',
        ]);

        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($existing->fresh()->keycloak_subject);

        $this->artisan('keycloak:link', ['user' => $existing->uuid, 'subject' => 'subject-1'])->assertSuccessful();
        $this->completeSso($this->startSso())->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($existing->fresh()->keycloak_verified_at);

        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($existing);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('legacy-identity', $existing->fresh()->google_id);

        $this->post(route('logout'));
        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL, 'sub' => 'different-subject'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_inactive_users_cannot_sign_in_and_deactivation_invalidates_existing_access(): void
    {
        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('home'));
        $user = User::sole();
        $user->forceFill(['is_active' => false])->save();

        Auth::forgetGuards();
        $this->get(route('user.index'))->assertForbidden();
        $this->assertGuest();

        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_default_admin_grant_is_revoked_when_the_configured_email_changes(): void
    {
        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('home'));
        $user = User::sole();

        config(['app.default_admin_email' => 'another-admin@example.test']);
        Auth::forgetGuards();
        $this->get(route('user.index'))->assertForbidden();
        $this->assertGuest();

        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_employee_can_open_only_their_linked_id_card(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->completeSso($this->startSso())->assertRedirect(route('login'));
        $user = User::sole();
        $user->assignRole('user');

        $position = $this->employeePosition();
        $ownEmployee = Karyawan::create(['nip' => '0001', 'uuid_posisi' => $position->uuid, 'nama_depan' => 'Own Card', 'email' => $user->email]);
        $otherEmployee = Karyawan::create(['nip' => '0002', 'uuid_posisi' => $position->uuid, 'nama_depan' => 'Other Card', 'email' => 'other@example.test']);
        $link = ['user' => $user->uuid, 'subject' => $user->keycloak_subject];

        $this->artisan('keycloak:link', [...$link, '--employee' => $otherEmployee->uuid])->assertFailed();
        $this->artisan('keycloak:link', [...$link, '--employee' => $ownEmployee->uuid])->assertSuccessful();
        $this->assertNull($otherEmployee->fresh()->user_uuid);

        $this->completeSso($this->startSso())->assertRedirect(route('home'));
        $this->get(route('home'))->assertRedirect(route('karyawan.idCard', 'own-card'));
        $this->get(route('karyawan.idCard', 'own-card'))->assertOk();
        $this->get(route('karyawan.idCard', 'other-card'))->assertNotFound();
    }

    public function test_inactive_employee_email_cannot_bootstrap_an_administrator(): void
    {
        Karyawan::create([
            'nip' => '0001', 'uuid_posisi' => $this->employeePosition()->uuid,
            'nama_depan' => 'Inactive', 'email' => self::ADMIN_EMAIL, 'status' => false,
        ]);

        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_google_or_local_sessions_cannot_access_administration(): void
    {
        $user = User::create(['slug' => 'legacy', 'nama_depan' => 'Legacy', 'email' => self::ADMIN_EMAIL, 'password' => 'test-password']);
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('user.index'))->assertForbidden();
        $this->assertGuest();
        $this->get(route('karyawan.idCard', 'another-employee'))->assertRedirect(route('login'));
    }

    public function test_state_is_checked_before_token_exchange_and_consumed(): void
    {
        $query = $this->startSso();
        $this->completeSso($query, state: 'wrong-state')->assertRedirect(route('login'));
        $this->completeSso($query)->assertRedirect(route('login'));

        Http::assertNothingSent();
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_tokens_cannot_provision_an_admin(array $claims, bool $tamperSignature = false): void
    {
        $this->completeSso($this->startSso(), ['email' => self::ADMIN_EMAIL, ...$claims], tamperSignature: $tamperSignature)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Login SSO gagal diverifikasi.');

        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://another.example.test']],
            'audience' => [['aud' => 'another-client']],
            'untrusted extra audience' => [['aud' => ['employee-info', 'another-client'], 'azp' => 'employee-info']],
            'authorized party' => [['azp' => 'another-client']],
            'expiration' => [['exp' => 1]],
            'missing expiration' => [['exp' => null]],
            'nonce' => [['nonce' => 'wrong-nonce']],
            'unverified email' => [['email_verified' => false]],
            'invalid signature' => [[], true],
        ];
    }

    private function startSso(): array
    {
        $response = $this->get(route('keycloak.redirect'))->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ENDPOINT.'auth?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function employeePosition(): Posisi
    {
        $department = Departemen::create(['kode_departemen' => 'OPS', 'departemen' => 'Operations']);
        $division = $department->divisis()->create(['kode_divisi' => 'FIELD', 'nama_divisi' => 'Field']);

        return $division->posisis()->create(['kode_posisi' => 'STAFF', 'nama_posisi' => 'Staff']);
    }

    private function generateSigningKey(): OpenSSLAsymmetricKey
    {
        $config = tempnam(sys_get_temp_dir(), 'keycloak-test-');

        try {
            $key = openssl_pkey_new([
                'config' => $config,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ]);
            $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

            return $key;
        } finally {
            unlink($config);
        }
    }

    private function completeSso(array $query, array $claims = [], ?string $state = null, bool $tamperSignature = false): TestResponse
    {
        $this->idToken = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => 'employee-info',
            'iat' => time() - 5,
            'exp' => time() + 60,
            'nonce' => $query['nonce'],
            'sub' => 'subject-1',
            'email' => 'employee@example.test',
            'email_verified' => true,
            'given_name' => 'Alex',
            'family_name' => 'Employee',
            ...$claims,
        ], self::$privateKey, 'RS256', 'signing-key');

        if ($tamperSignature) {
            $signatureStart = strrpos($this->idToken, '.') + 1;
            $this->idToken[$signatureStart] = $this->idToken[$signatureStart] === 'A' ? 'B' : 'A';
        }

        return $this->get(route('keycloak.callback', ['state' => $state ?? $query['state'], 'code' => 'test-code', 'iss' => self::ISSUER]));
    }
}
