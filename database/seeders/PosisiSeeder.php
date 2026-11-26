<?php

namespace Database\Seeders;

use App\Models\Divisi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PosisiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [['kode_posisi' => 'STAFF', 'nama_posisi' => 'Staff'], ['kode_posisi' => 'MGR', 'nama_posisi' => 'Manager'], ['kode_posisi' => 'AST', 'nama_posisi' => 'Assisten'], ['kode_posisi' => 'LEAD', 'nama_posisi' => 'Leader'], ['kode_posisi' => 'DIR', 'nama_posisi' => 'Direktur'], ['kode_posisi' => 'SPV', 'nama_posisi' => 'SPV']];

        foreach (Divisi::where('is_active', true)->lazyById() as $division) {
            foreach ($data as $item) {
                $division->posisis()->firstOrCreate(['kode_posisi' => $item['kode_posisi']], [
                    'uuid' => (string) Str::uuid(),
                    'nama_posisi' => $item['nama_posisi'],
                ]);
            }
        }
    }
}
