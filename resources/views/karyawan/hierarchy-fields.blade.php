@php($prefix = $editing ? 'edit_' : 'new_')

@foreach (['departemen_uuid' => 'Departemen', 'divisi_uuid' => 'Divisi', 'uuid_posisi' => 'Posisi'] as $field => $label)
    <div class="col-md-4 mb-3">
        <label for="{{ $prefix.$field }}" class="form-label required">{{ $label }}</label>
        <select id="{{ $prefix.$field }}" name="{{ $field }}" class="form-select"
            data-selection="{{ $editing ? '' : old($field) }}" required>
            <option value="">Pilih {{ $label }}</option>
        </select>
        <small class="text-danger" id="{{ $prefix.$field }}_error">
            @unless ($editing)
                @error($field) {{ $message }} @enderror
            @endunless
        </small>
    </div>
@endforeach

<div class="col-md-6 mb-3">
    <label for="{{ $prefix }}user_uuid" class="form-label">Akun Pengguna</label>
    <select id="{{ $prefix }}user_uuid" name="user_uuid" class="form-select">
        <option value="">Tanpa akun</option>
        @foreach ($users as $user)
            <option value="{{ $user->uuid }}" @selected(! $editing && old('user_uuid') === $user->uuid)>{{ $user->email }}</option>
        @endforeach
    </select>
    <small class="text-danger" id="{{ $prefix }}user_uuid_error">
        @unless ($editing)
            @error('user_uuid') {{ $message }} @enderror
        @endunless
    </small>
</div>
