@extends('layouts.app')

@section('title', 'Buka Sesi POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Buka Sesi POS</div>
            <div class="card-body">
                @php
                    $requiresTerminalSelection = (bool) ($requiresTerminalSelection ?? false);
                    $backRoute = auth()->user() && auth()->user()->can('pos.sell')
                        ? route('pos.sell')
                        : route('pos.sessions.index');
                @endphp

                <form method="POST" action="{{ route('pos.sessions.store') }}">
                    @csrf

                    @if($requiresTerminalSelection)
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="terminal_search" class="form-label">
                                        Terminal
                                        <span class="text-danger">*</span>
                                    </label>
                                    <livewire:modules.pos.pos-terminal-search-dropdown
                                        name="terminal_id"
                                        placeholder="Pilih terminal..."
                                        :selected="old('terminal_id')"
                                        :error="$errors->first('terminal_id')"
                                        wire:key="pos-terminal-dropdown"
                                    />
                                    <small class="text-muted">
                                        Pengguna Anda wajib memilih terminal sebelum membuka sesi.
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="opening_float_total_display" class="form-label">
                                        Total Saldo Awal
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="text" id="opening_float_total_display"
                                               class="form-control @error('opening_float_total') is-invalid @enderror"
                                               value="{{ old('opening_float_total') ? number_format(old('opening_float_total'), 0, ',', '.') : '' }}"
                                               placeholder="0"
                                               required>
                                    </div>
                                    <small class="text-muted">
                                        Wajib diisi saat membuka sesi dengan terminal.
                                    </small>
                                    <input type="hidden" name="opening_float_total" id="opening_float_total" value="{{ old('opening_float_total') }}">
                                    @error('opening_float_total')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="alert alert-info mb-3">
                            Sesi Anda tidak mewajibkan terminal. Sesi akan dibuka sebagai sesi non-terminal dengan saldo awal Rp0.
                        </div>
                    @endif

                    <div class="mb-3">
                        <label for="notes" class="form-label">Catatan</label>
                        <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Buka Sesi</button>
                        <a href="{{ $backRoute }}" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalInput = document.getElementById('opening_float_total');
    const displayInput = document.getElementById('opening_float_total_display');

    function formatNumber(num) {
        if (!num) return '';
        return parseInt(num, 10).toLocaleString('id-ID');
    }

    function parseFormattedNumber(str) {
        return parseInt(str.replace(/[^0-9]/g, ''), 10) || null;
    }

    if (!displayInput || !totalInput) {
        return;
    }

    displayInput.addEventListener('input', function() {
        let cursorPosition = this.selectionStart;
        const oldLength = this.value.length;

        const numericVal = parseFormattedNumber(this.value);
        totalInput.value = numericVal !== null ? numericVal : '';
        this.value = formatNumber(numericVal);

        const newLength = this.value.length;
        cursorPosition = cursorPosition + (newLength - oldLength);
        this.setSelectionRange(cursorPosition, cursorPosition);
    });
});
</script>
@endpush
