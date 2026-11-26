<?php

namespace App\Services;

use App\Models\Karyawan;
use App\Models\Posisi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class EmployeeOrganization
{
    public function resolveImportPosition(array $row, ?Karyawan $employee): Posisi
    {
        $department = $row['departemen'];
        $division = $row['divisi'];
        $position = $row['posisi'];

        if ($employee && $department === '' && $division === '') {
            $current = $employee->posisi;
            if ($position === '' || strcasecmp($position, $current->kode_posisi) === 0 || strcasecmp($position, $current->nama_posisi) === 0) {
                return $current;
            }
        }

        if ($position === '') {
            throw ValidationException::withMessages(['posisi' => 'Posisi wajib diisi.']);
        }

        $query = Posisi::with('divisi.departemen');
        $this->matchNameOrCode($query, 'nama_posisi', 'kode_posisi', $position);
        if ($division !== '') {
            $query->whereHas('divisi', fn (Builder $divisions) => $this->matchNameOrCode($divisions, 'nama_divisi', 'kode_divisi', $division));
        }
        if ($department !== '') {
            $query->whereHas('divisi.departemen', fn (Builder $departments) => $this->matchNameOrCode($departments, 'departemen', 'kode_departemen', $department));
        }

        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'posisi' => 'Posisi tidak ditemukan atau tidak unik. Gunakan kode departemen, divisi, dan posisi yang sesuai.',
            ]);
        }

        $selected = $matches->first();
        if ($selected->uuid !== $employee?->uuid_posisi && ! $selected->hasActiveHierarchy()) {
            throw ValidationException::withMessages(['posisi' => 'Pilih posisi, divisi, dan departemen yang aktif.']);
        }

        return $selected;
    }

    private function matchNameOrCode(Builder $query, string $name, string $code, string $value): void
    {
        $value = mb_strtolower($value);
        $query->where(fn (Builder $items) => $items->whereRaw("LOWER({$name}) = ?", [$value])
            ->orWhereRaw("LOWER({$code}) = ?", [$value]));
    }
}
