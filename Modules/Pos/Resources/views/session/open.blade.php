@extends('layouts.app')

@section('title', 'Buka Sesi POS')

@push('page_css')
<style>
    .pos-open-workspace {
        width: 100%;
    }

    .pos-open-panel {
        background-color: #ffffff;
        border: 1px solid #d8dbe0;
        border-radius: 0.5rem;
        padding: 1.25rem;
    }

    @media (max-width: 575.98px) {
        .pos-open-panel {
            padding: 1rem;
        }
    }

    .pos-open-group-heading {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
        padding: 0.75rem 0 0.375rem;
        border-bottom: 1px solid #ebedef;
        margin-bottom: 0.25rem;
    }

    .pos-open-group:first-of-type .pos-open-group-heading {
        padding-top: 0.25rem;
    }

    .pos-open-denom-header {
        display: grid;
        grid-template-columns: minmax(84px, 1fr) auto minmax(88px, 120px);
        gap: 0.5rem;
        padding: 0.25rem 0 0.5rem;
        border-bottom: 1px solid #d8dbe0;
        font-size: 0.75rem;
        font-weight: 600;
        color: #6c757d;
    }

    .pos-open-denom-row {
        display: grid;
        grid-template-columns: minmax(84px, 1fr) auto minmax(88px, 120px);
        gap: 0.5rem;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid #f1f3f5;
    }

    .pos-open-denom-row:last-child {
        border-bottom: 0;
    }

    .pos-open-denom-label {
        font-weight: 500;
        color: #212529;
        font-variant-numeric: tabular-nums;
        margin: 0;
        cursor: pointer;
    }

    .pos-open-qty-wrap {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    .pos-open-qty-input {
        width: 92px;
        min-height: 44px;
        text-align: right;
        font-variant-numeric: tabular-nums;
        padding-right: 0.5rem;
    }

    @media (max-width: 400px) {
        .pos-open-qty-input {
            width: 84px;
        }
    }

    .pos-open-unit-label {
        font-size: 0.8125rem;
        color: #6c757d;
        min-width: 44px;
    }

    .pos-open-subtotal {
        text-align: right;
        font-weight: 500;
        font-variant-numeric: tabular-nums;
        color: #212529;
        white-space: nowrap;
    }

    .pos-open-total-value {
        font-size: 1.875rem;
        line-height: 1.2;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: #111827;
        word-break: break-word;
    }

    @media (min-width: 992px) {
        .pos-open-summary-sticky {
            position: sticky;
            top: 72px;
        }
    }

    .visually-hidden {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }
</style>
@endpush

@section('content')
    <div class="container-fluid py-3">
        <div class="pos-open-workspace">
            @include('utils.alerts')

            @php
                $backRoute = auth()->user() && auth()->user()->can('pos.sell')
                    ? route('pos.sell')
                    : route('pos.sessions.index');
                $canSelectTerminal = (bool) (($roleCapabilities['can_select_terminal_on_open'] ?? false) === true);
                $isManagerCheckoutRole = (bool) (($roleCapabilities['is_manager_checkout_role'] ?? false) === true);
                $terminalHint = $canSelectTerminal
                    ? ($isManagerCheckoutRole
                        ? 'Terminal opsional untuk sesi manager.'
                        : 'Tanpa terminal, pembayaran belum tersedia.')
                    : 'Sesi tanpa terminal untuk menyiapkan dan menyerahkan draf.';
                $pageSubtitle = $canSelectTerminal
                    ? 'Pilih terminal dan hitung uang di laci.'
                    : 'Buka sesi kerja tanpa terminal untuk menyiapkan draf.';

                $oldMode = old('opening_cash_input_mode');
                $openingMode = ($canUseNominalOpening && $oldMode === 'nominal') ? 'nominal' : 'denominations';
                $rawOldCounts = old('opening_denominations');
                $oldCounts = is_array($rawOldCounts) ? $rawOldCounts : [];
                $rawOldFloatTotal = old('opening_float_total');
                $oldFloatTotal = is_scalar($rawOldFloatTotal) ? (string) $rawOldFloatTotal : '';
                $rawOldTerminalId = old('terminal_id');
                $oldTerminalId = is_scalar($rawOldTerminalId) ? (string) $rawOldTerminalId : '';
                $hasInitialTerminal = $oldTerminalId !== '';
                $hasEnabledDenominations = $cashDenominations->isNotEmpty();
                $terminalLabels = $terminals->mapWithKeys(fn ($terminal) => [
                    (string) $terminal->id => $terminal->code . ' - ' . $terminal->name,
                ])->all();
            @endphp

            <div class="mb-3">
                <h1 class="h4 fw-bold mb-1">Buka Sesi POS</h1>
                <p class="text-muted small mb-0">{{ $pageSubtitle }}</p>
            </div>

            @if($activeSessionInOtherSetting)
                <div class="alert alert-warning mb-3 shadow-sm border-start border-4 border-warning" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning" aria-hidden="true"></i>
                        </div>
                        <div>
                            <h2 class="h6 alert-heading fw-bold mb-1">Sesi Aktif Terdeteksi di Lokasi Lain</h2>
                            <p class="mb-0 small">
                                Anda saat ini memiliki sesi POS yang masih terbuka di <strong>{{ $activeSessionInOtherSetting->setting?->company_name }}</strong>.
                                Demi keamanan kas, sistem hanya mengizinkan satu sesi aktif per pengguna secara global.
                                Silakan tutup sesi tersebut di lokasi originalnya sebelum membuka sesi baru di sini.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('pos.sessions.store') }}" id="pos-session-open-form" novalidate>
                @csrf

                <fieldset id="pos-session-open-fieldset" @if($activeSessionInOtherSetting) disabled style="opacity: 0.6;" @endif>
                    <div id="saldo-form-container">
                        <div class="pos-open-panel mb-3" id="terminal-picker-panel">
                            <label for="terminal_search" class="form-label fw-medium mb-1">
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
                            <div class="text-muted small mt-1" id="terminal-selection-hint">
                                {{ $terminalHint }}
                            </div>
                        </div>

                        <div class="row g-3 align-items-start" id="pos-open-workspace-grid">
                            <div id="opening-cash-section" class="col-12 col-lg-8" @if(! $hasInitialTerminal) style="display: none;" @endif>
                                <div class="pos-open-panel">
                                    <input type="hidden" name="opening_cash_input_mode" id="opening_cash_input_mode" value="{{ $openingMode }}">

                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                        <div>
                                            <h2 class="h6 fw-bold mb-1" id="opening-cash-heading">
                                                {{ $openingMode === 'nominal' ? 'Input saldo awal' : 'Hitung saldo awal' }}
                                            </h2>
                                            <p class="text-muted small mb-0" id="opening-cash-help">
                                                {{ $openingMode === 'nominal' ? 'Masukkan total uang tunai di laci kasir.' : 'Isi jumlah lembar atau keping. Kolom kosong dihitung nol.' }}
                                            </p>
                                        </div>

                                        @if($canUseNominalOpening)
                                            <button type="button"
                                                id="opening_cash_nominal_switch"
                                                class="btn btn-sm btn-outline-secondary"
                                                aria-pressed="{{ $openingMode === 'nominal' ? 'true' : 'false' }}"
                                                aria-controls="opening-cash-denominations opening-cash-nominal">
                                                {{ $openingMode === 'nominal' ? 'Hitung pecahan' : 'Input nominal' }}
                                            </button>
                                        @endif
                                    </div>

                                    @error('opening_cash_input_mode')
                                        <div class="alert alert-danger py-2 px-3 small mb-3" id="opening-mode-error" role="alert">{{ $message }}</div>
                                    @enderror

                                    <div id="opening-cash-denominations" @if($openingMode === 'nominal') style="display: none;" @endif>
                                        @error('opening_denominations')
                                            <div class="alert alert-danger py-2 px-3 small mb-3" id="opening-denominations-error" role="alert">{{ $message }}</div>
                                        @enderror

                                        @if($hasEnabledDenominations)
                                            <div class="pos-open-denom-header" aria-hidden="true">
                                                <span>Pecahan</span>
                                                <span>Jumlah</span>
                                                <span class="text-end">Subtotal</span>
                                            </div>

                                            @foreach($cashDenominations->groupBy('type') as $type => $group)
                                                <div class="pos-open-group">
                                                    <div class="pos-open-group-heading">{{ $group->first()->typeLabel() }}</div>
                                                    @foreach($group as $denomination)
                                                        @php
                                                            $oldCountValue = $oldCounts[$denomination->value] ?? '';
                                                            $safeOldCount = is_scalar($oldCountValue) ? (string) $oldCountValue : '';
                                                            $inputId = 'opening_denomination_' . $denomination->value;
                                                            $subtotalId = 'opening_subtotal_' . $denomination->value;
                                                            $describedBy = 'opening-cash-help ' . $subtotalId . ($errors->has('opening_denominations') ? ' opening-denominations-error' : '');
                                                        @endphp
                                                        <div class="pos-open-denom-row">
                                                            <label for="{{ $inputId }}" class="pos-open-denom-label">
                                                                {{ $denomination->label() }}
                                                                <span class="visually-hidden">({{ $denomination->unitLabel() }})</span>
                                                            </label>
                                                            <div class="pos-open-qty-wrap">
                                                                <input type="number"
                                                                    id="{{ $inputId }}"
                                                                    min="0"
                                                                    step="1"
                                                                    inputmode="numeric"
                                                                    class="form-control form-control-sm pos-open-qty-input opening-denomination-count"
                                                                    name="opening_denominations[{{ $denomination->value }}]"
                                                                    data-value="{{ $denomination->value }}"
                                                                    value="{{ $safeOldCount }}"
                                                                    aria-label="Jumlah {{ $denomination->label() }}, {{ $denomination->unitLabel() }}"
                                                                    aria-describedby="{{ $describedBy }}"
                                                                    placeholder="0">
                                                                <span class="pos-open-unit-label" aria-hidden="true">{{ $denomination->unitLabel() }}</span>
                                                            </div>
                                                            <div id="{{ $subtotalId }}"
                                                                class="pos-open-subtotal opening-denomination-subtotal"
                                                                data-value="{{ $denomination->value }}">Rp0</div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endforeach
                                        @else
                                            <div class="alert alert-warning mb-0" id="opening-denomination-empty" role="alert">
                                                Belum ada pecahan uang yang aktif. Hubungi admin untuk mengaktifkan pecahan uang pada konfigurasi
                                                @if($canUseNominalOpening) atau pilih <strong>Input nominal</strong>.@else sebelum membuka sesi dengan terminal.@endif
                                            </div>
                                        @endif
                                    </div>

                                    @if($canUseNominalOpening)
                                        <div id="opening-cash-nominal" @if($openingMode !== 'nominal') style="display: none;" @endif>
                                            <label for="opening_float_total_display" class="form-label fw-medium">
                                                Nominal saldo awal (Rp)
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                                id="opening_float_total_display"
                                                inputmode="numeric"
                                                autocomplete="off"
                                                class="form-control form-control-lg @error('opening_float_total') is-invalid @enderror"
                                                value="{{ is_numeric($oldFloatTotal) ? number_format((float) $oldFloatTotal, 0, ',', '.') : '' }}"
                                                aria-describedby="opening-nominal-help @error('opening_float_total') opening-float-total-error @enderror"
                                                placeholder="0">
                                            <input type="hidden" name="opening_float_total" id="opening_float_total" value="{{ $oldFloatTotal }}">
                                            <div class="text-muted small mt-1" id="opening-nominal-help">Masukkan angka lebih dari nol.</div>
                                            @error('opening_float_total')
                                                <div class="text-danger small mt-1" id="opening-float-total-error" role="alert">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div id="opening-summary-column" class="{{ $hasInitialTerminal ? 'col-12 col-lg-4' : 'col-12' }}">
                                <div class="pos-open-panel pos-open-summary-sticky">
                                    <div id="opening-cash-summary-details" @if(! $hasInitialTerminal) style="display: none;" @endif>
                                        <div class="text-muted small fw-semibold text-uppercase mb-1">Ringkasan saldo awal</div>
                                        <div class="text-muted small">Total saldo awal</div>
                                        <div id="opening-denomination-total" class="pos-open-total-value my-1">Rp0</div>
                                        <div id="opening-total-live-region" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>
                                        <div id="opening-summary-terminal-label" class="text-muted small">
                                            Terminal: {{ $hasInitialTerminal && isset($terminalLabels[$oldTerminalId]) ? $terminalLabels[$oldTerminalId] : '—' }}
                                        </div>
                                        <hr class="my-3">
                                    </div>

                                    <div class="mb-3">
                                        <label for="notes" class="form-label fw-medium mb-1">
                                            Catatan <span class="text-muted fw-normal">(opsional)</span>
                                        </label>
                                        <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ is_scalar(old('notes')) ? old('notes') : '' }}</textarea>
                                        @error('notes')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </div>

                                    <div class="d-grid gap-2">
                                        @if(!$activeSessionInOtherSetting)
                                            <button type="submit" id="opening-submit-btn" class="btn btn-primary">Buka Sesi</button>
                                        @endif
                                        <a href="{{ $backRoute }}" class="btn btn-outline-secondary text-center">Kembali</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>
@endsection

@push('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const formContainer = document.getElementById('saldo-form-container');
    const terminalPickerPanel = document.getElementById('terminal-picker-panel');
    const fieldset = document.getElementById('pos-session-open-fieldset');
    const section = document.getElementById('opening-cash-section');
    const summaryColumn = document.getElementById('opening-summary-column');
    const summaryDetails = document.getElementById('opening-cash-summary-details');
    const summaryTerminalLabel = document.getElementById('opening-summary-terminal-label');
    const modeInput = document.getElementById('opening_cash_input_mode');
    const nominalSwitch = document.getElementById('opening_cash_nominal_switch');
    const headingEl = document.getElementById('opening-cash-heading');
    const helpEl = document.getElementById('opening-cash-help');
    const denominationBox = document.getElementById('opening-cash-denominations');
    const nominalBox = document.getElementById('opening-cash-nominal');
    const totalCell = document.getElementById('opening-denomination-total');
    const liveRegion = document.getElementById('opening-total-live-region');
    const countInputs = Array.from(document.querySelectorAll('.opening-denomination-count'));
    const totalInput = document.getElementById('opening_float_total');
    const displayInput = document.getElementById('opening_float_total_display');
    const terminalLabels = @json($terminalLabels);

    if (!section || !formContainer) {
        return;
    }

    const isFieldsetLocked = Boolean(fieldset && fieldset.hasAttribute('disabled'));
    let lastTerminalVal = null;

    function getTerminalInput() {
        return (terminalPickerPanel || formContainer).querySelector('input[name="terminal_id"]');
    }

    function getCurrentTerminalVal() {
        const terminalInput = getTerminalInput();
        return terminalInput ? String(terminalInput.value || '').trim() : '';
    }

    function setText(el, text) {
        if (el && el.textContent !== text) {
            el.textContent = text;
        }
    }

    function formatRupiah(num) {
        return 'Rp' + (num || 0).toLocaleString('id-ID');
    }

    function setDisabled(container, disabled) {
        if (!container) return;
        const effectiveDisabled = isFieldsetLocked || disabled;
        container.querySelectorAll('input').forEach(function(el) {
            el.disabled = effectiveDisabled;
        });
    }

    function computeCountedTotal() {
        let total = 0;
        countInputs.forEach(function(input) {
            const value = parseInt(input.dataset.value, 10) || 0;
            const raw = input.value.trim();
            const isValidInt = /^\d+$/.test(raw);
            const qty = isValidInt ? parseInt(raw, 10) : 0;
            const subtotal = value * qty;
            total += subtotal;

            if (raw !== '' && !isValidInt) {
                input.classList.add('is-invalid');
            } else {
                input.classList.remove('is-invalid');
            }

            const cell = document.querySelector('.opening-denomination-subtotal[data-value="' + input.dataset.value + '"]');
            setText(cell, formatRupiah(subtotal));
        });
        return total;
    }

    function computeActiveTotal() {
        const nominal = modeInput && modeInput.value === 'nominal';
        const countedTotal = computeCountedTotal();
        if (nominal) {
            const rawNominal = totalInput ? parseInt(String(totalInput.value).replace(/[^0-9]/g, ''), 10) : 0;
            return isNaN(rawNominal) ? 0 : rawNominal;
        }
        return countedTotal;
    }

    function recalc(announce) {
        const activeTotal = computeActiveTotal();
        const formatted = formatRupiah(activeTotal);
        setText(totalCell, formatted);
        if (announce && liveRegion) {
            setText(liveRegion, 'Total saldo awal ' + formatted);
        }
    }

    function updateTerminalSummary(terminalVal) {
        if (!summaryTerminalLabel) return;
        const label = terminalVal && terminalLabels[terminalVal] ? terminalLabels[terminalVal] : '—';
        setText(summaryTerminalLabel, 'Terminal: ' + label);
    }

    function applyState(announce) {
        const terminalVal = getCurrentTerminalVal();
        lastTerminalVal = terminalVal;
        const hasTerminal = terminalVal !== '';
        const nominal = modeInput && modeInput.value === 'nominal';

        section.style.display = hasTerminal ? '' : 'none';
        if (summaryDetails) summaryDetails.style.display = hasTerminal ? '' : 'none';
        if (summaryColumn) {
            summaryColumn.className = hasTerminal ? 'col-12 col-lg-4' : 'col-12';
        }
        updateTerminalSummary(terminalVal);

        if (modeInput) modeInput.disabled = isFieldsetLocked || !hasTerminal;
        if (denominationBox) denominationBox.style.display = nominal ? 'none' : '';
        if (nominalBox) nominalBox.style.display = nominal ? '' : 'none';

        setText(headingEl, nominal ? 'Input saldo awal' : 'Hitung saldo awal');
        setText(
            helpEl,
            nominal
                ? 'Masukkan total uang tunai di laci kasir.'
                : 'Isi jumlah lembar atau keping. Kolom kosong dihitung nol.'
        );
        if (nominalSwitch) {
            nominalSwitch.setAttribute('aria-pressed', nominal ? 'true' : 'false');
            setText(nominalSwitch, nominal ? 'Hitung pecahan' : 'Input nominal');
        }

        setDisabled(denominationBox, !hasTerminal || nominal);
        setDisabled(nominalBox, !hasTerminal || !nominal);

        if (displayInput) {
            if (hasTerminal && nominal) displayInput.setAttribute('required', '');
            else displayInput.removeAttribute('required');
        }

        recalc(Boolean(announce));
    }

    function syncIfTerminalChanged() {
        const currentVal = getCurrentTerminalVal();
        if (currentVal !== lastTerminalVal) {
            applyState(false);
        }
    }

    countInputs.forEach(function(input, idx) {
        input.addEventListener('input', function() {
            recalc(false);
        });
        input.addEventListener('change', function() {
            recalc(true);
        });
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                const nextInput = countInputs[idx + 1];
                if (nextInput && !nextInput.disabled) {
                    nextInput.focus();
                    if (typeof nextInput.select === 'function') {
                        nextInput.select();
                    }
                } else {
                    const notesEl = document.getElementById('notes');
                    const submitBtn = document.getElementById('opening-submit-btn');
                    if (notesEl && !notesEl.disabled) {
                        notesEl.focus();
                    } else if (submitBtn && !submitBtn.disabled) {
                        submitBtn.focus();
                    }
                }
            }
        });
    });

    if (nominalSwitch && modeInput) {
        nominalSwitch.addEventListener('click', function() {
            const nextNominal = modeInput.value !== 'nominal';
            modeInput.value = nextNominal ? 'nominal' : 'denominations';
            applyState(true);
            if (nextNominal && displayInput && !displayInput.disabled) {
                displayInput.focus();
            } else if (!nextNominal && countInputs[0] && !countInputs[0].disabled) {
                countInputs[0].focus();
            }
        });
    }

    if (displayInput && totalInput) {
        displayInput.addEventListener('input', function() {
            const numericVal = parseInt(this.value.replace(/[^0-9]/g, ''), 10);
            totalInput.value = isNaN(numericVal) ? '' : numericVal;
            this.value = isNaN(numericVal) ? '' : numericVal.toLocaleString('id-ID');
            recalc(false);
        });
        displayInput.addEventListener('change', function() {
            recalc(true);
        });
    }

    applyState(false);

    if (terminalPickerPanel) {
        const pickerObserver = new MutationObserver(function() {
            syncIfTerminalChanged();
        });
        pickerObserver.observe(terminalPickerPanel, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['value']
        });
    }

    window.addEventListener('terminal-selected', function() {
        setTimeout(syncIfTerminalChanged, 0);
    });
    window.addEventListener('terminal-cleared', function() {
        setTimeout(syncIfTerminalChanged, 0);
    });
    document.addEventListener('livewire:init', function() {
        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('commit', function({ succeed }) {
                succeed(function() {
                    queueMicrotask(syncIfTerminalChanged);
                });
            });
        }
    });
});
</script>
@endpush
