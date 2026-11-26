<?php

namespace App\Http\Controllers;

use App\Imports\KaryawanImport;
use App\Imports\KaryawanImportValidator;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Karyawan;
use App\Models\Posisi;
use App\Models\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;

class KaryawanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->checkPermission('karyawan.view');

        $divisi = Divisi::with('departemen')->orderBy('nama_divisi')->get();
        $departemen = Departemen::orderBy('departemen')->get();
        $posisi = Posisi::with('divisi')->orderBy('nama_posisi')->get();
        $isEmployeeAdmin = $request->user()->hasAnyRole(['admin', 'super admin']);
        $users = $isEmployeeAdmin ? User::orderBy('email')->get(['uuid', 'email']) : collect();
        $query = Karyawan::with(['divisi.departemen', 'posisi']);
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nip', 'like', '%' . $search . '%')
                    ->orWhere('nama_depan', 'like', '%' . $search . '%')
                    ->orWhere('nama_belakang', 'like', '%' . $search . '%')
                    ->orWhere('no_hp', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }
        if ($request->filled('divisi_uuid') && $request->divisi_uuid !== 'all') {
            $query->where('divisi_uuid', $request->divisi_uuid);
        }
        if ($request->filled('departemen_uuid') && $request->departemen_uuid !== 'all') {
            $query->whereHas('divisi', fn ($divisions) => $divisions->where('uuid_departemen', $request->departemen_uuid));
        }
        if ($request->filled('uuid_posisi') && $request->uuid_posisi !== 'all') {
            $query->where('uuid_posisi', $request->uuid_posisi);
        }
        if (in_array($request->query('status'), ['0', '1'], true)) {
            $query->where('status', $request->boolean('status'));
        }
        $perPage = (int) $request->get('per_page', 10);
        if (!in_array($perPage, [10, 25, 50, 100])) {
            $perPage = 10;
        }
        $karyawans = $query->latest()->orderByDesc('id')->paginate($perPage)->withQueryString();

        return view('karyawan.index', compact('karyawans', 'divisi', 'posisi', 'departemen', 'isEmployeeAdmin', 'users'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->checkPermission('karyawan.create');
        $data = $this->employeeData($request);
        $this->saveEmployee($request, new Karyawan, $data);

        return $request->expectsJson()
            ? response()->json(['message' => 'Data berhasil ditambahkan.'], 201)
            : back()->with('success', 'Data berhasil ditambahkan.');
    }


    /**
     * Display the specified resource.
     */
    public function show(string $nip)
    {
        $this->checkPermission('karyawan.detail');
        $karyawan = Karyawan::with(['divisi.departemen', 'posisi'])
            ->where('nip', $nip)
            ->firstOrFail();
        return response()->json([
            'uuid' => $karyawan->uuid,
            'nama_depan' => $karyawan->nama_depan,
            'nama_belakang' => $karyawan->nama_belakang,
            'nip' => $karyawan->nip,
            'email' => $karyawan->email,
            'no_hp' => $karyawan->no_hp,
            'jenis_kelamin' => $karyawan->jenis_kelamin,
            'tempat_lahir' => $karyawan->tempat_lahir,
            'tgl_lahir' => $karyawan->tgl_lahir,
            'alamat' => $karyawan->alamat,
            'status' => $karyawan->status,
            'departemen' => $karyawan->divisi?->departemen?->departemen ?? '-',
            'divisi' => $karyawan->divisi?->nama_divisi ?? '-',
            'posisi' => $karyawan->posisi?->nama_posisi ?? '-',
            'avatar' => $karyawan->avatar ? asset('storage/' . $karyawan->avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($karyawan->nama_depan . ' ' . $karyawan->nama_belakang) . '&background=1e3a8a&color=ffffff&size=256&bold=true',
            'qr_code' => $karyawan->qr_code ? asset('storage/' . $karyawan->qr_code) : asset('assets/static/avatars/default.jpg'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $uuid)
    {
        $this->checkPermission('karyawan.edit');
        $karyawan = Karyawan::with('posisi.divisi')->where('uuid', $uuid)->firstOrFail();
        return response()->json([
            'uuid' => $karyawan->uuid,
            'nama_depan' => $karyawan->nama_depan,
            'nama_belakang' => $karyawan->nama_belakang,
            'email' => $karyawan->email,
            'nip' => $karyawan->nip,
            'no_hp' => $karyawan->no_hp,
            'jenis_kelamin' => $karyawan->jenis_kelamin,
            'tempat_lahir' => $karyawan->tempat_lahir,
            'tgl_lahir' => $karyawan->tgl_lahir,
            'alamat' => $karyawan->alamat,
            'status' => $karyawan->status,
            'departemen_uuid' => $karyawan->posisi->divisi->uuid_departemen,
            'divisi_uuid' => $karyawan->divisi_uuid,
            'uuid_posisi' => $karyawan->uuid_posisi,
            'user_uuid' => $karyawan->user_uuid,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $uuid)
    {
        $this->checkPermission('karyawan.edit');
        $employee = Karyawan::where('uuid', $uuid)->firstOrFail();
        $this->saveEmployee($request, $employee, $this->employeeData($request, $employee));

        return response()->json(['message' => 'Data karyawan berhasil diperbarui.']);
    }

    private function employeeData(Request $request, ?Karyawan $employee = null): array
    {
        $id = $employee?->id;
        $data = $request->validate([
            'departemen_uuid' => ['required', 'uuid', 'exists:departemens,uuid'],
            'divisi_uuid' => ['required', 'uuid', 'exists:divisis,uuid'],
            'uuid_posisi' => ['required', 'uuid', 'exists:posisis,uuid'],
            'user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid', Rule::unique('karyawans', 'user_uuid')->ignore($id)],
            'nip' => ['required', 'string', 'max:50', Rule::unique('karyawans', 'nip')->ignore($id)],
            'nama_depan' => ['required', 'string', 'max:100'],
            'nama_belakang' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', Rule::unique('karyawans', 'email')->ignore($id)],
            'no_hp' => [$employee && $employee->no_hp === null ? 'nullable' : 'required', 'string', 'max:20', Rule::unique('karyawans', 'no_hp')->ignore($id)],
            'jenis_kelamin' => ['nullable', 'in:laki-laki,perempuan'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tgl_lahir' => ['nullable', 'date'],
            'alamat' => ['nullable', 'string'],
            'status' => ['required', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $position = Posisi::with('divisi.departemen')->where('uuid', $data['uuid_posisi'])->first();
        if (! $position || $position->divisi_uuid !== $data['divisi_uuid']
            || $position->divisi->uuid_departemen !== $data['departemen_uuid']
        ) {
            throw ValidationException::withMessages(['uuid_posisi' => 'Posisi harus berada di departemen dan divisi yang dipilih.']);
        }
        if ($employee?->uuid_posisi !== $position->uuid && ! $position->hasActiveHierarchy()) {
            throw ValidationException::withMessages(['uuid_posisi' => 'Pilih posisi, divisi, dan departemen yang aktif.']);
        }

        $userUuid = $data['user_uuid'] ?? null;
        if ($userUuid !== null && $userUuid !== $employee?->user_uuid) {
            $user = User::where('uuid', $userUuid)->first();
            if (! $user || strcasecmp($user->email, $data['email']) !== 0) {
                throw ValidationException::withMessages(['user_uuid' => 'Email akun dan karyawan harus sama.']);
            }
        }

        unset($data['departemen_uuid'], $data['divisi_uuid'], $data['avatar']);

        return $data;
    }

    private function saveEmployee(Request $request, Karyawan $employee, array $data): void
    {
        $oldAvatar = $employee->avatar;
        $oldQr = $employee->qr_code;
        $oldSlug = $employee->slug_nama;
        $newAvatar = null;
        $newQr = null;
        $disk = Storage::disk('public');

        try {
            DB::transaction(function () use ($request, $employee, $data, $oldSlug, $disk, &$newAvatar, &$newQr) {
                if ($request->hasFile('avatar')) {
                    $newAvatar = $request->file('avatar')->store('karyawan/avatar', 'public');
                    if (! $newAvatar) {
                        throw new \RuntimeException('Gagal menyimpan foto karyawan.');
                    }
                    $data['avatar'] = $newAvatar;
                }
                $employee->fill($data)->save();

                if (! $employee->qr_code || $oldSlug !== $employee->slug_nama) {
                    $url = route('karyawan.idCard', [
                        'nama_lengkap' => $employee->slug_nama ?: $employee->uuid,
                        'employee' => $employee->uuid,
                    ]);
                    $qr = (new PngWriter)->write(new QrCode(data: $url, size: 300, margin: 10));
                    $newQr = 'qrcode/karyawan/'.$employee->uuid.'/'.Str::uuid().'.png';
                    if (! $disk->put($newQr, $qr->getString())) {
                        throw new \RuntimeException('Gagal menyimpan kode QR karyawan.');
                    }
                    $employee->update(['qr_code' => $newQr]);
                }
            });
        } catch (\Throwable $exception) {
            $disk->delete(array_filter([$newAvatar, $newQr]));
            throw $exception;
        }

        if ($newAvatar && $oldAvatar && ! Karyawan::where('avatar', $oldAvatar)->exists()) {
            $disk->delete($oldAvatar);
        }
        if ($newQr && $oldQr && ! Karyawan::where('qr_code', $oldQr)->exists()) {
            $disk->delete($oldQr);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $uuid)
    {
        $this->checkPermission('karyawan.delete');
        $this->deleteEmployees([$uuid]);

        return response()->json(['success' => 'Data berhasil dihapus.']);
    }

    public function bulkDestroy(Request $request)
    {
        $this->checkPermission('karyawan.delete');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1'],
            'uuids.*' => ['required', 'uuid', 'distinct', 'exists:karyawans,uuid'],
        ]);

        $total = $this->deleteEmployees($validated['uuids']);

        return response()->json(['success' => true, 'message' => $total.' data karyawan berhasil dihapus.']);
    }

    private function deleteEmployees(array $uuids): int
    {
        return DB::transaction(function () use ($uuids) {
            $employees = Karyawan::whereIn('uuid', $uuids)->lockForUpdate()->get();
            abort_if($employees->count() !== count($uuids), 404);

            if ($employees->contains(fn (Karyawan $employee) => $employee->user_uuid !== null)) {
                throw ValidationException::withMessages(['user_uuid' => 'Lepaskan akun pengguna sebelum menghapus karyawan.']);
            }

            return Karyawan::whereKey($employees->modelKeys())->delete();
        });
    }


    public function idCard(Request $request, string $nama_lengkap)
    {
        $this->checkPermission('karyawan.id card');
        $user = auth()->user();
        $query = $user->can('karyawan.detail') ? Karyawan::query() : $user->karyawan();

        $query->with(['divisi.departemen', 'posisi']);
        $employeeUuid = $request->query('employee');
        $karyawan = is_string($employeeUuid) && Str::isUuid($employeeUuid)
            ? $query->where('uuid', $employeeUuid)->first()
            : $query->get()->first(fn (Karyawan $employee) => $employee->slug_nama === $nama_lengkap);
        if (!$karyawan) {
            abort(404, 'Data karyawan tidak ditemukan.');
        }
        return view('karyawan.id-card', ['karyawan' => $karyawan, 'dataKaryawan' => $this->formatIdCardData($karyawan)]);
    }

    private function formatIdCardData($karyawan)
    {
        $user = auth()->user();

        $bolehLihatAlamat = auth()->check() && ($user?->departemen?->kode_departemen === 'HR' || $user?->hasRole('super admin') || $user?->hasRole('admin'));

        $namaLengkap = trim(($karyawan->nama_depan ?? '') . ' ' . ($karyawan->nama_belakang ?? ''));

        return [
            'nip' => $karyawan->nip ?? '-',
            'nama_depan' => $karyawan->nama_depan ?? '',
            'nama_belakang' => $karyawan->nama_belakang ?? '',
            'namaLengkap' => $namaLengkap,
            'slug_nama' => Str::slug($namaLengkap),
            'posisi' => $karyawan->posisi?->nama_posisi ?? '-',
            'foto' => $karyawan->avatar ? asset('storage/' . $karyawan->avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($namaLengkap) . '&background=1e3a8a&color=ffffff&size=256&bold=true',
            'email' => $karyawan->email ?? '-',
            'departemen' => $karyawan->divisi?->departemen?->departemen ?? '-',
            'divisi' => $karyawan->divisi?->nama_divisi ?? '-',
            'jenis_kelamin' => $karyawan->jenis_kelamin ? Str::title(str_replace('-', ' ', $karyawan->jenis_kelamin)) : '-',
            'tempat_lahir' => $karyawan->tempat_lahir ?? '-',
            'tgl_lahir' => $karyawan->tgl_lahir ? Carbon::parse($karyawan->tgl_lahir)->translatedFormat('d F Y') : '-',
            'no_hp' => $karyawan->no_hp ?? '-',
            'alamat' => $bolehLihatAlamat ? $karyawan->alamat ?? '-' : '-',
            'boleh_lihat_alamat' => $bolehLihatAlamat,
            'status' => $karyawan->status == 1 ? 'Aktif' : 'Nonaktif',
            'status_class' => $karyawan->status == 1 ? 'status-active' : 'status-nonactive',
        ];
    }

    public function import(Request $request)
    {
        $this->checkPermission('karyawan.import');

        $request->validate(
            [
                'file' => 'required|mimes:xlsx,xls,csv|max:5120',
            ],
            [
                'file.required' => 'File wajib diupload.',
                'file.mimes' => 'File harus berformat xlsx, xls, atau csv.',
                'file.max' => 'Ukuran file maksimal 5MB.',
            ],
        );

        try {
            $import = new KaryawanImport();
            Excel::import($import, $request->file('file'));

            $messages = [];

            if ($import->insertCount > 0) {
                $messages[] = "{$import->insertCount} data baru ditambahkan";
            }

            if ($import->updateCount > 0) {
                $messages[] = "{$import->updateCount} data berhasil diperbarui";
            }

            if (count($import->errors) > 0) {
                $errorText = collect($import->errors)
                    ->map(function ($error) {
                        return 'Baris ' . $error['row'] . ': ' . implode(', ', $error['messages']);
                    })
                    ->implode(' | ');

                return redirect()
                    ->back()
                    ->with('warning', implode(', ', $messages) . '. Sebagian data gagal diimport: ' . $errorText);
            }

            return redirect()
                ->back()
                ->with('success', implode(', ', $messages) ?: 'Data karyawan berhasil diimport.');
        } catch (\Throwable $th) {
            return redirect()
                ->back()
                ->with('error', 'Gagal import data: ' . $th->getMessage());
        }
    }

    public function testImport(Request $request)
    {
        $this->checkPermission('karyawan.import');

        $request->validate(
            [
                'file' => 'required|mimes:xlsx,xls,csv|max:5120',
            ],
            [
                'file.required' => 'File wajib dipilih.',

                'file.mimes' => 'File harus berformat xlsx, xls, atau csv.',

                'file.max' => 'Ukuran file maksimal 5MB.',
            ],
        );

        try {
            /*
        |--------------------------------------------------------------------------
        | Jalankan Validator
        |--------------------------------------------------------------------------
        |
        | Class ini TIDAK melakukan create/update.
        |
        */

            $validator = new KaryawanImportValidator();

            Excel::import($validator, $request->file('file'));

            /*
        |--------------------------------------------------------------------------
        | Hasil
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'success' => $validator->isValid(),

                'message' => $validator->isValid() ? 'File berhasil divalidasi dan siap diimport.' : 'File masih memiliki data yang harus diperbaiki.',

                'summary' => [
                    'total' => count($validator->preview),

                    'valid' => $validator->validRows,

                    'error' => count($validator->errors),

                    'insert' => $validator->insertCount,

                    'update' => $validator->updateCount,

                    'warning' => count($validator->warnings),
                ],

                'errors' => $validator->errors,

                'warnings' => $validator->warnings,

                'preview' => $validator->preview,
            ]);
        } catch (\Throwable $th) {
            return response()->json(
                [
                    'success' => false,

                    'message' => 'Gagal membaca file: ' . $th->getMessage(),
                ],
                422,
            );
        }
    }

    public function exportQrCode(Request $request)
    {
        $this->checkPermission('karyawan.detail');

        $request->validate([
            'selected_nips' => ['required', 'string'],
        ]);

        $nips = json_decode($request->selected_nips, true);

        if (!is_array($nips) || count($nips) === 0) {
            return back()->with('error', 'Pilih minimal satu karyawan untuk export.');
        }

        $karyawans = Karyawan::with(['divisi.departemen', 'posisi'])
            ->whereIn('nip', $nips)
            ->get()
            ->sortBy(function ($karyawan) use ($nips) {
                return array_search($karyawan->nip, $nips);
            })
            ->values();

        return view('karyawan.export-qrcode', [
            'karyawans' => $karyawans,
            'selectedNips' => $nips,
        ]);
    }

    public function exportQrCodeZip(Request $request)
    {
        $this->checkPermission('karyawan.detail');

        $request->validate([
            'selected_nips' => ['required', 'string'],
        ]);

        $nips = json_decode($request->selected_nips, true);

        if (!is_array($nips) || count($nips) === 0) {
            return back()->with('error', 'Pilih minimal satu karyawan untuk export.');
        }

        $nips = array_values(array_unique($nips));

        $karyawans = Karyawan::whereIn('nip', $nips)
            ->get()
            ->sortBy(function ($karyawan) use ($nips) {
                return array_search($karyawan->nip, $nips);
            })
            ->values();

        $tempDir = storage_path('app/temp');

        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zipFileName = 'qrcode-karyawan-' . now()->format('YmdHis') . '.zip';
        $zipPath = $zipFileName;

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Gagal membuat file ZIP.');
        }

        foreach ($karyawans as $karyawan) {
            if (!$karyawan->qr_code) {
                continue;
            }

            $qrPath = storage_path('app/public/' . $karyawan->qr_code);

            if (!file_exists($qrPath)) {
                continue;
            }

            $imageContent = file_get_contents($qrPath);
            $image = imagecreatefromstring($imageContent);

            if (!$image) {
                continue;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            $jpgImage = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($jpgImage, 255, 255, 255);

            imagefill($jpgImage, 0, 0, $white);
            imagecopy($jpgImage, $image, 0, 0, 0, 0, $width, $height);

            ob_start();
            imagejpeg($jpgImage, null, 95);
            $jpgContent = ob_get_clean();

            imagedestroy($image);
            imagedestroy($jpgImage);

            $namaLengkap = trim(($karyawan->nama_depan ?? '') . ' ' . ($karyawan->nama_belakang ?? ''));
            $fileName = $this->cleanFileName($karyawan->nip . '-' . $namaLengkap) . '.jpg';

            $zip->addFromString($fileName, $jpgContent);
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    private function cleanFileName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9\- ]/', '', $name);
        $name = preg_replace('/\s+/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);

        return trim($name, '-');
    }
}
