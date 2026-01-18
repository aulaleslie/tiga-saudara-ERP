<div class="modal fade" id="productQuickAddModal" tabindex="-1" aria-labelledby="productQuickAddModalLabel" aria-hidden="true" wire:ignore.self>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
             <form wire:submit.prevent="save">
                <div class="modal-header">
                    <h5 class="modal-title" id="productQuickAddModalLabel">Tambah Produk Baru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" wire:click="closeModal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body overflow-auto" style="max-height: 70vh;">
                    <!-- Basic Fields -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                             <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                             <input type="text" class="form-control" wire:model="product_name" placeholder="Nama Produk">
                             @error('product_name') <span class="text-danger">{{ $message }}</span> @enderror
                        </div>
                         <div class="col-md-6 mb-3">
                             <label class="form-label">Kode Produk</label>
                             <input type="text" class="form-control" wire:model="product_code" placeholder="Auto-generate jika kosong">
                             @error('product_code') <span class="text-danger">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    
                    <!-- Category & Brand -->
                    <div class="row">
                         <div class="col-md-6 mb-3">
                             <label class="form-label">Kategori <span class="text-danger">*</span></label>
                             <div class="d-flex">
                                 <div class="flex-grow-1">
                                     <livewire:modules.product.category-search-dropdown 
                                         name="category_id" 
                                         :selected="$category_id"
                                         dispatch-to="modules.product.modals.product-quick-add-modal"
                                         :allow-create="true"
                                         wire:key="'quick-product-category-'.$formResetVersion"
                                     />
                                 </div>
                             </div>
                             @error('category_id') <span class="text-danger">{{ $message }}</span> @enderror
                        </div>
                         <div class="col-md-6 mb-3">
                             <label class="form-label">Merek</label>
                             <div class="d-flex">
                                 <div class="flex-grow-1">
                                     <livewire:modules.product.brand-search-dropdown 
                                         name="brand_id" 
                                         :selected="$brand_id" 
                                         dispatch-to="modules.product.modals.product-quick-add-modal"
                                         :allow-create="true"
                                         wire:key="'quick-product-brand-'.$formResetVersion"
                                     />
                                 </div>
                             </div>
                        </div>
                    </div>

                    <!-- Unit & Stock Management -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Unit Utama <span class="text-danger">*</span></label>
                             <div class="d-flex">
                                 <div class="flex-grow-1">
                                     <livewire:modules.product.unit-search-dropdown 
                                         name="unit_id"
                                         :selected="$unit_id"
                                         dispatch-to="modules.product.modals.product-quick-add-modal"
                                         :allow-create="true"
                                         wire:key="'quick-product-unit-'.$formResetVersion"
                                     />
                                 </div>
                             </div>
                             @error('unit_id') <span class="text-danger">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                             <label class="form-label">Barcode (Unit Utama)</label>
                             <input type="text" class="form-control" wire:model="barcode">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="stockManaged" wire:model.live="stock_managed">
                        <label class="form-check-label" for="stockManaged">Manajemen Stok</label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="serialRequired" wire:model.live="serial_number_required" @disabled(!$stock_managed)>
                        <label class="form-check-label" for="serialRequired">Serial Number Diperlukan</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Peringatan Stok Minimum</label>
                        <input type="number" class="form-control" wire:model="product_stock_alert" @disabled(!$stock_managed)>
                    </div>

                    @if($stock_managed)
                        <!-- Conversions Table -->
                        <div class="card mb-3" style="overflow: visible;">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Konversi Unit</span>
                                <button type="button" class="btn btn-sm btn-primary" wire:click="addConversionRow">+ Tambah</button>
                            </div>
                            <div class="card-body p-0" style="overflow: visible;">
                                <div class="table-responsive" style="overflow: visible;">
                                    <table class="table table-bordered mb-0">
                                        <thead>
                                            <tr>
                                                <th>Unit</th>
                                                <th>Faktor</th>
                                                <th>Barcode</th>
                                                <th>Harga Beli (Ref)</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($conversions as $index => $conv)
                                                @php($rowKey = $rowKeys[$index] ?? $index)
                                                <tr wire:key="conv-row-{{ $rowKey }}">
                                                    <td style="min-width: 200px">
                                                         <livewire:modules.product.unit-search-dropdown 
                                                             name="conversions.{{ $index }}.unit_id"
                                                             :selected="$conv['unit_id']"
                                                             dispatch-to="modules.product.modals.product-quick-add-modal"
                                                             :allow-create="false"
                                                             width="100%"
                                                             wire:key="'conv-unit-'.$rowKey.'-'.$formResetVersion"
                                                         />
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control" wire:model="conversions.{{ $index }}.conversion_factor" step="any" placeholder="10">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control" wire:model="conversions.{{ $index }}.barcode" placeholder="Barcode">
                                                    </td>
                                                    <td>
                                                        <input
                                                            type="text"
                                                            class="form-control conversion-price-input price-mask"
                                                            x-data="currencyField('displayPrices.{{ $index }}', @js($displayPrices[$index] ?? 0), productCurrency, (raw, wire) => wire && wire.call('syncPrice', {{ $index }}))"
                                                            x-model="display"
                                                            x-on:focus="onFocus($event)"
                                                            x-on:input="onInput($event)"
                                                            x-on:blur="onBlur($event)"
                                                            inputmode="decimal"
                                                            autocomplete="off"
                                                        >
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger" wire:click="removeConversionRow('{{ $rowKey }}')">Hapus</button>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif

                    <hr>

                    <!-- Purchasing -->
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="isPurchased" wire:model.live="is_purchased">
                        <label class="form-check-label" for="isPurchased">Item Dibeli</label>
                    </div>
                    @if($is_purchased)
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Harga Beli</label>
                                <input
                                    type="text"
                                    class="form-control price-mask"
                                    x-data="currencyField('purchase_price', @js($purchase_price ?? 0), productCurrency)"
                                    x-model="display"
                                    x-on:focus="onFocus($event)"
                                    x-on:input="onInput($event)"
                                    x-on:blur="onBlur($event)"
                                    inputmode="decimal"
                                    autocomplete="off"
                                >
                                @error('purchase_price') <span class="text-danger">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Pajak Beli</label>
                                <livewire:modules.product.tax-search-dropdown 
                                     name="purchase_tax_id"
                                     :selected="$purchase_tax_id"
                                     dispatch-to="modules.product.modals.product-quick-add-modal"
                                     wire:key="'quick-product-tax-buy-'.$formResetVersion"
                                />
                            </div>
                        </div>
                    @endif

                    <!-- Selling -->
                    <div class="form-check form-switch mb-2">
                         <input class="form-check-input" type="checkbox" id="isSold" wire:model.live="is_sold">
                         <label class="form-check-label" for="isSold">Item Dijual</label>
                    </div>
                    @if($is_sold)
                        <div class="row mb-3">
                             <div class="col-md-4">
                                <label class="form-label">Harga Jual</label>
                                <input
                                    type="text"
                                    class="form-control price-mask"
                                    x-data="currencyField('sale_price', @js($sale_price ?? 0), productCurrency)"
                                    x-model="display"
                                    x-on:focus="onFocus($event)"
                                    x-on:input="onInput($event)"
                                    x-on:blur="onBlur($event)"
                                    inputmode="decimal"
                                    autocomplete="off"
                                >
                                @error('sale_price') <span class="text-danger">{{ $message }}</span> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tier 1</label>
                                <input
                                    type="text"
                                    class="form-control price-mask"
                                    x-data="currencyField('tier_1_price', @js($tier_1_price ?? 0), productCurrency)"
                                    x-model="display"
                                    x-on:focus="onFocus($event)"
                                    x-on:input="onInput($event)"
                                    x-on:blur="onBlur($event)"
                                    inputmode="decimal"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tier 2</label>
                                <input
                                    type="text"
                                    class="form-control price-mask"
                                    x-data="currencyField('tier_2_price', @js($tier_2_price ?? 0), productCurrency)"
                                    x-model="display"
                                    x-on:focus="onFocus($event)"
                                    x-on:input="onInput($event)"
                                    x-on:blur="onBlur($event)"
                                    inputmode="decimal"
                                    autocomplete="off"
                                >
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Pajak Jual</label>
                                <livewire:modules.product.tax-search-dropdown 
                                     name="sale_tax_id"
                                     :selected="$sale_tax_id"
                                     dispatch-to="modules.product.modals.product-quick-add-modal"
                                     wire:key="'quick-product-tax-sell-'.$formResetVersion"
                                />
                            </div>
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea class="form-control" wire:model="note" rows="2"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" wire:click="closeModal">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan Produk</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Nested Modals -->
    <livewire:modules.product.modals.category-quick-add-modal wire:key="nested-cat-quick-add" />
    <livewire:modules.product.modals.brand-quick-add-modal wire:key="nested-brand-quick-add" />
    <livewire:modules.setting.modals.unit-quick-add-modal wire:key="nested-unit-quick-add" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="nested-tax-quick-add" />
</div>

<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('openProductModal', () => {
            $('#productQuickAddModal').modal('show');
        });

        Livewire.on('productCreated', () => {
             $('#productQuickAddModal').modal('hide');
        });
    });

    // Currency helpers to mirror product create formatting
    const productCurrency = {!! json_encode([
        'prefix' => settings()->currency->symbol,
        'thousands' => settings()->currency->thousand_separator,
        'decimal' => settings()->currency->decimal_separator,
    ], JSON_THROW_ON_ERROR) !!};

    document.addEventListener('alpine:init', () => {
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
                if (value === null || value === undefined) return 0;
                const thousands = cfg.thousands ?? ',';
                const decimal = cfg.decimal ?? '.';
                let str = String(value);
                if (cfg.prefix) {
                    const escaped = cfg.prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    str = str.replace(new RegExp(escaped, 'g'), '');
                }
                str = str.replace(new RegExp('\\' + thousands, 'g'), '');
                str = str.replace(new RegExp('[^0-9\\' + decimal + '\\-]', 'g'), '');
                if (decimal !== '.') {
                    str = str.replace(new RegExp('\\' + decimal, 'g'), '.');
                }
                const num = parseFloat(str);
                return isNaN(num) ? 0 : num;
            };

            return {
                display: '',
                init() {
                    const raw = parseCurrency(initial);
                    this.display = formatCurrency(raw);
                },
                updateWire(raw) {
                    if (!field || !this.$wire) return;
                    this.$wire.set(field, raw);
                },
                onFocus(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = raw === 0 ? '' : String(raw);
                    this.$nextTick(() => event.target.select());
                },
                onInput(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = event.target.value;
                    this.updateWire(raw);
                },
                onBlur(event) {
                    const raw = parseCurrency(event.target.value);
                    this.display = formatCurrency(raw);
                    this.updateWire(raw);
                    if (typeof afterBlur === 'function') {
                        afterBlur(raw, this.$wire);
                    }
                }
            };
        };
    });
</script>
