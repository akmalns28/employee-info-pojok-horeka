<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Keycloak SSO

Configure the `KEYCLOAK_*` settings from `.env.example` in the deployment environment.
Use a confidential OpenID Connect client with Standard Flow, RS256 ID tokens,
and PKCE S256. Register `KEYCLOAK_REDIRECT_URI` as the exact callback URL
(`/auth/keycloak/callback`) and the application's `APP_URL` plus `/login` as the
post-logout redirect. Use HTTPS with `SESSION_SECURE_COOKIE=true` and
`APP_DEBUG=false` in production. The ID token must identify this client as
its sole audience and include the verified email claim. Apply the users
migration before deploying SSO and seed roles and permissions on a fresh database.

Set `DEFAULT_ADMIN_EMAIL` to a verified realm email to bootstrap administrator
access. If that email already belongs to a local account, link its existing UUID
to the subject confirmed in Keycloak before signing in:

```sh
php artisan keycloak:link <local-user-uuid> <keycloak-subject>
```

The first sign-in to a prelinked account must also match its verified email.
New non-admin accounts can request access by signing in once. An application
administrator then assigns a role or permission in the User screen. The same
screen can deactivate an account. To give an authorized employee access to
their own ID card, add `--employee=<local-employee-uuid>` to the link command.
This requires matching emails and preserves existing employee links.

Keycloak may ask users to confirm SSO logout.
[Keycloak logout documentation](https://www.keycloak.org/securing-apps/oidc-layers)

## Organization and employees

The organization migration retains existing UUIDs, employee numbers (`nip`),
and profile fields. Pause organization and employee writes while applying it
so the previous application version cannot add records during backfill.
A legacy position used across divisions keeps its original
UUID in one division and gets a copy in each other division that used it.
Unassigned records stay visible under inactive **Belum Ditentukan** entries
until an administrator assigns them. Missing employee numbers receive a
`LEGACY-` identifier. Duplicate user links keep the email-matching employee
where possible. The other rows retain the previous UUID in `legacy_user_uuid`.
The migration runs forward only because reversing scoped positions can lose
organization assignments.

Employee spreadsheet imports accept names or codes in the `posisi` and `divisi`
columns. Add a `departemen` column when those values match more than one
hierarchy. Format spreadsheet NIP cells as text to preserve leading zeros.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
