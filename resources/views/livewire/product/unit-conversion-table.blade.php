<div>
<div class="unit-conversion-wrapper" style="overflow: visible;" x-data="unitConversionFormatter()">
    <div class="table-responsive unit-conversion-table" style="overflow-x: auto; overflow-y: visible;">
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>ke Unit</th>
                <th>Faktor Konversi</th>
                <th>Barcode</th>
                <th>Harga</th>
                <th class="text-end" style="white-space: nowrap;">
                    Aksi
                    <button type="button"
                            class="btn btn-outline-primary btn-sm ms-2"
                            wire:click="addConversionRow"
                        @disabled($locked)>
                        <i class="bi bi-plus"></i>
                    </button>
                </th>
            </tr>
            </thead>
            <tbody>
            @foreach($conversions as $index => $conversion)
                @php($rowKey = $rowKeys[$index] ?? ('conv_'.$index))
                <tr wire:key="conv-row-{{ $rowKey }}">
                    <input type="hidden"
                           name="conversions[{{ $index }}][id]"
                           value="{{ $conversion['id'] ?? '' }}">
                    <td style="min-width: 220px;">
                        <livewire:modules.product.unit-search-dropdown
                            :options="$units"
                            :selected="$conversion['unit_id'] ?? null"
                            name="conversions[{{ $index }}][unit_id]"
                            placeholder="Pilih unit..."
                            :allow-create="true"
                            :error="$errors['conversions.' . $index . '.unit_id'][0] ?? null"
                            width="220px"
                            wire:key="unit-dropdown-{{ $rowKey }}"
                        />
                    </td>
                    <td>
                        <input type="number" name="conversions[{{ $index }}][conversion_factor]"
                               class="form-control {{ isset($errors['conversions.' . $index . '.conversion_factor']) ? 'is-invalid' : '' }}"
                               step="0.0001"
                               value="{{ $conversion['conversion_factor'] }}">
                        @if(isset($errors['conversions.' . $index . '.conversion_factor']))
                            <span class="invalid-feedback"
                                  role="alert"><strong>{{ $errors['conversions.' . $index . '.conversion_factor'][0] }}</strong></span>
                        @endif
                    </td>
                    <td>
                        <input type="text" name="conversions[{{ $index }}][barcode]"
                               class="form-control {{ isset($errors['conversions.' . $index . '.barcode']) ? 'is-invalid' : '' }}"
                               value="{{ $conversion['barcode'] }}">
                        @if(isset($errors['conversions.' . $index . '.barcode']))
                            <span class="invalid-feedback"
                                  role="alert"><strong>{{ $errors['conversions.' . $index . '.barcode'][0] }}</strong></span>
                        @endif
                    </td>
                    <td>
                        <input type="text" 
                               name="conversions[{{ $index }}][price]"
                               class="form-control price-input {{ isset($errors['conversions.' . $index . '.price']) ? 'is-invalid' : '' }}"
                               value="{{ formatRupiah($conversion['price'] ?? 0) }}"
                               @focus="$el.value = toPlainNumber($el.value)"
                               @blur="formatPriceField($el)"
                               inputmode="decimal"
                               wire:change="updateConversionPrice({{ $index }}, $event.target.value)"
                        />
                        @if(isset($errors['conversions.' . $index . '.price']))
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $errors['conversions.' . $index . '.price'][0] }}</strong>
                            </span>
                        @endif
                    </td>

                    <td>
                        <button type="button" class="btn btn-danger" wire:click="removeConversionRow('{{ $rowKey }}')">
                            Hapus
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<style>
    /* Keep dropdown menus from being clipped by scroll containers */
    .unit-conversion-wrapper,
    .unit-conversion-wrapper .unit-conversion-table {
        overflow: visible !important;
    }
</style>

<script>
function formatRupiah(amount) {
    const numeric = parseFloat(amount) || 0;
    return 'RP' + new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(numeric);
}

function unitConversionFormatter() {
    return {
        formatPriceField(element) {
            const rawValue = element.value;
            const numericValue = this.parseCurrencyInput(rawValue);
            element.value = formatRupiah(numericValue);
        },

        toPlainNumber(value) {
            const num = parseFloat(value);
            return Number.isFinite(num) ? num.toString() : '0';
        },

        parseCurrencyInput(value) {
            if (value === null || value === undefined) return 0;
            const cleaned = value
                .toString()
                .replace(/[^0-9,.-]/g, '');
            const withoutThousands = cleaned.replace(/\./g, '');
            const normalized = withoutThousands.replace(',', '.');
            const parsed = parseFloat(normalized);
            return isNaN(parsed) ? 0 : parsed;
        }
    };
}
</script>
</div>
