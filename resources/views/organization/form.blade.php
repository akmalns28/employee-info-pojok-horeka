<form method="POST" action="{{ $record ? route($resource.'.update', $record->uuid) : route($resource.'.store') }}">
    @csrf
    @if ($record)
        @method('PUT')
    @endif
    <div class="{{ $record ? 'card-body' : 'modal-body' }}">
        @if ($parent)
            <div class="mb-3">
                <label for="organization-parent" class="form-label required">{{ $parent['label'] }}</label>
                <select id="organization-parent" name="{{ $parent['field'] }}" class="form-select @error($parent['field']) is-invalid @enderror" required>
                    <option value="">Pilih {{ $parent['label'] }}</option>
                    @foreach ($parents as $option)
                        <option value="{{ $option->uuid }}" @selected(old($parent['field'], $record?->{$parent['field']}) === $option->uuid)>
                            {{ $parent['field'] === 'divisi_uuid' ? $option->departemen->departemen.' / ' : '' }}{{ $option->{$parent['name']} }}{{ $option->is_active ? '' : ' (Nonaktif)' }}
                        </option>
                    @endforeach
                </select>
                @error($parent['field']) <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        @endif
        <div class="mb-3">
            <label for="organization-code" class="form-label required">Kode</label>
            <input id="organization-code" name="{{ $codeField }}" class="form-control @error($codeField) is-invalid @enderror"
                value="{{ old($codeField, $record?->{$codeField}) }}" maxlength="20" required>
            @error($codeField) <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label for="organization-name" class="form-label required">Nama</label>
            <input id="organization-name" name="{{ $nameField }}" class="form-control @error($nameField) is-invalid @enderror"
                value="{{ old($nameField, $record?->{$nameField}) }}" maxlength="100" required>
            @error($nameField) <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div>
            <label for="organization-status" class="form-label">Status</label>
            <select id="organization-status" name="is_active" class="form-select @error('is_active') is-invalid @enderror">
                <option value="1" @selected((bool) old('is_active', $record?->is_active ?? true))>Aktif</option>
                <option value="0" @selected(! (bool) old('is_active', $record?->is_active ?? true))>Nonaktif</option>
            </select>
            @error('is_active') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="{{ $record ? 'card-footer text-end' : 'modal-footer' }}">
        <button class="btn btn-primary">Simpan</button>
    </div>
</form>
