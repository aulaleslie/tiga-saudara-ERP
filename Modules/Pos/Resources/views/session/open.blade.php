@extends('layouts.app')

@section('title', 'Buka Sesi POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Buka Sesi POS</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pos.sessions.store') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="terminal_search">Terminal <span class="text-danger">*</span></label>
                                <livewire:modules.pos.pos-terminal-search-dropdown
                                    name="terminal_id"
                                    placeholder="Pilih terminal..."
                                    :selected="old('terminal_id')"
                                    :error="$errors->first('terminal_id')"
                                    wire:key="pos-terminal-dropdown"
                                />
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="opening_float_total_display" class="form-label">Total Saldo Awal <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="text" id="opening_float_total_display"
                                           class="form-control @error('opening_float_total') is-invalid @enderror"
                                           value="{{ old('opening_float_total') ? number_format(old('opening_float_total'), 0, ',', '.') : '' }}"
                                           placeholder="0" required>
                                </div>
                                <input type="hidden" name="opening_float_total" id="opening_float_total" value="{{ old('opening_float_total') }}">
                                @error('opening_float_total')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    @php
                        $denominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];
                    @endphp

                    <div class="mb-3">
                        <label class="form-label d-block">Pecahan Saldo Awal (Opsional jika terminal mengizinkan total saja)</label>
                        <div class="row">
                            @foreach($denominations as $denomination)
                                <div class="col-6 col-md-4 col-lg-3 mb-2">
                                    <label class="form-label small" for="denom_{{ $denomination }}">{{ number_format($denomination, 0, ',', '.') }}</label>
                                    <input type="number" min="0" step="1"
                                           id="denom_{{ $denomination }}"
                                           name="opening_denominations[{{ $denomination }}]"
                                           value="{{ old('opening_denominations.' . $denomination) }}"
                                           class="form-control @error('opening_denominations') is-invalid @enderror">
                                </div>
                            @endforeach
                        </div>
                        @error('opening_denominations')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Catatan</label>
                        <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Buka Sesi</button>
                        <a href="{{ route('pos.sell') }}" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const denominations = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];
    const totalInput = document.getElementById('opening_float_total');
    const displayInput = document.getElementById('opening_float_total_display');
    const denomInputs = denominations.map(d => document.getElementById('denom_' + d));

    function formatNumber(num) {
        if (!num) return '';
        return parseInt(num, 10).toLocaleString('id-ID');
    }

    function parseFormattedNumber(str) {
        return parseInt(str.replace(/[^0-9]/g, ''), 10) || null;
    }

    function recalcTotal() {
        let sum = 0;
        let hasDenomValue = false;

        denominations.forEach((denomValue, index) => {
            const count = parseInt(denomInputs[index].value) || 0;
            if (count > 0) {
                hasDenomValue = true;
            }
            sum += denomValue * count;
        });

        if (hasDenomValue) {
            totalInput.value = sum;
            displayInput.value = formatNumber(sum);
            displayInput.readOnly = true;
            displayInput.classList.add('bg-light');
        } else {
            // When denominators are empty, don't clear the value completely,
            // just make it editable so user can use the "total only" policy
            displayInput.readOnly = false;
            displayInput.classList.remove('bg-light');
            
            // if we stripped denom values, ensure the display format matches the total
            const currentValue = totalInput.value;
            displayInput.value = formatNumber(currentValue);
        }
    }

    denomInputs.forEach(input => {
        if (input) {
            input.addEventListener('input', recalcTotal);
        }
    });
    
    // Allow manual entry on display input when not readonly
    displayInput.addEventListener('input', function(e) {
        if (this.readOnly) return;
        
        let cursorPosition = this.selectionStart;
        let oldLength = this.value.length;
        
        // Strip non-numeric, set hidden input
        let numericVal = parseFormattedNumber(this.value);
        totalInput.value = numericVal !== null ? numericVal : '';
        
        // Format display
        this.value = formatNumber(numericVal);
        
        // Restore cursor position roughly
        let newLength = this.value.length;
        cursorPosition = cursorPosition + (newLength - oldLength);
        this.setSelectionRange(cursorPosition, cursorPosition);
    });

    // Run once on load in case of old() values
    recalcTotal();
});
</script>
@endpush

