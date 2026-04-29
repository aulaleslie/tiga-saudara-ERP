<div class="modal" id="productQuickAddModal" tabindex="-1" aria-labelledby="productQuickAddModalLabel" aria-hidden="true" wire:ignore.self data-coreui-backdrop="false" data-coreui-keyboard="false">
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
                    @if($context === 'sale')
                        <div class="alert alert-info">
                            Produk yang dibuat dari modal ini akan langsung dipakai di keranjang penjualan. Harga jual wajib tersedia sebelum produk bisa diproses.
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <strong>Periksa kembali data yang Anda masukkan.</strong>
                            <ul class="mb-0 mt-2">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-md-6 mb-3" wire:key="quick-product-name-container-{{ $formResetVersion }}">
                                    <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model="product_name" placeholder="Nama Produk" wire:key="quick-product-name-input-{{ $formResetVersion }}">
                                    @error('product_name') <span class="text-danger">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-6 mb-3" wire:key="quick-product-code-container-{{ $formResetVersion }}">
                                    <label class="form-label">Kode Produk</label>
                                    <input type="text" class="form-control" wire:model="product_code" placeholder="Auto-generate jika kosong" wire:key="quick-product-code-input-{{ $formResetVersion }}">
                                    <small class="form-text text-muted">Biarkan kosong for auto-generate (SKU-000001, SKU-000002, dll.)</small>
                                    @error('product_code') <span class="text-danger">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Kategori</label>
                                    <livewire:modules.product.category-search-dropdown
                                        name="category_id"
                                        placeholder="Pilih kategori..."
                                        :selected="$category_id"
                                        :clearable="true"
                                        :allow-create="true"
                                        :error="$errors->first('category_id')"
                                        modal-event="openNestedCategoryModal"
                                        dispatch-to="modules.product.modals.product-quick-add-modal"
                                        wire:key="quick-product-category-{{ $formResetVersion }}"
                                    />
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Merek</label>
                                    <livewire:modules.product.brand-search-dropdown
                                        name="brand_id"
                                        placeholder="Pilih merek..."
                                        :selected="$brand_id"
                                        :clearable="true"
                                        :allow-create="true"
                                        :error="$errors->first('brand_id')"
                                        modal-event="openNestedBrandModal"
                                        dispatch-to="modules.product.modals.product-quick-add-modal"
                                        wire:key="quick-product-brand-{{ $formResetVersion }}"
                                    />
                                </div>
                            </div>

                            <div class="border p-3 mb-3">
                                <div class="form-group mb-0">
                                    <input type="checkbox"
                                           id="modal_is_purchased"
                                           wire:model.live="is_purchased"
                                           checked
                                           disabled>
                                    <label for="modal_is_purchased"><strong>Saya Beli Barang Ini</strong></label>

                                    <div class="row mt-3" wire:key="purchase-price-container-{{ $formResetVersion }}">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Harga Beli <span class="text-danger">*</span></label>
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
                                                input-id="quick_purchase_tax_id"
                                                placeholder="Pilih pajak..."
                                                :selected="$purchase_tax_id"
                                                :clearable="true"
                                                :allow-create="true"
                                                :error="$errors->first('purchase_tax_id')"
                                                modal-event="openNestedTaxModal"
                                                dispatch-to="modules.product.modals.product-quick-add-modal"
                                                wire:key="quick-product-tax-buy-{{ $formResetVersion }}"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="border p-3 mb-3">
                                <div class="form-group mb-0">
                                    <input type="checkbox"
                                           id="modal_is_sold"
                                           wire:model.live="is_sold"
                                           @disabled($context === 'sale')
                                           wire:key="quick-product-is-sold-check-{{ $formResetVersion }}">
                                    <label for="modal_is_sold"><strong>Saya Jual Barang Ini</strong></label>

                                    <div
                                        class="mt-3 {{ $is_sold ? '' : 'd-none' }}"
                                        wire:key="sale-price-section-v{{ $formResetVersion }}"
                                    >
                                        <div class="row" wire:key="sale-price-container-v{{ $formResetVersion }}">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Harga Jual @if($context === 'sale')<span class="text-danger">*</span>@endif</label>
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
                                                    @disabled(!$is_sold)
                                                >
                                                @error('sale_price') <span class="text-danger">{{ $message }}</span> @enderror
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Pajak Jual</label>
                                                <livewire:modules.product.tax-search-dropdown
                                                    name="sale_tax_id"
                                                    input-id="quick_sale_tax_id"
                                                    placeholder="Pilih pajak..."
                                                    :selected="$sale_tax_id"
                                                    :clearable="true"
                                                    :allow-create="true"
                                                    :error="$errors->first('sale_tax_id')"
                                                    modal-event="openNestedTaxModal"
                                                    dispatch-to="modules.product.modals.product-quick-add-modal"
                                                    wire:key="quick-product-tax-sell-v{{ $formResetVersion }}-{{ $is_sold ? 'on' : 'off' }}"
                                                    :disabled="!$is_sold"
                                                />
                                            </div>
                                        </div>

                                        <div class="row mt-3" wire:key="tier-1-price-container-v{{ $formResetVersion }}">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Harga Jual Partai Besar</label>
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
                                                    @disabled(!$is_sold)
                                                >
                                                @error('tier_1_price') <span class="text-danger">{{ $message }}</span> @enderror
                                                @if($context === 'sale')
                                                    <small class="form-text text-muted">Kosongkan untuk memakai harga jual utama.</small>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="row mt-3" wire:key="tier-2-price-container-v{{ $formResetVersion }}">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Harga Jual Reseller</label>
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
                                                    @disabled(!$is_sold)
                                                >
                                                @error('tier_2_price') <span class="text-danger">{{ $message }}</span> @enderror
                                                @if($context === 'sale')
                                                    <small class="form-text text-muted">Kosongkan untuk memakai harga jual utama.</small>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <fieldset id="stock-dependent" class="mt-4">
                                <div class="form-row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>
                                                <input type="checkbox"
                                                       id="stock_managed"
                                                       wire:model.live="stock_managed"
                                                       checked
                                                       disabled>
                                                <strong>Manajemen Stok</strong>
                                            </label>
                                            <p class="help-block"><i>Aktifkan opsi ini jika Anda ingin mengelola stok untuk produk ini.</i></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row mt-2">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <input type="checkbox"
                                                   id="serial_number_required"
                                                   wire:model.live="serial_number_required"
                                                   @disabled(!$stock_managed)>
                                            <label for="serial_number_required"><strong>Serial Number Diperlukan</strong></label>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row mt-2">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Peringatan Jumlah Stok</label>
                                        <input type="number" class="form-control" wire:model="product_stock_alert" min="0" @disabled(!$stock_managed)>
                                        @error('product_stock_alert') <span class="text-danger">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="form-row mt-2">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Unit Utama <span class="text-danger">*</span></label>
                                        <livewire:modules.product.unit-search-dropdown
                                            name="base_unit_id"
                                            placeholder="Pilih unit..."
                                            :selected="$base_unit_id"
                                            :allow-create="true"
                                            :error="$errors->first('base_unit_id')"
                                            modal-event="openNestedUnitModal"
                                            width="100%"
                                            :disabled="!$stock_managed"
                                            dispatch-to="modules.product.modals.product-quick-add-modal"
                                            wire:key="quick-product-base-unit-{{ $formResetVersion }}"
                                        />
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Barcode Unit Utama</label>
                                        <input type="text" class="form-control" wire:model="barcode" @disabled(!$stock_managed)>
                                        @error('barcode') <span class="text-danger">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                @if($stock_managed)
                                    <div class="form-row mt-3">
                                        <div class="col-lg-12">
                                            <div class="card" style="overflow: visible;">
                                                <div class="card-body unit-conversion-table" style="overflow: visible;">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h5 class="mb-0">Konversi Unit</h5>
                                                        <button type="button" class="btn btn-outline-primary btn-sm" wire:click="addConversionRow">
                                                            <i class="bi bi-plus"></i> Tambah
                                                        </button>
                                                    </div>
                                                    <div class="table-responsive" style="overflow-x: auto; overflow-y: visible;">
                                                        <table class="table table-bordered mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>ke Unit</th>
                                                                    <th>Faktor Konversi</th>
                                                                    <th>Barcode</th>
                                                                    <th>Harga</th>
                                                                    <th class="text-end" style="white-space: nowrap;">Aksi</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($conversions as $index => $conv)
                                                                    @php($rowKey = $rowKeys[$index] ?? $index)
                                                                    <tr wire:key="conv-row-{{ $rowKey }}">
                                                                        <td style="min-width: 220px;">
                                                                            <livewire:modules.product.unit-search-dropdown
                                                                                name="conversions.{{ $index }}.unit_id"
                                                                                :selected="$conv['unit_id'] ?? null"
                                                                                placeholder="Pilih unit..."
                                                                                :allow-create="true"
                                                                                :error="$errors->first('conversions.' . $index . '.unit_id')"
                                                                                modal-event="openNestedUnitModal"
                                                                                width="220px"
                                                                                dispatch-to="modules.product.modals.product-quick-add-modal"
                                                                                wire:key="conv-unit-{{ $rowKey }}-{{ $formResetVersion }}"
                                                                            />
                                                                        </td>
                                                                        <td>
                                                                            <input type="number" class="form-control {{ $errors->has('conversions.' . $index . '.conversion_factor') ? 'is-invalid' : '' }}" wire:model="conversions.{{ $index }}.conversion_factor" step="0.0001" placeholder="10">
                                                                            @error('conversions.' . $index . '.conversion_factor') <span class="invalid-feedback d-block"><strong>{{ $message }}</strong></span> @enderror
                                                                        </td>
                                                                        <td>
                                                                            <input type="text" class="form-control {{ $errors->has('conversions.' . $index . '.barcode') ? 'is-invalid' : '' }}" wire:model="conversions.{{ $index }}.barcode" placeholder="Barcode">
                                                                            @error('conversions.' . $index . '.barcode') <span class="invalid-feedback d-block"><strong>{{ $message }}</strong></span> @enderror
                                                                        </td>
                                                                        <td wire:key="conv-price-{{ $rowKey }}-{{ $formResetVersion }}">
                                                                            <input
                                                                                type="text"
                                                                                class="form-control conversion-price-input {{ $errors->has('conversions.' . $index . '.price') ? 'is-invalid' : '' }}"
                                                                                x-data="currencyField('displayPrices.{{ $index }}', @js($displayPrices[$index] ?? 0), productCurrency, (raw, wire) => wire && wire.call('syncPrice', {{ $index }}))"
                                                                                x-model="display"
                                                                                x-on:focus="onFocus($event)"
                                                                                x-on:input="onInput($event)"
                                                                                x-on:blur="onBlur($event)"
                                                                                inputmode="decimal"
                                                                                autocomplete="off"
                                                                            >
                                                                            @error('conversions.' . $index . '.price') <span class="invalid-feedback d-block"><strong>{{ $message }}</strong></span> @enderror
                                                                        </td>
                                                                        <td class="text-end">
                                                                            <button type="button" class="btn btn-danger" wire:click="removeConversionRow('{{ $rowKey }}')">Hapus</button>
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </fieldset>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" wire:click="closeModal">Batal</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="save">Simpan Produk</span>
                        <span wire:loading wire:target="save">Memproses…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <livewire:modules.product.modals.category-quick-add-modal wire:key="nested-cat-quick-add" listen-event="openNestedCategoryModal" />
    <livewire:modules.product.modals.brand-quick-add-modal wire:key="nested-brand-quick-add" listen-event="openNestedBrandModal" />
    <livewire:modules.setting.modals.unit-quick-add-modal wire:key="nested-unit-quick-add" listen-event="openNestedUnitModal" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="nested-tax-quick-add" listen-event="openNestedTaxModal" />
    <style>
    .unit-conversion-table {
        overflow: visible !important;
    }
    .unit-conversion-table .table-responsive {
        overflow: visible !important;
    }
    .unit-conversion-table .dropdown-menu {
        z-index: 5000;
    }
    </style>

    <script>
    document.addEventListener('livewire:initialized', () => {
        if (!window.__productQuickAddModalEventsBound) {
            window.__productQuickAddModalEventsBound = true;

            Livewire.on('openProductModal', () => {
                $('#productQuickAddModal').modal('show');
            });

            Livewire.on('productCreated', () => {
                $('#productQuickAddModal').modal('hide');
            });
        }
    });

    var productCurrency = window.productCurrency = {!! json_encode([
        'prefix' => 'RP ',
        'thousands' => '.',
        'decimal' => ',',
    ], JSON_THROW_ON_ERROR) !!};

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

                    // Add watch to sync when Livewire property changes (e.g. cleared by PHP)
                    $wire.$watch(field, (value) => {
                        const raw = parseCurrency(value);
                        this.display = formatCurrency(raw);
                    });
                },
                updateWire(raw) {
                    if (!field || !this.$wire) return;
                    // Only update if value actually changed to avoid cycles
                    if (parseCurrency(this.$wire.get(field)) !== raw) {
                        this.$wire.set(field, raw);
                    }
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
</div>
