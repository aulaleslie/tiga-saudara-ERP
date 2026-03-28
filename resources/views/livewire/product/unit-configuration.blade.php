<div>
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
                                            <!-- Hidden input: stores actual numeric value for form submission and Livewire binding -->
                                            <input type="hidden"
                                                   id="conv-price-{{ $rowKey }}"
                                                   name="conversions[{{ $index }}][price]"
                                                   wire:model="conversions.{{ $index }}.price"
                                                   value="{{ $conversion['price'] }}"/>

                                            <!-- Visible input: jQuery maskMoney formatting only
                                                 IMPORTANT: Must NOT have wire:model, wire:focus, or wire:blur attributes
                                                 Livewire re-renders destroy maskMoney state, so we keep visible input
                                                 jQuery-only. The hidden input handles Livewire data binding.
                                                 See: fix-nominal-field-formatting-consistency change
                                            -->
                                            <input type="text"
                                                   class="form-control conversion-price-input {{ isset($errors['conversions.' . $index . '.price']) ? 'is-invalid' : '' }}"
                                                   placeholder="0,00"
                                                   data-hidden="#conv-price-{{ $rowKey }}"
                                                   value="{{ $displayPrices[$index] ?? '' }}"
                                            />
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
    // Initialize maskMoney for conversion table price fields
    (function() {
        'use strict';

        // Fixed product nominal format (deterministic, not system-configurable)
        // This ensures conversion table prices always display with "RP " prefix and consistent separators
        // regardless of database currency settings, providing a stable user experience
        const symbol = 'RP ';
        const thousands = '.';
        const decimal = ',';

        function initConversionPriceFields() {
            $('.conversion-price-input').each(function() {
                const $visible = $(this);
                const hiddenSelector = $visible.data('hidden');
                const $hidden = hiddenSelector ? $(hiddenSelector) : null;

                // Prevent double-initialization
                if ($visible.data('maskmoney-initialized')) {
                    return;
                }

                // Configure maskMoney
                $visible.maskMoney({
                    prefix: symbol,
                    thousands: thousands,
                    decimal: decimal,
                    precision: 2,
                    allowZero: true,
                    allowNegative: false,
                });

                // Apply initial mask
                const currentValue = $visible.val();
                if (currentValue) {
                    $visible.val(currentValue);
                    $visible.maskMoney('mask');
                } else {
                    // Show placeholder for empty fields
                    $visible.maskMoney('mask');
                }

                $visible.data('maskmoney-initialized', true);

                // Focus: show raw number, destroy mask
                $visible.on('focus', function() {
                    try {
                        $visible.maskMoney('destroy');
                    } catch (e) {
                        // Already destroyed
                    }
                    const unmasked = $hidden ? $hidden.val() : extractRawValue($visible);
                    $visible.val(unmasked || '');
                    setTimeout(() => $visible.select(), 0);
                });

                // Blur: format and reapply mask
                $visible.on('blur', function() {
                    const raw = extractRawValue($visible);
                    if ($hidden) {
                        $hidden.val(raw).trigger('input');
                    }
                    $visible.val(raw);
                    $visible.maskMoney({
                        prefix: symbol,
                        thousands: thousands,
                        decimal: decimal,
                        precision: 2,
                        allowZero: true,
                        allowNegative: false,
                    });
                    $visible.maskMoney('mask');
                });

                // Sync hidden on keyup/change
                $visible.on('keyup change', function() {
                    if ($hidden) {
                        const raw = extractRawValue($visible);
                        $hidden.val(raw).trigger('input');
                    }
                });
            });
        }

        function extractRawValue($el) {
            const textValue = String($el.val() || '');

            // Strategy: Parse based on the separators we control
            // This ensures "65000" doesn't become "0.65"
            // First, try maskMoney's unmasked method if available
            try {
                const unmasked = $el.maskMoney('unmasked');
                if (unmasked && unmasked.length) {
                    return unmasked[0];
                }
            } catch (e) {
                // maskMoney not initialized, continue to manual parsing
            }

            // Fallback: Manual parsing using our known separators
            // Remove currency symbol (RP ), then handle separators
            let cleaned = textValue.replace(/RP\s*/g, '').trim();

            // Replace thousand separator (.) with nothing, and decimal separator (,) with .
            // This converts "1.234,56" -> "1234.56"
            cleaned = cleaned.replace(/\./g, ''); // Remove thousand separators
            cleaned = cleaned.replace(/,/g, '.'); // Convert decimal separator

            const num = parseFloat(cleaned);
            return isNaN(num) ? 0 : num;
        }

        // Initialize on document ready
        if (typeof $ !== 'undefined' && typeof $.fn.maskMoney !== 'undefined') {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initConversionPriceFields);
            } else {
                initConversionPriceFields();
            }
        }

        // Re-initialize on Livewire updates
        if (window.Livewire) {
            document.addEventListener('livewire:navigated', initConversionPriceFields);
            document.addEventListener('livewire:updated', initConversionPriceFields);
        }
    })();
</script>
</div>
