@php
    $policy = $terminal?->policy;

    $checkboxValue = static function (string $field, bool $default = false) use ($policy) {
        if (old($field) !== null) {
            return old($field);
        }

        if ($policy && isset($policy->{$field})) {
            return (bool) $policy->{$field};
        }

        return $default;
    };
@endphp

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="code" class="form-label">Kode Terminal</label>
            <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror"
                   value="{{ old('code', $terminal?->code) }}" maxlength="50" required>
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="name" class="form-label">Nama Terminal</label>
            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
                   value="{{ old('name', $terminal?->name) }}" maxlength="100" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="location_id" class="form-label">Lokasi</label>
            <select name="location_id" id="location_id" class="form-select @error('location_id') is-invalid @enderror" required>
                <option value="">-- Pilih Lokasi --</option>
                @foreach($availableLocations as $location)
                    <option value="{{ $location->id }}" @selected((string) old('location_id', $terminal?->location_id) === (string) $location->id)>
                        {{ $location->name }}
                    </option>
                @endforeach
            </select>
            @error('location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-check mt-4">
            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                   @checked(old('is_active', $terminal?->is_active ?? true))>
            <label class="form-check-label" for="is_active">Terminal aktif</label>
        </div>
    </div>
</div>

<hr>
<h6>Kebijakan Terminal</h6>

<div class="row">
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="require_session_open" name="require_session_open" value="1" @checked($checkboxValue('require_session_open', true))>
            <label class="form-check-label" for="require_session_open">Wajib buka sesi</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="require_opening_float" name="require_opening_float" value="1" @checked($checkboxValue('require_opening_float', true))>
            <label class="form-check-label" for="require_opening_float">Wajib saldo awal</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="allow_total_only_float_input" name="allow_total_only_float_input" value="1" @checked($checkboxValue('allow_total_only_float_input', true))>
            <label class="form-check-label" for="allow_total_only_float_input">Izinkan input total saldo awal saja</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="require_pickup_supervisor_approval" name="require_pickup_supervisor_approval" value="1" @checked($checkboxValue('require_pickup_supervisor_approval', true))>
            <label class="form-check-label" for="require_pickup_supervisor_approval">Wajib persetujuan supervisor untuk pengambilan</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="auto_open_drawer_on_session_open" name="auto_open_drawer_on_session_open" value="1" @checked($checkboxValue('auto_open_drawer_on_session_open'))>
            <label class="form-check-label" for="auto_open_drawer_on_session_open">Buka laci otomatis saat sesi dibuka</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="auto_open_drawer_on_cash_sale" name="auto_open_drawer_on_cash_sale" value="1" @checked($checkboxValue('auto_open_drawer_on_cash_sale'))>
            <label class="form-check-label" for="auto_open_drawer_on_cash_sale">Buka laci otomatis saat penjualan tunai</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="auto_open_drawer_on_pickup" name="auto_open_drawer_on_pickup" value="1" @checked($checkboxValue('auto_open_drawer_on_pickup'))>
            <label class="form-check-label" for="auto_open_drawer_on_pickup">Buka laci otomatis saat pengambilan</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="auto_open_drawer_on_close" name="auto_open_drawer_on_close" value="1" @checked($checkboxValue('auto_open_drawer_on_close'))>
            <label class="form-check-label" for="auto_open_drawer_on_close">Buka laci otomatis saat sesi ditutup</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="close_variance_approval_threshold" class="form-label">Ambang persetujuan selisih penutupan</label>
            <input type="number" min="0" step="0.01" id="close_variance_approval_threshold" name="close_variance_approval_threshold"
                   class="form-control @error('close_variance_approval_threshold') is-invalid @enderror"
                   value="{{ old('close_variance_approval_threshold', $policy?->close_variance_approval_threshold ?? 0) }}">
            @error('close_variance_approval_threshold')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label for="cash_threshold" class="form-label">Batas kas</label>
            <input type="number" min="0" step="0.01" id="cash_threshold" name="cash_threshold"
                   class="form-control @error('cash_threshold') is-invalid @enderror"
                   value="{{ old('cash_threshold', $policy?->cash_threshold) }}">
            @error('cash_threshold')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>
