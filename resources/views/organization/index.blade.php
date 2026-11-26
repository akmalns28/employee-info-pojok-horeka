@extends('layouts.app')

@section('title', $label)
@section('header', $label)
@section('sub header', 'Data Master')

@section('header actions')
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-tambah">
        Tambah {{ $label }}
    </button>
@endsection

@section('content')
    @foreach (['success', 'error'] as $notice)
        @if (session($notice))
            <div class="alert alert-{{ $notice === 'error' ? 'danger' : 'success' }}" role="alert">
                {{ session($notice) }}
            </div>
        @endif
    @endforeach

    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md">
                    <label for="search" class="form-label">Cari kode atau nama</label>
                    <input id="search" name="search" type="search" class="form-control" value="{{ request()->string('search') }}">
                </div>
                @if ($parent)
                    <div class="col-md">
                        <label for="parent-filter" class="form-label">{{ $parent['label'] }}</label>
                        <select id="parent-filter" name="{{ $parent['field'] }}" class="form-select">
                            <option value="">Semua</option>
                            @foreach ($parents as $option)
                                <option value="{{ $option->uuid }}" @selected(request($parent['field']) === $option->uuid)>
                                    {{ $parent['field'] === 'divisi_uuid' ? $option->departemen->departemen.' / ' : '' }}{{ $option->{$parent['name']} }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-auto">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">Semua</option>
                        <option value="1" @selected(request('status') === '1')>Aktif</option>
                        <option value="0" @selected(request('status') === '0')>Nonaktif</option>
                    </select>
                </div>
                <div class="col-md-auto">
                    <label for="per_page" class="form-label">Per halaman</label>
                    <select id="per_page" name="per_page" class="form-select">
                        @foreach ([10, 25, 50, 100] as $size)
                            <option value="{{ $size }}" @selected($records->perPage() === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-auto"><button class="btn btn-outline-primary">Terapkan</button></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        @if ($parent)
                            <th>{{ $parent['label'] }}</th>
                        @endif
                        <th>Status</th>
                        <th class="w-1">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ $record->{$codeField} }}</td>
                            <td>{{ $record->{$nameField} }}</td>
                            @if ($parent)
                                <td>{{ $record->{$parent['relation']}?->{$parent['name']} }}</td>
                            @endif
                            <td><span @class(['badge', 'bg-green-lt' => $record->is_active, 'bg-secondary-lt' => ! $record->is_active])>{{ $record->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <a href="{{ route($resource.'.edit', $record->uuid) }}" class="btn btn-sm">Edit</a>
                                    <form action="{{ route($resource.'.destroy', $record->uuid) }}" method="POST"
                                        onsubmit="return confirm('Hapus data ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" @disabled($record->{$childCount})>Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $parent ? 5 : 4 }}" class="text-center text-secondary py-4">Data tidak ditemukan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($records->hasPages())
            <div class="card-footer">{{ $records->links() }}</div>
        @endif
    </div>
@endsection

@push('modal')
    <div class="modal modal-blur fade" id="modal-tambah" tabindex="-1" aria-labelledby="organization-modal-title" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="organization-modal-title">Tambah {{ $label }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                @include('organization.form', ['record' => null])
            </div>
        </div>
    </div>
@endpush

@if ($errors->any())
    @push('scripts')
        <script>
            window.addEventListener('load', () => document.querySelector('[data-bs-target="#modal-tambah"]')?.click());
        </script>
    @endpush
@endif
