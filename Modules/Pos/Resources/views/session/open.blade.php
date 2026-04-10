@extends('layouts.app')

@section('title', 'Buka Sesi POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Buka Sesi POS</div>
            <div class="card-body">
                @php
                    $backRoute = auth()->user() && auth()->user()->can('pos.sell')
                        ? route('pos.sell')
                        : route('pos.sessions.index');
                    $canSelectTerminal = (bool) (($roleCapabilities['can_select_terminal_on_open'] ?? false) === true);
                    $isManagerCheckoutRole = (bool) (($roleCapabilities['is_manager_checkout_role'] ?? false) === true);
                    $terminalHint = $canSelectTerminal
                        ? ($isManagerCheckoutRole
                            ? 'Opsional. Manager tetap dapat membuka pembayaran meski sesi ini tanpa terminal, tetapi pemilihan terminal tetap tersedia untuk alur kasir normal.'
                            : 'Opsional. Kasir dapat membuka sesi tanpa terminal, tetapi pembayaran baru aktif setelah sesi ini memiliki terminal.')
                        : 'Floor staff bekerja tanpa terminal. Gunakan sesi ini untuk siapkan, simpan, dan load draft sebelum handoff ke kasir atau manager.';
                @endphp

                @if($activeSessionInOtherSetting)
                    <div class="alert alert-warning mb-4 shadow-sm border-start border-4 border-warning">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading fw-bold mb-1">Sesi Aktif Terdeteksi di Lokasi Lain</h5>
                                <p class="mb-0">
                                    Anda saat ini memiliki sesi POS yang masih terbuka di <strong>{{ $activeSessionInOtherSetting->setting?->company_name }}</strong>.
                                    Demi keamanan kas, sistem hanya mengizinkan satu sesi aktif per pengguna secara global. 
                                    Silakan tutup sesi tersebut di lokasi originalnya sebelum membuka sesi baru di sini.
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('pos.sessions.store') }}">
                    @csrf

                    <fieldset @if($activeSessionInOtherSetting) disabled style="opacity: 0.6;" @endif>
                        <div id="saldo-form-container">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="terminal_search" class="form-label">
                                            Terminal
                                        </label>
                                        <livewire:modules.pos.pos-terminal-search-dropdown
                                            name="terminal_id"
                                            :placeholder="$canSelectTerminal ? 'Pilih terminal...' : 'Terminal tidak dipakai untuk floor staff'"
                                            :selected="old('terminal_id')"
                                            :error="$errors->first('terminal_id')"
                                            :disabled="! $canSelectTerminal || (bool) $activeSessionInOtherSetting"
                                            :disabled-reason="$activeSessionInOtherSetting ? 'Tutup sesi aktif Anda terlebih dahulu.' : ($canSelectTerminal ? null : 'Floor staff tidak memilih terminal saat membuka sesi.')"
                                            wire:key="pos-terminal-dropdown"
                                        />
                                        <small class="text-muted">
                                            {{ $terminalHint }}
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3" x-show="terminalSelected" style="display: none;">
                                        <label for="opening_float_total_display" class="form-label">
                                            Total Saldo Awal
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" id="opening_float_total_display"
                                            class="form-control @error('opening_float_total') is-invalid @enderror"
                                            value="{{ old('opening_float_total') ? number_format(old('opening_float_total'), 0, ',', '.') : '' }}"
                                            placeholder="0"
                                            :required="saldoRequired">
                                        <small class="text-muted">
                                            Wajib diisi saat membuka sesi dengan terminal.
                                        </small>
                                        <input type="hidden" name="opening_float_total" id="opening_float_total" value="{{ old('opening_float_total') }}">
                                        @error('opening_float_total')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Catatan</label>
                            <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mt-3">
                            @if(!$activeSessionInOtherSetting)
                                <button type="submit" class="btn btn-primary">Buka Sesi</button>
                            @endif
                            <a href="{{ $backRoute }}" class="btn btn-secondary">Kembali</a>
                        </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const terminalInput = document.querySelector('input[name=terminal_id]');
    const saldoField = document.querySelector('[x-show="terminalSelected"]');
    const saldoInput = document.getElementById('opening_float_total_display');

    if (!terminalInput || !saldoField) {
        return;
    }

    function updateSaldoVisibility() {
        const hasValue = terminalInput && terminalInput.value !== '';
        if (hasValue) {
            saldoField.style.display = '';
            if (saldoInput) {
                saldoInput.setAttribute('required', '');
            }
        } else {
            saldoField.style.display = 'none';
            if (saldoInput) {
                saldoInput.removeAttribute('required');
            }
        }
    }

    // Initial check
    updateSaldoVisibility();

    // Watch for changes via MutationObserver
    if (terminalInput && terminalInput.parentElement) {
        const observer = new MutationObserver(updateSaldoVisibility);
        observer.observe(terminalInput.parentElement, {
            subtree: true,
            attributes: true,
            attributeFilter: ['value']
        });
    }

    // Watch for Livewire updates
    document.addEventListener('livewire:updated', updateSaldoVisibility);

    // Number formatting for Saldo input
    const totalInput = document.getElementById('opening_float_total');
    const displayInput = document.getElementById('opening_float_total_display');

    function formatNumber(num) {
        if (!num) return '';
        return parseInt(num, 10).toLocaleString('id-ID');
    }

    function parseFormattedNumber(str) {
        return parseInt(str.replace(/[^0-9]/g, ''), 10) || null;
    }

    if (displayInput && totalInput) {
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
    }
});
</script>
@endpush
