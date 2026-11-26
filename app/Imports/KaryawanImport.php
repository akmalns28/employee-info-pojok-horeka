<?php

namespace App\Imports;

use App\Models\Karyawan;
use App\Services\EmployeeOrganization;
use Carbon\Carbon;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class KaryawanImport implements ToCollection, WithHeadingRow
{
    public array $errors = [];

    public int $successCount = 0;
    public int $insertCount = 0;
    public int $updateCount = 0;

    public function collection(Collection $rows)
    {
        $organization = new EmployeeOrganization;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            try {
                $tglLahir = null;

                if (!empty($row['tgl_lahir'])) {
                    if (is_numeric($row['tgl_lahir'])) {
                        $tglLahir = Date::excelToDateTimeObject($row['tgl_lahir'])->format('Y-m-d');
                    } else {
                        $tglLahir = Carbon::parse($row['tgl_lahir'])->format('Y-m-d');
                    }
                }

                $data = [
                    'nip' => trim((string) ($row['nip'] ?? '')),
                    'nama_depan' => trim((string) ($row['nama_depan'] ?? '')),
                    'nama_belakang' => trim((string) ($row['nama_belakang'] ?? '')),
                    'email' => trim((string) ($row['email'] ?? '')),
                    'posisi' => trim((string) ($row['posisi'] ?? '')),
                    'no_hp' => trim((string) ($row['no_hp'] ?? '')),
                    'jenis_kelamin' => strtolower(trim((string) ($row['jenis_kelamin'] ?? ''))),
                    'tempat_lahir' => trim((string) ($row['tempat_lahir'] ?? '')),
                    'tgl_lahir' => $tglLahir,
                    'alamat' => trim((string) ($row['alamat'] ?? '')),
                    'status' => strtolower(trim((string) ($row['status'] ?? ''))),
                    'departemen' => trim((string) ($row['departemen'] ?? '')),
                    'divisi' => trim((string) ($row['divisi'] ?? '')),
                ];

                $validator = Validator::make($data, [
                    'nip' => 'required|string|max:50',
                    'nama_depan' => 'required|string|max:100',
                    'email' => 'required|email|max:100',
                    'nama_belakang' => 'nullable|string|max:100',
                    'posisi' => 'nullable|string|max:100',
                    'no_hp' => 'nullable|string|max:20',
                    'jenis_kelamin' => 'nullable|in:laki-laki,perempuan',
                    'tempat_lahir' => 'nullable|string|max:100',
                    'tgl_lahir' => 'nullable|date',
                    'alamat' => 'nullable|string',
                    'status' => 'nullable|in:0,1,aktif,active,nonaktif,inactive',
                    'departemen' => 'nullable|string|max:100',
                    'divisi' => 'nullable|string|max:100',
                ]);

                if ($validator->fails()) {
                    $this->errors[] = [
                        'row' => $rowNumber,
                        'messages' => $validator->errors()->all(),
                    ];
                    continue;
                }

                $karyawan = Karyawan::where('nip', $data['nip'])->first();
                $posisi = $organization->resolveImportPosition($data, $karyawan);
                if (! $karyawan && $data['no_hp'] === '') {
                    throw ValidationException::withMessages(['no_hp' => 'No HP wajib diisi untuk karyawan baru.']);
                }

                $status = 1;

                if ($data['status'] !== '') {
                    $statusValue = strtolower((string) $data['status']);

                    if (in_array($statusValue, ['0', 'nonaktif', 'inactive'])) {
                        $status = 0;
                    }
                }

                $payload = [
                    'uuid_posisi' => $posisi->uuid,
                    'nip' => $data['nip'],
                    'nama_depan' => $data['nama_depan'],
                    'nama_belakang' => $data['nama_belakang'] ?: null,
                    'email' => $data['email'],
                    'no_hp' => $data['no_hp'] !== '' ? $data['no_hp'] : $karyawan?->no_hp,
                    'jenis_kelamin' => $data['jenis_kelamin'] ?: null,
                    'tempat_lahir' => $data['tempat_lahir'] ?: null,
                    'tgl_lahir' => $data['tgl_lahir'],
                    'alamat' => $data['alamat'] ?: null,
                    'status' => $status,
                ];

                /*
|--------------------------------------------------------------------------
| Cari Berdasarkan NIP
|--------------------------------------------------------------------------
|
| NIP tetap digunakan sebagai identifier untuk menentukan apakah
| data import adalah INSERT atau UPDATE.
|
| Tetapi URL QR tidak lagi menggunakan NIP.
|
*/

                if ($karyawan) {
                    /*
    |--------------------------------------------------------------------------
    | Simpan Slug Nama Lama
    |--------------------------------------------------------------------------
    */

                    $oldNamaLengkap = trim(($karyawan->nama_depan ?? '') . ' ' . ($karyawan->nama_belakang ?? ''));

                    $oldSlug = Str::slug($oldNamaLengkap);

                    $oldQrPath = $karyawan->qr_code;

                    /*
    |--------------------------------------------------------------------------
    | Update Data
    |--------------------------------------------------------------------------
    */

                    $karyawan->update($payload);

                    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    */

                    $karyawan->refresh();

                    $newNamaLengkap = trim(($karyawan->nama_depan ?? '') . ' ' . ($karyawan->nama_belakang ?? ''));

                    $newSlug = Str::slug($newNamaLengkap);

                    /*
    |--------------------------------------------------------------------------
    | Regenerate QR Jika Nama Berubah
    |--------------------------------------------------------------------------
    */

                    if ($oldSlug !== $newSlug || ! $karyawan->qr_code) {
                        $qrPath = $this->generateQrImage($newNamaLengkap, $karyawan->uuid);

                        $karyawan->update([
                            'qr_code' => $qrPath,
                        ]);

                        if ($oldQrPath && ! Karyawan::where('qr_code', $oldQrPath)->exists()) {
                            Storage::disk('public')->delete($oldQrPath);
                        }
                    }

                    $this->updateCount++;
                } else {
                    /*
    |--------------------------------------------------------------------------
    | Insert Data Baru
    |--------------------------------------------------------------------------
    */

                    $newKaryawan = Karyawan::create([
                        'uuid' => (string) Str::uuid(),
                        ...$payload,
                    ]);

                    /*
    |--------------------------------------------------------------------------
    | Nama Lengkap
    |--------------------------------------------------------------------------
    */

                    $namaLengkap = trim(($newKaryawan->nama_depan ?? '') . ' ' . ($newKaryawan->nama_belakang ?? ''));

                    /*
    |--------------------------------------------------------------------------
    | Generate QR Berdasarkan Nama
    |--------------------------------------------------------------------------
    */

                    $qrPath = $this->generateQrImage($namaLengkap, $newKaryawan->uuid);

                    /*
    |--------------------------------------------------------------------------
    | Update QR
    |--------------------------------------------------------------------------
    */

                    $newKaryawan->update([
                        'qr_code' => $qrPath,
                    ]);

                    $this->insertCount++;
                }

                $this->successCount++;
            } catch (ValidationException $exception) {
                $this->errors[] = ['row' => $rowNumber, 'messages' => collect($exception->errors())->flatten()->all()];
            } catch (\Throwable $th) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'messages' => ['Gagal diproses: ' . $th->getMessage()],
                ];
            }
        }
    }

    private function generateQrImage(string $namaLengkap, string $employeeUuid): string
    {
        /*
    |--------------------------------------------------------------------------
    | Folder QR
    |--------------------------------------------------------------------------
    */

        $folder = 'qrcode/karyawan/'.$employeeUuid;

        /*
    |--------------------------------------------------------------------------
    | Slug Nama Lengkap
    |--------------------------------------------------------------------------
    |
    | Contoh:
    |
    | Devia Nur Julianthy
    |
    | menjadi:
    |
    | devia-nur-julianthy
    |
    */

        $slugNama = Str::slug($namaLengkap) ?: $employeeUuid;

        /*
    |--------------------------------------------------------------------------
    | Nama File
    |--------------------------------------------------------------------------
    */

        $fileName = $slugNama . '.png';

        $filePath = $folder . '/' . $fileName;

        /*
    |--------------------------------------------------------------------------
    | Buat Folder Jika Belum Ada
    |--------------------------------------------------------------------------
    */

        if (!Storage::disk('public')->exists($folder)) {
            Storage::disk('public')->makeDirectory($folder);
        }

        /*
    |--------------------------------------------------------------------------
    | URL ID Card
    |--------------------------------------------------------------------------
    */

        $idCardUrl = route('karyawan.idCard', [
            'nama_lengkap' => $slugNama,
            'employee' => $employeeUuid,
        ]);

        /*
    |--------------------------------------------------------------------------
    | Generate QR
    |--------------------------------------------------------------------------
    */

        $qrCode = new QrCode(data: $idCardUrl, size: 300, margin: 10);

        $writer = new PngWriter();

        $result = $writer->write($qrCode);

        /*
    |--------------------------------------------------------------------------
    | Simpan QR
    |--------------------------------------------------------------------------
    */

        Storage::disk('public')->put($filePath, $result->getString());

        return $filePath;
    }
}
