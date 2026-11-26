<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private ?string $unassignedDivision = null;

    public function up(): void
    {
        Schema::table('departemens', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        Schema::table('divisis', function (Blueprint $table) {
            $table->string('kode_divisi', 20)->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::table('posisis', function (Blueprint $table) {
            $table->uuid('divisi_uuid')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dropUnique(['kode_posisi']);
        });
        Schema::table('karyawans', function (Blueprint $table) {
            $table->uuid('legacy_user_uuid')->nullable();
        });

        DB::transaction(function () {
            $this->backfillOrganization();
            $this->backfillEmployees();
        });

        Schema::table('departemens', function (Blueprint $table) {
            $table->unique('kode_departemen');
        });
        Schema::table('divisis', function (Blueprint $table) {
            $table->dropForeign(['uuid_departemen']);
            $table->foreign('uuid_departemen')->references('uuid')->on('departemens')->restrictOnDelete();
            $table->string('kode_divisi', 20)->change();
            $table->unique(['uuid_departemen', 'kode_divisi']);
        });
        Schema::table('posisis', function (Blueprint $table) {
            $table->uuid('divisi_uuid')->change();
            $table->foreign('divisi_uuid')->references('uuid')->on('divisis')->restrictOnDelete();
            $table->unique(['divisi_uuid', 'kode_posisi']);
            $table->unique(['uuid', 'divisi_uuid']);
        });
        Schema::table('karyawans', function (Blueprint $table) {
            $table->dropForeign(['uuid_posisi']);
            $table->dropForeign(['divisi_uuid']);
            $table->dropForeign(['user_uuid']);
        });
        Schema::table('karyawans', function (Blueprint $table) {
            $table->uuid('uuid_posisi')->change();
            $table->uuid('divisi_uuid')->change();
            $table->string('nip')->change();
            $table->unique('user_uuid');
            $table->index(['uuid_posisi', 'divisi_uuid'], 'employees_position_division_index');
            $table->foreign(['uuid_posisi', 'divisi_uuid'], 'employees_position_division_foreign')
                ->references(['uuid', 'divisi_uuid'])->on('posisis')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('divisi_uuid')->references('uuid')->on('divisis')->restrictOnDelete();
            $table->foreign('user_uuid')->references('uuid')->on('users')->restrictOnDelete();
            $table->index('status');
        });
    }

    private function backfillOrganization(): void
    {
        $departmentCodes = [];
        foreach (DB::table('departemens')->select('id', 'kode_departemen')->lazyById() as $department) {
            $code = strtoupper(trim($department->kode_departemen));
            $earlierDuplicate = DB::table('departemens')->where('id', '<', $department->id)
                ->where('kode_departemen', $department->kode_departemen)->exists();
            if ($code === '' || isset($departmentCodes[$code]) || $earlierDuplicate) {
                $code = $this->availableValue('departemens', 'kode_departemen', 'LEGACY-DPT-'.$department->id);
                DB::table('departemens')->where('id', $department->id)->update(['kode_departemen' => $code]);
            }
            $departmentCodes[$code] = true;
        }

        foreach (DB::table('divisis')->select('id')->lazyById() as $division) {
            DB::table('divisis')->where('id', $division->id)->update(['kode_divisi' => 'DIV-'.$division->id]);
        }

        foreach (DB::table('posisis')->whereNull('divisi_uuid')->lazyById() as $position) {
            $divisions = DB::table('karyawans')->where('uuid_posisi', $position->uuid)
                ->distinct()->pluck('divisi_uuid')->sortBy(fn ($uuid) => $uuid ?? '~')->values();

            foreach ($divisions->isEmpty() ? [null] : $divisions as $index => $oldDivision) {
                $division = $oldDivision ?? $this->unassignedDivision();
                $positionUuid = $position->uuid;
                if ($index === 0) {
                    DB::table('posisis')->where('id', $position->id)->update(['divisi_uuid' => $division]);
                } else {
                    $positionUuid = (string) Str::uuid();
                    DB::table('posisis')->insert([
                        'uuid' => $positionUuid,
                        'divisi_uuid' => $division,
                        'kode_posisi' => $position->kode_posisi,
                        'nama_posisi' => $position->nama_posisi,
                        'created_at' => $position->created_at,
                        'updated_at' => $position->updated_at,
                    ]);
                }

                DB::table('karyawans')->where('uuid_posisi', $position->uuid)->where('divisi_uuid', $oldDivision)
                    ->update(['uuid_posisi' => $positionUuid, 'divisi_uuid' => $division]);
            }
        }

        $divisionsWithoutPosition = DB::table('karyawans')->whereNull('uuid_posisi')->distinct()->pluck('divisi_uuid');
        foreach ($divisionsWithoutPosition as $oldDivision) {
            $division = $oldDivision ?? $this->unassignedDivision();
            $position = (string) Str::uuid();
            $code = $this->availableValue('posisis', 'kode_posisi', 'UNASSIGNED', $division);
            DB::table('posisis')->insert([
                'uuid' => $position,
                'divisi_uuid' => $division,
                'kode_posisi' => $code,
                'nama_posisi' => 'Belum Ditentukan',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('karyawans')->whereNull('uuid_posisi')->where('divisi_uuid', $oldDivision)
                ->update(['uuid_posisi' => $position, 'divisi_uuid' => $division]);
        }
    }

    private function backfillEmployees(): void
    {
        foreach (DB::table('karyawans')->whereRaw("TRIM(COALESCE(nip, '')) = ''")->select('id')->lazyById() as $employee) {
            DB::table('karyawans')->where('id', $employee->id)->update([
                'nip' => $this->availableValue('karyawans', 'nip', 'LEGACY-'.$employee->id),
            ]);
        }

        $duplicates = DB::table('karyawans')->whereNotNull('user_uuid')
            ->groupBy('user_uuid')->havingRaw('COUNT(*) > 1')->pluck('user_uuid');
        foreach ($duplicates as $userUuid) {
            $email = DB::table('users')->where('uuid', $userUuid)->value('email');
            $employees = DB::table('karyawans')->where('user_uuid', $userUuid);
            $keepId = (clone $employees)->orderByRaw('CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END', [strtolower($email)])
                ->orderBy('id')->value('id');
            $employees->where('id', '!=', $keepId)->update(['user_uuid' => null, 'legacy_user_uuid' => $userUuid]);
        }
    }

    private function unassignedDivision(): string
    {
        if ($this->unassignedDivision !== null) {
            return $this->unassignedDivision;
        }

        $department = (string) Str::uuid();
        DB::table('departemens')->insert([
            'uuid' => $department,
            'kode_departemen' => $this->availableValue('departemens', 'kode_departemen', 'UNASSIGNED'),
            'departemen' => 'Belum Ditentukan',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->unassignedDivision = (string) Str::uuid();
        DB::table('divisis')->insert([
            'uuid' => $this->unassignedDivision,
            'uuid_departemen' => $department,
            'kode_divisi' => 'UNASSIGNED',
            'nama_divisi' => 'Belum Ditentukan',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->unassignedDivision;
    }

    private function availableValue(string $table, string $column, string $base, ?string $division = null): string
    {
        $query = DB::table($table);
        if ($division !== null) {
            $query->where('divisi_uuid', $division);
        }

        $value = $base;
        for ($suffix = 2; (clone $query)->whereRaw("UPPER({$column}) = ?", [$value])->exists(); $suffix++) {
            $value = $base.'-'.$suffix;
        }

        return $value;
    }

    public function down(): void
    {
        throw new LogicException('Reversing scoped positions would discard organization assignments.');
    }
};
