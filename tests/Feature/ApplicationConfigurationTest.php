<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationConfigurationTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_displays_the_application_name(): void
    {
        $this->withoutVite();

        $this->get('/login')
            ->assertOk()
            ->assertSee('<title>Login | Pojok Horeka Employee Info</title>', false)
            ->assertSee('<meta name="application-name" content="Pojok Horeka Employee Info">', false)
            ->assertSee('<h1 class="mb-2">Pojok Horeka Employee Info</h1>', false)
            ->assertSeeText('Masuk dengan SSO');
    }

    public function test_default_admin_email_is_normalized(): void
    {
        config(['app.default_admin_email' => '  SPRINT-ADMIN@PTAUN.COM  ']);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertSame('sprint-admin@ptaun.com', config('app.default_admin_email'));
    }

    public function test_default_admin_email_can_be_blank(): void
    {
        config(['app.default_admin_email' => '']);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertNull(config('app.default_admin_email'));
    }

    #[DataProvider('invalidAdministratorEmails')]
    public function test_invalid_default_admin_email_is_rejected(string|bool $email): void
    {
        config(['app.default_admin_email' => $email]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DEFAULT_ADMIN_EMAIL must be empty or a valid email address.');

        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    public static function invalidAdministratorEmails(): array
    {
        return [
            'malformed address' => ['missing-local-part@'],
            'non-string value' => [false],
        ];
    }
}
