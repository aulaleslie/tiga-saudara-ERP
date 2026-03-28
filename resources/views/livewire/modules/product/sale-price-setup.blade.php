@php($options = $taxOptions)

<div class="border p-3 mb-3">
    <div class="form-group">
        <input type="checkbox" 
            name="is_sold" 
            id="is_sold" 
            value="1"
            wire:model.live="isActive"
            {{ $isActive ? 'checked' : '' }}
            wire:key="sale-checkbox"
        >
        <label for="is_sold"><strong>{{ $checkboxLabel }}</strong></label>

        <div class="row mt-3">
            <div class="col-md-6">
                <x-nominal-field
                    label="Harga Jual"
                    name="sale_price"
                    :disabled="!$isActive"
                    :value="$price"
                    :error="$priceError"
                />
            </div>
            <div class="col-md-6">
                <label for="sale_tax_id">Pajak Jual</label>
                <livewire:modules.product.tax-search-dropdown
                    name="sale_tax_id"
                    input-id="sale_tax_id"
                    placeholder="Pilih pajak..."
                    :options="$options"
                    :selected="$taxId"
                    :clearable="true"
                    :disabled="!$isActive"
                    :allow-create="true"
                    :error="$taxError"
                    wire:key="sale-tax-dropdown-{{ $isActive ? 'on' : 'off' }}"
                />
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <x-nominal-field
                    label="Harga Jual Partai Besar"
                    name="tier_1_price"
                    :disabled="!$isActive"
                    :value="$tier1Price"
                    :error="$tier1Error"
                />
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <x-nominal-field
                    label="Harga Jual Reseller"
                    name="tier_2_price"
                    :disabled="!$isActive"
                    :value="$tier2Price"
                    :error="$tier2Error"
                />
            </div>
        </div>
    </div>
</div>
