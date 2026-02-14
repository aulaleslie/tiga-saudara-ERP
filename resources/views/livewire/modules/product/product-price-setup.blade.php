@php($options = $taxOptions)

<div class="border p-3 mb-3">
    <div class="form-group">
        <input type="checkbox" 
            name="{{ $checkboxName }}" 
            id="{{ $checkboxName }}" 
            value="1"
            wire:model.live="isActive"
            {{ $isActive ? 'checked' : '' }}
            wire:key="{{ $fieldPrefix }}-checkbox"
        >
        <label for="{{ $checkboxName }}"><strong>{{ $checkboxLabel }}</strong></label>

        <div class="row mt-3">
            <div class="col-md-6">
                <x-input 
                    :label="$priceLabel" 
                    name="{{ $fieldPrefix }}_price"
                    step="0.01" 
                    :disabled="!$isActive"
                    :value="$price"
                    :error="$priceError"
                    wire:model.live="price"
                />
            </div>
            <div class="col-md-6">
                <label for="{{ $fieldPrefix }}_tax_id">{{ $taxLabel }}</label>
                <livewire:modules.product.tax-search-dropdown
                    :name="$fieldPrefix . '_tax_id'"
                    :input-id="$fieldPrefix . '_tax_id'"
                    placeholder="Pilih pajak..."
                    :options="$options"
                    :selected="$taxId"
                    :clearable="true"
                    :disabled="!$isActive"
                    :allow-create="true"
                    :error="$taxError"
                    wire:key="{{ $fieldPrefix }}-tax-dropdown-{{ $isActive ? 'on' : 'off' }}"
                />
            </div>
        </div>
    </div>
</div>
