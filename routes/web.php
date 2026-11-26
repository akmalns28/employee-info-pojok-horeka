<?php

use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\DepartemenController;
use App\Http\Controllers\DivisiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KaryawanController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\PosisiController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('login', [KeycloakController::class, 'login'])->name('login');
Route::get('auth/keycloak', [KeycloakController::class, 'redirect'])->name('keycloak.redirect');
Route::get('auth/keycloak/callback', [KeycloakController::class, 'callback'])->name('keycloak.callback');
Route::post('logout', [KeycloakController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'keycloak.access'])->group(function () {
    Route::get('/', function (Request $request) {
        $user = $request->user();
        if (! $user->can('dashboard.view')) {
            if ($user->can('karyawan.id card') && $user->karyawan) {
                return redirect()->route('karyawan.idCard', ['nama_lengkap' => $user->karyawan->slug_nama]);
            }

            if ($user->can('karyawan.view')) {
                return redirect()->route('karyawan.index');
            }
        }

        return redirect()->route('dashboard');
    })->name('home');

    Route::get('dashboard', [HomeController::class, 'dashboard'])->name('dashboard');
    Route::get('/id-card/{nama_lengkap}', [KaryawanController::class, 'idCard'])->name('karyawan.idCard');

    Route::get('lengkapi-data', [UserController::class, 'editProfile'])->name('pendaftaran.lengkapi');
    Route::post('lengkapi-data', [UserController::class, 'updateProfile'])->name('pendaftaran.update');

    Route::resource('karyawan', KaryawanController::class)->only(['index', 'show']);
    Route::post('/karyawan/export-qrcode', [KaryawanController::class, 'exportQrCode'])->name('karyawan.exportQrCode');
    Route::post('/karyawan/export-qrcode-zip', [KaryawanController::class, 'exportQrCodeZip'])->name('karyawan.exportQrCodeZip');

    Route::middleware('role:admin|super admin')->group(function () {
        Route::delete('/karyawan/bulk-delete', [KaryawanController::class, 'bulkDestroy'])->name('karyawan.bulkDestroy');
        Route::resource('karyawan', KaryawanController::class)->only(['store', 'edit', 'update', 'destroy']);
        Route::post('/karyawan/import', [KaryawanController::class, 'import'])->name('karyawan.import');
        Route::post('/karyawan/import/test', [KaryawanController::class, 'testImport'])->name('karyawan.import.test');

        Route::resource('user', UserController::class)->except(['show', 'create']);
        Route::post('user/get-all-user', [UserController::class, 'getAllUser'])->name('user.getAllUser');
        Route::resource('departemen', DepartemenController::class)->except(['show', 'create']);
        Route::post('departemen/get-all-departemen', [DepartemenController::class, 'getAllDepartemen'])->name('departemen.getAllDepartemen');
        Route::resource('divisi', DivisiController::class)->except(['show', 'create']);
        Route::resource('posisi', PosisiController::class)->except(['show', 'create']);
        Route::post('posisi/get-all-posisi', [PosisiController::class, 'getAllPosisi'])->name('posisi.getAllPosisi');
        Route::resource('permissions', PermissionsController::class);
        Route::post('permissions/get-all-permissions', [PermissionsController::class, 'getAllPermissions'])->name('permissions.getAllPermissions');
        Route::resource('role', RoleController::class);
        Route::post('role/get-all-role', [RoleController::class, 'getAllRole'])->name('role.getAllRole');
    });
});
