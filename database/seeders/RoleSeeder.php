<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdmin = Role::findOrCreate('super admin', 'web');
        $admin = Role::findOrCreate('admin', 'web');
        $user = Role::findOrCreate('user', 'web');

        // Ambil semua permission
        $permissions = Permission::all();

        // Berikan semua permission ke super admin dan admin
        $superAdmin->givePermissionTo($permissions);
        $admin->givePermissionTo($permissions);

        // Jika ingin user hanya punya dashboard
        $user->givePermissionTo(['karyawan.id card']);
    }
}
