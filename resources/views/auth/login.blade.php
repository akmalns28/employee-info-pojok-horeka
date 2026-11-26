<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Login | {{ config('app.name') }}</title>
    @include('layouts.partials.branding-meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <h1 class="mb-2">{{ config('app.name') }}</h1>
            </div>
            <div class="card card-md">
                <div class="card-body">
                    <div class="mb-4 text-center">
                        <h2>Login Akunmu</h2>
                        <p>Silahkan Masuk Dengan Email Perusahaan</p>
                    </div>
                    @if (session('error'))
                        <div class="alert alert-important alert-danger alert-dismissible" role="alert">
                            <div class="d-flex gap-2">
                                <div>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        class="icon icon-tabler icons-tabler-outline icon-tabler-alert-square-rounded">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path
                                            d="M12 3c7.2 0 9 1.8 9 9c0 7.2 -1.8 9 -9 9c-7.2 0 -9 -1.8 -9 -9c0 -7.2 1.8 -9 9 -9" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg>
                                </div>
                                <p class="m-0">
                                    {{ session('error') }}
                                </p>
                            </div>
                        </div>
                    @endif
                    <div class="col">
                        <a href="{{ route('keycloak.redirect') }}" class="btn btn-primary w-100">
                            Masuk dengan SSO
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
