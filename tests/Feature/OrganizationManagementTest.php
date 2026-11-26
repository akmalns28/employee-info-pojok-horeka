<?php

namespace Tests\Feature;

use App\Imports\KaryawanImport;
use App\Imports\KaryawanImportValidator;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Karyawan;
use App\Models\Posisi;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.keycloak.base_url' => 'https://sso.example.test', 'services.keycloak.realm' => 'company-dev']);
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    public function test_admin_manages_departments_divisions_and_positions_with_scoped_codes(): void
    {
        $this->signIn('admin');
        $departmentData = ['kode_departemen' => 'ops', 'departemen' => 'Operations', 'is_active' => true];
        $this->postJson(route('departemen.store'), $departmentData)->assertCreated();
        $this->postJson(route('departemen.store'), $departmentData)->assertUnprocessable()->assertJsonValidationErrors('kode_departemen');
        $department = Departemen::sole();
        $otherDepartment = Departemen::create(['kode_departemen' => 'FIN', 'departemen' => 'Finance']);

        $divisionData = ['uuid_departemen' => $department->uuid, 'kode_divisi' => 'field', 'nama_divisi' => 'Field', 'is_active' => true];
        $this->postJson(route('divisi.store'), $divisionData)->assertCreated();
        $division = $department->divisis()->sole();
        $this->postJson(route('divisi.store'), $divisionData)->assertUnprocessable()->assertJsonValidationErrors('kode_divisi');
        $this->postJson(route('divisi.store'), [...$divisionData, 'uuid_departemen' => $otherDepartment->uuid])->assertCreated();
        $otherDivision = $otherDepartment->divisis()->sole();

        $positionData = ['divisi_uuid' => $division->uuid, 'kode_posisi' => 'staff', 'nama_posisi' => 'Staff', 'is_active' => true];
        $this->postJson(route('posisi.store'), $positionData)->assertCreated();
        $position = $division->posisis()->sole();
        $this->postJson(route('posisi.store'), $positionData)->assertUnprocessable()->assertJsonValidationErrors('kode_posisi');
        $this->postJson(route('posisi.store'), [...$positionData, 'divisi_uuid' => $otherDivision->uuid])->assertCreated();
        $otherPosition = $otherDivision->posisis()->sole();

        $this->putJson(route('departemen.update', $department->uuid), [...$departmentData, 'departemen' => 'Operations Updated'])->assertOk();
        $this->putJson(route('divisi.update', $division->uuid), [...$divisionData, 'nama_divisi' => 'Field Updated'])->assertOk();
        $this->putJson(route('posisi.update', $position->uuid), [...$positionData, 'is_active' => false])->assertOk();

        $this->get(route('departemen.index'))->assertOk()->assertSeeText('Operations Updated');
        $this->get(route('divisi.index', ['uuid_departemen' => $department->uuid]))->assertOk()->assertSeeText('Field Updated');
        $this->get(route('posisi.index', ['status' => '0', 'search' => 'staff']))->assertOk()->assertViewHas(
            'records', fn ($records) => $records->total() === 1 && $records->first()->is($position)
        );
        $this->get(route('posisi.edit', $position->uuid))->assertOk()->assertSeeText('Nonaktif');
        $this->getJson(route('divisi.edit', $division->uuid))->assertOk()->assertJsonPath('uuid_departemen', $department->uuid);

        $this->deleteJson(route('departemen.destroy', $department->uuid))->assertUnprocessable();
        $this->deleteJson(route('divisi.destroy', $division->uuid))->assertUnprocessable();
        $this->deleteJson(route('posisi.destroy', $otherPosition->uuid))->assertOk();
        $this->deleteJson(route('divisi.destroy', $otherDivision->uuid))->assertOk();
        $this->deleteJson(route('departemen.destroy', $otherDepartment->uuid))->assertOk();
    }

    public function test_employee_crud_validates_hierarchy_and_preserves_linked_user_on_deactivation(): void
    {
        $this->signIn('admin');
        Storage::fake('public');
        $position = $this->position('OPS');
        $otherPosition = $this->position('FIN');
        $user = $this->user('employee@example.test');
        $data = $this->employeeData($position, ['user_uuid' => $user->uuid]);

        $this->postJson(route('karyawan.store'), [...$data, 'departemen_uuid' => $otherPosition->divisi->uuid_departemen])
            ->assertUnprocessable()->assertJsonValidationErrors('uuid_posisi');
        $this->postJson(route('karyawan.store'), [...$data, 'divisi_uuid' => $otherPosition->divisi_uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('uuid_posisi');
        $this->postJson(route('karyawan.store'), [...$data, 'nip' => 123])
            ->assertUnprocessable()->assertJsonValidationErrors('nip');

        $this->postJson(route('karyawan.store'), $data)->assertCreated();
        $employee = Karyawan::sole();
        $this->assertSame('000123', $employee->nip);
        $this->assertTrue($employee->user->is($user));
        $this->assertTrue($employee->posisi->divisi->departemen->is($position->divisi->departemen));
        Storage::disk('public')->assertExists($employee->qr_code);
        $this->get(route('karyawan.index'))->assertOk()->assertSee('id="new_departemen_uuid"', false);
        $this->getJson(route('karyawan.show', '000123'))->assertOk()->assertJsonPath('departemen', 'OPS Department');
        $this->getJson(route('karyawan.edit', $employee->uuid))->assertOk()->assertJsonPath('departemen_uuid', $position->divisi->uuid_departemen);
        $this->deleteJson(route('posisi.destroy', $position->uuid))->assertUnprocessable();
        $this->deleteJson(route('user.destroy', $user->uuid))->assertUnprocessable();

        $this->putJson(route('karyawan.update', $employee->uuid), $this->employeeData($otherPosition, [
            'status' => false,
            'nama_depan' => 'Updated Employee',
        ]))->assertOk();
        $employee->refresh();
        $this->assertSame($otherPosition->divisi_uuid, $employee->divisi_uuid);
        $this->assertFalse($employee->status);
        $this->assertTrue($employee->user->is($user));
        $this->assertFalse($user->fresh()->hasActiveAccount());
        $this->get(route('karyawan.idCard', ['nama_lengkap' => 'updated-employee', 'employee' => $employee->uuid]))->assertOk();

        $this->deleteJson(route('karyawan.destroy', $employee->uuid))->assertUnprocessable()->assertJsonValidationErrors('user_uuid');
        $this->putJson(route('karyawan.update', $employee->uuid), $this->employeeData($otherPosition, ['user_uuid' => null]))->assertOk();
        $this->deleteJson(route('karyawan.destroy', $employee->uuid))->assertOk();
        $this->assertDatabaseEmpty('karyawans');
        $this->assertDatabaseHas('users', ['uuid' => $user->uuid]);
    }

    public function test_employee_filters_search_and_paginate(): void
    {
        $this->signIn('admin');
        $position = $this->position('OPS');
        $otherPosition = $this->position('FIN');
        foreach (range(1, 12) as $number) {
            Karyawan::create([
                'uuid_posisi' => $position->uuid, 'nip' => sprintf('%06d', $number),
                'nama_depan' => 'Field Employee '.$number, 'email' => "field{$number}@example.test", 'status' => false,
            ]);
        }
        Karyawan::create([
            'uuid_posisi' => $otherPosition->uuid, 'nip' => 'FIN-1', 'nama_depan' => 'Excluded Employee',
            'email' => 'excluded@example.test', 'status' => true,
        ]);

        $this->get(route('karyawan.index', [
            'departemen_uuid' => $position->divisi->uuid_departemen, 'divisi_uuid' => $position->divisi_uuid,
            'uuid_posisi' => $position->uuid, 'status' => '0', 'search' => 'Field', 'page' => '2',
        ]))->assertOk()->assertViewHas('karyawans', fn ($records) => $records->total() === 12 && $records->count() === 2)
            ->assertDontSeeText('Excluded Employee');
    }

    public function test_database_restricts_invalid_hierarchy_and_updates_derived_division(): void
    {
        $position = $this->position('OPS');
        $otherPosition = $this->position('FIN');
        $employee = Karyawan::create([
            'uuid_posisi' => $position->uuid, 'nip' => '000123', 'nama_depan' => 'Employee', 'email' => 'employee@example.test',
        ]);

        $this->assertSame($position->divisi_uuid, $employee->divisi_uuid);
        $position->update(['divisi_uuid' => $otherPosition->divisi_uuid, 'kode_posisi' => 'MOVED']);
        $this->assertSame($otherPosition->divisi_uuid, $employee->fresh()->divisi_uuid);

        $this->expectException(QueryException::class);
        DB::table('karyawans')->where('id', $employee->id)->update(['divisi_uuid' => $this->position('HR')->divisi_uuid]);
    }

    public function test_only_admins_manage_master_data_and_employee_records(): void
    {
        $user = $this->signIn('user');
        $user->givePermissionTo(['departemen.view', 'posisi.view', 'karyawan.view', 'karyawan.create', 'karyawan.edit', 'karyawan.delete', 'karyawan.import']);

        foreach (['departemen', 'divisi', 'posisi'] as $resource) {
            $this->get(route($resource.'.index'))->assertForbidden();
            $this->postJson(route($resource.'.store'), [])->assertForbidden();
        }
        $this->postJson(route('karyawan.store'), [])->assertForbidden();
        $this->deleteJson(route('karyawan.bulkDestroy'), [])->assertForbidden();
        $this->postJson(route('karyawan.import.test'), [])->assertForbidden();
        $this->get(route('karyawan.index'))->assertOk()->assertDontSee('id="newEmployeeForm"', false);
    }

    public function test_inactive_hierarchy_cannot_be_assigned_and_import_requires_an_unambiguous_position(): void
    {
        $this->signIn('admin');
        Storage::fake('public');
        $position = $this->position('OPS');
        $otherPosition = $this->position('FIN');
        $otherPosition->divisi->update(['is_active' => false]);
        $this->postJson(route('karyawan.store'), $this->employeeData($otherPosition))
            ->assertUnprocessable()->assertJsonValidationErrors('uuid_posisi');

        $row = collect([
            'nip' => '000005', 'nama_depan' => 'Imported', 'email' => 'imported@example.test', 'no_hp' => '08123456789',
            'divisi' => 'FIELD', 'posisi' => 'STAFF',
        ]);
        $preview = new KaryawanImportValidator;
        $preview->collection(collect([$row]));
        $this->assertFalse($preview->isValid());

        $row->put('departemen', 'OPS');
        $preview = new KaryawanImportValidator;
        $preview->collection(collect([$row]));
        $this->assertTrue($preview->isValid());

        $import = new KaryawanImport;
        $import->collection(collect([$row]));
        $this->assertSame(1, $import->insertCount);
        $this->assertDatabaseHas('karyawans', ['nip' => '000005', 'uuid_posisi' => $position->uuid, 'divisi_uuid' => $position->divisi_uuid]);
    }

    private function position(string $departmentCode): Posisi
    {
        $department = Departemen::create(['kode_departemen' => $departmentCode, 'departemen' => $departmentCode.' Department']);
        $division = $department->divisis()->create(['kode_divisi' => 'FIELD', 'nama_divisi' => 'Field']);

        return $division->posisis()->create(['kode_posisi' => 'STAFF', 'nama_posisi' => 'Staff']);
    }

    private function employeeData(Posisi $position, array $overrides = []): array
    {
        return [
            'departemen_uuid' => $position->divisi->uuid_departemen, 'divisi_uuid' => $position->divisi_uuid,
            'uuid_posisi' => $position->uuid, 'nip' => '000123', 'nama_depan' => 'Employee',
            'email' => 'employee@example.test', 'no_hp' => '08123456789', 'status' => true,
            ...$overrides,
        ];
    }

    private function signIn(string $role): User
    {
        $issuer = 'https://sso.example.test/realms/company-dev';
        $user = $this->user('manager@example.test');
        $user->forceFill(['keycloak_issuer' => $issuer, 'keycloak_subject' => 'manager', 'keycloak_verified_at' => now()])->save();
        $user->assignRole($role);
        $user = $user->fresh();
        $this->actingAs($user)->withSession(['keycloak.identity' => [$issuer, 'manager']]);

        return $user;
    }

    private function user(string $email): User
    {
        return User::create(['slug' => 'sprint-user', 'nama_depan' => 'Sprint User', 'email' => $email, 'password' => 'test-password']);
    }
}
