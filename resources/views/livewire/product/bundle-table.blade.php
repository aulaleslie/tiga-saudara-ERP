<div>
    <div class="card" style="overflow: visible;">
        <div class="card-body bundle-items-table" style="overflow: visible;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Item Paket</h5>
                <button type="button"
                        class="btn btn-outline-primary btn-sm"
                        wire:click="addItem">
                    <i class="bi bi-plus"></i> Tambah
                </button>
            </div>
            <div class="table-responsive" style="overflow-x: auto; overflow-y: visible;">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Jumlah (min 1)</th>
                        <th>Harga Informasi Item</th>
                        <th class="text-end" style="white-space: nowrap;">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $index => $item)
                        @php
                            $rowKey = $rowKeys[$index] ?? ('bundle_'.$index);
                        @endphp
                        <tr wire:key="bundle-row-{{ $rowKey }}">
                            <td style="min-width: 220px;">
                                <livewire:modules.product.product-search-dropdown
                                    :index="$rowKey"
                                    wire:key="psd-{{ $rowKey }}"
                                    :selected="$item['product_id']"
                                />
                                @error("items.$index.product_id")
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </td>
                            <td>
                                <input
                                    type="number"
                                    name="items[{{ $index }}][quantity]"
                                    value="{{ $item['quantity'] }}"
                                    wire:model.blur="items.{{ $index }}.quantity"
                                    class="form-control"
                                    min="1">
                                @error("items.$index.quantity")
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </td>
                            <td wire:key="price-col-{{ $rowKey }}-{{ $index }}">
                                <input
                                    type="text"
                                    class="form-control price-mask {{ ($item['informational_item_price'] === null && $item['product_id']) || $errors->has("items.$index.informational_item_price") ? 'is-invalid' : '' }}"
                                    wire:key="bundle-price-{{ $rowKey }}-{{ $item['product_id'] }}-{{ $item['informational_item_price'] }}"
                                    x-data="currencyField('items.{{ $index }}.informational_item_price', @js($item['informational_item_price']), productCurrency)"
                                    x-model="display"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    readonly>
                                @if($item['informational_item_price'] === null && $item['product_id'])
                                    <div class="invalid-feedback d-block">
                                        Harga jual tidak ditemukan untuk produk ini.
                                    </div>
                                @endif
                                @error("items.$index.informational_item_price")
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </td>
                            <td class="text-end">
                                @php
                                    $isOnlyItem = count($items) <= 1;
                                    $btnTitle = $isOnlyItem ? 'Paket harus memiliki minimal satu item' : 'Hapus item';
                                    $btnDisabled = $isOnlyItem ? 'disabled' : '';
                                @endphp
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    wire:click="removeItem('{{ $rowKey }}')"
                                    {{ $btnDisabled }}
                                    title="{{ $btnTitle }}">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Hidden inputs to pass bundle items data when the parent form is submitted -->
    @foreach($items as $index => $item)
        <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] }}">
    @endforeach

    <style>
        .bundle-items-table {
            overflow: visible !important;
        }
        .bundle-items-table .table-responsive {
            overflow: visible !important;
        }
        .bundle-items-table .dropdown-menu {
            z-index: 5000;
        }
    </style>

    <script>
    if (typeof productCurrency === 'undefined') {
        var productCurrency = window.productCurrency = {!! json_encode([
            'prefix' => 'Rp ',
            'thousands' => '.',
            'decimal' => ',',
        ], JSON_THROW_ON_ERROR) !!};
    }

    document.addEventListener('alpine:init', () => {
        if (window.currencyField) {
            return;
        }

        window.currencyField = function (field, initial = 0, cfg = productCurrency, afterBlur = null) {
            const formatCurrency = (num) => {
                const prefix = cfg.prefix ?? '';
                const thousands = cfg.thousands ?? ',';
                const decimal = cfg.decimal ?? '.';
                const sign = num < 0 ? '-' : '';
                const n = Math.abs(num || 0).toFixed(2);
                const [intPart, frac] = n.split('.');
                const withThousands = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
                return `${sign}${prefix}${withThousands}${decimal}${frac}`;
            };

            const parseCurrency = (value) => {
                if (value === null || value === undefined || value === '') return null;
                if (typeof value === 'number') return value;
                
                let str = String(value).trim();
                if (str === '') return null;

                // If it looks like a standard float from PHP (dot for decimal, no thousands), parse it directly.
                if (/^-?\d+(\.\d+)?$/.test(str)) {
                    return parseFloat(str);
                }

                const thousands = cfg.thousands ?? ',';
                const decimal = cfg.decimal ?? '.';
                if (cfg.prefix) {
                    const escaped = cfg.prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    str = str.replace(new RegExp(escaped, 'gi'), ''); // Case insensitive for 'Rp'
                }
                str = str.replace(new RegExp('\\' + thousands, 'g'), '');
                str = str.replace(new RegExp('[^0-9\\' + decimal + '\\-]', 'g'), '');
                if (decimal !== '.') {
                    str = str.replace(new RegExp('\\' + decimal, 'g'), '.');
                }
                const num = parseFloat(str);
                return isNaN(num) ? null : num;
            };

            return {
                display: '',
                raw: null,
                init() {
                    this.raw = parseCurrency(initial);
                    this.display = this.raw === null ? '' : formatCurrency(this.raw);

                    // Add watch to sync when Livewire property changes (e.g. cleared by PHP)
                    if (field && this.$wire) {
                        this.$wire.$watch(field, (value) => {
                            this.raw = parseCurrency(value);
                            this.display = this.raw === null ? '' : formatCurrency(this.raw);
                        });
                    }
                },
                updateWire(raw) {
                    this.raw = raw;
                    if (!field || !this.$wire) return;
                    // Only update if value actually changed to avoid cycles
                    const current = parseCurrency(this.$wire.get(field));
                    if (current !== raw) {
                        this.$wire.set(field, raw);
                    }
                },
                onFocus(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = raw === null || raw === 0 ? '' : String(raw);
                    this.$nextTick(() => event.target.select());
                },
                onInput(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = event.target.value;
                    this.raw = raw;
                },
                onBlur(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = raw === null ? '' : formatCurrency(raw);
                    this.updateWire(raw);
                    if (typeof afterBlur === 'function') {
                        afterBlur(raw, this.$wire);
                    }
                }
            };
        };
    });
    </script>
</div>
