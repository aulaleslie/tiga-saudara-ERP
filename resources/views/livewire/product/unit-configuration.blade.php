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
                                    @disabled($locked)
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
                                                :disabled="$locked"
                                            />
                                        </td>
                                        <td>
                                            <input type="number" name="conversions[{{ $index }}][conversion_factor]"
                                                   class="form-control {{ isset($errors['conversions.' . $index . '.conversion_factor']) ? 'is-invalid' : '' }}"
                                                   step="0.0001"
                                                   value="{{ $conversion['conversion_factor'] }}"
                                                   @disabled($locked)>
                                            @if(isset($errors['conversions.' . $index . '.conversion_factor']))
                                                <span class="invalid-feedback"
                                                      role="alert"><strong>{{ $errors['conversions.' . $index . '.conversion_factor'][0] }}</strong></span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" name="conversions[{{ $index }}][barcode]"
                                                   class="form-control {{ isset($errors['conversions.' . $index . '.barcode']) ? 'is-invalid' : '' }}"
                                                   value="{{ $conversion['barcode'] }}"
                                                   @disabled($locked)>
                                            @if(isset($errors['conversions.' . $index . '.barcode']))
                                                <span class="invalid-feedback"
                                                      role="alert"><strong>{{ $errors['conversions.' . $index . '.barcode'][0] }}</strong></span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="hidden"
                                                   id="conv-price-{{ $rowKey }}"
                                                   name="conversions[{{ $index }}][price]"
                                                   wire:model="conversions.{{ $index }}.price"
                                                   value="{{ $conversion['price'] }}"/>

                                            <input type="text"
                                                   class="form-control conversion-price-input {{ isset($errors['conversions.' . $index . '.price']) ? 'is-invalid' : '' }}"
                                                   placeholder="0,00"
                                                   data-hidden="#conv-price-{{ $rowKey }}"
                                                   wire:model="displayPrices.{{ $index }}"
                                                   wire:focus="showRawPrice({{ $index }})"
                                                   wire:blur="syncPrice({{ $index }})"
                                                   value="{{ $displayPrices[$index] ?? '' }}"
                                                   @disabled($locked)
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
                                                    wire:click="removeConversionRow('{{ $rowKey }}')"
                                                    @disabled($locked)>
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
</div>
