<?php

use App\Models\Karyawan;
use App\Models\User;
use App\Services\Keycloak;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('keycloak:link {user : Local user UUID} {subject : Trusted Keycloak subject} {--employee= : Local employee UUID}', function (Keycloak $keycloak) {
    $subject = $this->argument('subject');
    $employeeUuid = $this->option('employee');

    if (! Keycloak::validSubject($subject)) {
        $this->error('Invalid Keycloak subject.');

        return 1;
    }

    try {
        $issuer = $keycloak->issuer();
        $linked = DB::transaction(function () use ($issuer, $subject, $employeeUuid) {
            $user = User::where('uuid', $this->argument('user'))->lockForUpdate()->first();
            if (! $user) {
                return false;
            }

            if ($user->keycloak_subject !== null && ($user->keycloak_subject !== $subject || $user->keycloak_issuer !== $issuer)) {
                return false;
            }

            if ($employeeUuid !== null) {
                $employee = Karyawan::where('uuid', $employeeUuid)->lockForUpdate()->first();
                if (! $employee || strcasecmp($employee->email, $user->email) !== 0
                    || ($employee->user_uuid !== null && $employee->user_uuid !== $user->uuid)
                    || $user->karyawan()->where('uuid', '!=', $employee->uuid)->exists()
                ) {
                    return false;
                }

                $employee->user_uuid = $user->uuid;
                $employee->save();
            }

            return $user->forceFill([
                'keycloak_issuer' => $issuer,
                'keycloak_subject' => $subject,
            ])->save();
        }, attempts: 3);
    } catch (UniqueConstraintViolationException) {
        $linked = false;
    }

    if (! $linked) {
        $this->error('The account or employee could not be linked. Check the UUIDs, bindings, and email match.');

        return 1;
    }

    $this->info('Keycloak subject linked. The first sign-in requires a verified matching email.');

    return 0;
})->purpose('Explicitly bind an existing user to a trusted Keycloak subject');
