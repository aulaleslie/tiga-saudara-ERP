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
                     :disabled="!$stockManaged || $locked"/>
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
                            <h5 class="mb-0">Konversi Unit</h5>
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
                                                   value="{{ $displayPrices[$index] ?? '' }}"
                                            />
                                            </div>
                                            @if(isset($errors['conversions.' . $index . '.price']))
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $errors['conversions.' . $index . '.price'][0] }}</strong>
                                                </span>
                                            @endif
                                        </td>

                                        <td class="text-end">
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

        const RP_PREFIX = 'RP ';
        const THOUSANDS = '.';
        const DECIMAL = ',';
        const PRECISION = 2;

        function toCanonicalString(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            const numeric = Number(value);
            if (!Number.isFinite(numeric)) {
                return '';
            }

            const rounded = Math.round(numeric * 100) / 100;

            if (Number.isInteger(rounded)) {
                return String(rounded);
            }

            return rounded.toFixed(PRECISION).replace(/\.?0+$/, '');
        }

        function formatDisplay(rawValue) {
            const canonical = toCanonicalString(rawValue);

            if (canonical === '') {
                return '';
            }

            const numeric = Number(canonical);
            if (!Number.isFinite(numeric)) {
                return '';
            }

            const fixed = numeric.toFixed(PRECISION);
            const parts = fixed.split('.');
            const grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS);

            return RP_PREFIX + grouped + DECIMAL + parts[1];
        }

        function extractRawValue(visible) {
            const textValue = String(visible.value || '').trim();
            if (!textValue) {
                return '';
            }

            let cleaned = textValue.replace(/^RP\s*/i, '').trim();
            cleaned = cleaned.replace(/\s+/g, '');
            cleaned = cleaned.replace(/\./g, '');
            cleaned = cleaned.replace(/,/g, '.');

            const parsed = Number.parseFloat(cleaned);

            return Number.isFinite(parsed) ? toCanonicalString(parsed) : '';
        }

        function findHiddenInput(visible) {
            return visible.closest('.conversion-price-field')?.querySelector('.conversion-price-hidden') ?? null;
        }

        function dispatchNativeInput(hidden) {
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function syncInput(visible) {
            const hidden = findHiddenInput(visible);
            const rawValue = extractRawValue(visible);

            if (hidden) {
                if (hidden.value !== rawValue) {
                    hidden.value = rawValue;
                }

                dispatchNativeInput(hidden);
            }

            return rawValue;
        }

        function applyFormattedState(visible, rawValue) {
            visible.value = formatDisplay(rawValue);
        }

        function bindInput(visible) {
            if (visible.dataset.unitConfigBound === 'true') {
                return;
            }

            visible.dataset.unitConfigBound = 'true';

            const hidden = findHiddenInput(visible);
            const initialRawValue = (hidden && hidden.value) || extractRawValue(visible);
            const initialCanonical = toCanonicalString(initialRawValue);

            if (hidden) {
                hidden.value = initialCanonical;
                dispatchNativeInput(hidden);
            }

            applyFormattedState(visible, initialCanonical);

            visible.addEventListener('focus', function () {
                visible.value = hidden ? hidden.value : '';

                window.setTimeout(() => {
                    if (typeof visible.select === 'function') {
                        visible.select();
                    }
                }, 0);
            });

            visible.addEventListener('blur', function () {
                const rawValue = syncInput(visible);
                applyFormattedState(visible, rawValue);
            });

            visible.addEventListener('input', function () {
                syncInput(visible);
            });

            visible.addEventListener('change', function () {
                syncInput(visible);
            });
        }

        function syncForm(form) {
            form.querySelectorAll('.conversion-price-input').forEach((visible) => {
                const rawValue = syncInput(visible);

                if (document.activeElement !== visible) {
                    applyFormattedState(visible, rawValue);
                }
            });
        }

        function bindFormSubmit() {
            document.querySelectorAll('form').forEach((form) => {
                if (form.dataset.unitConfigPriceSubmitBound === 'true') {
                    return;
                }

                if (!form.querySelector('.conversion-price-input')) {
                    return;
                }

                form.dataset.unitConfigPriceSubmitBound = 'true';
                form.addEventListener('submit', function () {
                    syncForm(form);
                });
            });
        }

        function refresh() {
            document.querySelectorAll('.conversion-price-input').forEach((visible) => {
                bindInput(visible);
            });

            bindFormSubmit();
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
