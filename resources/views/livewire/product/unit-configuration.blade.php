<div class="unit-configuration-root">
<fieldset id="stock-dependent" class="mt-4">
    <div class="form-row">
        <div class="col-md-4">
            <div class="form-group">
                <input type="hidden" name="stock_managed" value="{{ $stockManaged ? 1 : 0 }}">
                <label>
                    <input type="checkbox"
                           id="stock_managed"
                           name="stock_managed_toggle"
                           wire:model.live="stockManaged"
                           value="1"
                           {{ $stockManaged ? 'checked' : '' }}
                           @disabled($locked)
                    >
                    <strong>Manajemen Stok</strong>
                </label>
                <p class="help-block"><i>Aktifkan opsi ini jika Anda ingin mengelola stok untuk produk ini.</i></p>
            </div>
        </div>
    </div>

    <div class="form-row mt-2">
        <div class="col-md-4">
            <div class="form-group">
                <input type="hidden" name="serial_number_required" value="{{ $serialNumberRequired ? 1 : 0 }}">
                <input type="checkbox"
                       name="serial_number_required_toggle"
                       id="serial_number_required"
                       value="1"
                       wire:model.live="serialNumberRequired"
                       @disabled(!$stockManaged || $locked)
                >
                <label for="serial_number_required"><strong>Serial Number Diperlukan</strong></label>
            </div>
        </div>
    </div>

    <div class="form-row mt-2">
        @if(!is_null($productQuantity))
            <div class="col-md-6">
                <x-input label="Stok" name="product_quantity" type="number" step="1"
                         :value="$productQuantity"
                         disabled/>
            </div>
        @endif
        <div class="col-md-6">
            <x-input label="Peringatan Jumlah Stok"
                     name="product_stock_alert"
                     type="number"
                     step="1"
                     :value="$productStockAlert"
                     :disabled="!$stockManaged"/>
            <small class="form-text text-muted">Ambang batas peringatan stok minimum ini berlaku secara global untuk semua bisnis dan lokasi.</small>
        </div>
    </div>

    <div class="form-row mt-2">
        <div class="col-md-6">
            <label for="unit_search">Unit Utama</label>
            <livewire:modules.product.unit-search-dropdown
                name="base_unit_id"
                placeholder="Pilih unit..."
                :options="$unitOptions"
                :selected="$baseUnitId"
                :allow-create="true"
                :error="$errors['base_unit_id'][0] ?? null"
                width="100%"
                :disabled="(!$stockManaged) || $locked"
                wire:key="base-unit-config-{{ $stockManaged ? 'on' : 'off' }}-{{ $locked ? 'locked' : 'free' }}-{{ $baseUnitId ?? 'null' }}"
            />
        </div>
        <div class="col-md-6">
            <x-input
                label="Barcode Unit Utama"
                name="barcode"
                :value="$barcode"
                :disabled="(!$stockManaged) || $locked"
            />
        </div>
    </div>

    @if($stockManaged)
        <div class="form-row mt-3">
            <div class="col-lg-12">
                <div class="card" style="overflow: visible;">
                    <div class="card-body unit-conversion-table" style="overflow: visible;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h5 class="mb-0">Konversi Unit</h5>
                                <small class="text-muted">Unit dan faktor konversi berlaku untuk semua bisnis. Perubahan harga hanya berlaku untuk bisnis yang aktif saat ini, sedangkan penambahan konversi baru akan mengisi harga awal ke semua bisnis.</small>
                            </div>
                            <button type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    wire:click="addConversionRow"
                            >
                                <i class="bi bi-plus"></i> Tambah
                            </button>
                        </div>
                        <div class="table-responsive" style="overflow-x: auto; overflow-y: visible;">
                            <table class="table table-bordered">
                                <thead>
                                <tr>
                                    <th>ke Unit</th>
                                    <th>Faktor Konversi</th>
                                    <th>Barcode</th>
                                    <th>Harga</th>
                                    <th class="text-center" style="white-space: nowrap;">Bisa Jual</th>
                                    <th class="text-center" style="white-space: nowrap;">Bisa Beli</th>
                                    <th class="text-end" style="white-space: nowrap;">Aksi</th>
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
                                                :options="$unitOptions"
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
                                            <div class="conversion-price-field">
                                            <input type="hidden"
                                                   class="conversion-price-hidden"
                                                   name="conversions[{{ $index }}][price]"
                                                   wire:model="conversions.{{ $index }}.price"
                                                   value="{{ $conversion['price'] }}"/>
                                            <input type="text"
                                                   class="form-control conversion-price-input {{ isset($errors['conversions.' . $index . '.price']) ? 'is-invalid' : '' }}"
                                                   placeholder="0,00"
                                                   data-financial-amount
                                                   value="{{ $conversion['price'] }}"
                                            />
                                            </div>
                                            @if(isset($errors['conversions.' . $index . '.price']))
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $errors['conversions.' . $index . '.price'][0] }}</strong>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-center align-middle">
                                            <input type="hidden" name="conversions[{{ $index }}][sales_enabled]" value="0">
                                            <input type="checkbox"
                                                   name="conversions[{{ $index }}][sales_enabled]"
                                                   wire:model="conversions.{{ $index }}.sales_enabled"
                                                   value="1"
                                                   class="form-check-input"
                                                   {{ !empty($conversion['sales_enabled']) ? 'checked' : '' }}
                                            >
                                        </td>
                                        <td class="text-center align-middle">
                                            <input type="hidden" name="conversions[{{ $index }}][purchase_enabled]" value="0">
                                            <input type="checkbox"
                                                   name="conversions[{{ $index }}][purchase_enabled]"
                                                   wire:model="conversions.{{ $index }}.purchase_enabled"
                                                   value="1"
                                                   class="form-check-input"
                                                   {{ !empty($conversion['purchase_enabled']) ? 'checked' : '' }}
                                            >
                                        </td>

                                        <td class="text-end align-middle">
                                            <button type="button"
                                                    class="btn btn-danger"
                                                    wire:click="removeConversionRow('{{ $rowKey }}')">
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
            </div>
        </div>
    @endif
</fieldset>

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
    (function () {
        'use strict';

        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return;
        }

        // Each conversion row's price field delegates to the shared real-time financial
        // formatter (public/js/financial-input.js), the same one backing the nominal-field
        // component and the payment amount inputs, so all three surfaces behave identically.
        // Every row is
        // enhanced independently -- its canonical state and editing behavior never leak into
        // another row -- and the hidden `conversions[n][price]` input (Livewire's wire:model
        // source of truth) is kept in sync via the shared formatter's change event.

        function findHiddenInput(visible) {
            return visible.closest('.conversion-price-field')?.querySelector('.conversion-price-hidden') ?? null;
        }

        function dispatchNativeInput(hidden) {
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function bindInput(visible) {
            if (visible.dataset.unitConfigBound === 'true') {
                return;
            }
            if (typeof window.FinancialInput === 'undefined') {
                // Shared formatter not yet loaded; retry on the next dynamic-render pass.
                return;
            }

            visible.dataset.unitConfigBound = 'true';

            const hidden = findHiddenInput(visible);

            window.FinancialInput.enhance(visible);

            const syncHiddenFromVisible = function () {
                const canonical = window.FinancialInput.getCanonicalValue(visible);
                const value = canonical === null ? '' : canonical;

                if (hidden && hidden.value !== value) {
                    hidden.value = value;
                    dispatchNativeInput(hidden);
                }
            };

            syncHiddenFromVisible();

            visible.addEventListener('financial-amount:change', syncHiddenFromVisible);
            visible.addEventListener('input', syncHiddenFromVisible);
            visible.addEventListener('change', syncHiddenFromVisible);
        }

        function refresh() {
            document.querySelectorAll('.conversion-price-input').forEach((visible) => {
                bindInput(visible);
            });
        }

        function queueRefresh() {
            if (window.__unitConfigurationPriceRefreshQueued) {
                return;
            }

            window.__unitConfigurationPriceRefreshQueued = true;

            requestAnimationFrame(function () {
                window.__unitConfigurationPriceRefreshQueued = false;
                refresh();
            });
        }

        if (!window.__unitConfigurationPriceObserver) {
            window.__unitConfigurationPriceObserver = new MutationObserver(queueRefresh);
            window.__unitConfigurationPriceObserver.observe(document.body, {
                childList: true,
                subtree: true,
            });
        }

        document.addEventListener('livewire:load', queueRefresh);
        document.addEventListener('livewire:initialized', queueRefresh);
        document.addEventListener('livewire:navigated', queueRefresh);

        queueRefresh();
    })();
</script>
</div>
