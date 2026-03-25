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
                @endphp

                <form method="POST" action="{{ route('pos.sessions.store') }}">
                    @csrf

                    <div id="saldo-form-container">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="terminal_search" class="form-label">
                                        Terminal
                                    </label>
                                    <livewire:modules.pos.pos-terminal-search-dropdown
                                        name="terminal_id"
                                        placeholder="Pilih terminal..."
                                        :selected="old('terminal_id')"
                                        :error="$errors->first('terminal_id')"
                                        wire:key="pos-terminal-dropdown"
                                    />
                                    <small class="text-muted">
                                        Opsional. Anda dapat membuka sesi tanpa memilih terminal.
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
    const observer = new MutationObserver(updateSaldoVisibility);
    if (terminalInput.parentElement) {
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
