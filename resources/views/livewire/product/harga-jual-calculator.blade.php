<div>
    <div class="form-row">
        <div class="col-md-6">
            <x-input label="Harga" name="product_cost" wire:model="product_cost" wire:input="calculateHargaJual"/>
        </div>
        <div class="col-md-6">
            <x-input label="Pajak (%)" name="product_order_tax" type="number" step="0.01" wire:model="product_order_tax" wire:input="calculateHargaJual"/>
        </div>
    </div>

    <div class="form-row">
        <div class="col-md-6">
            <x-select label="Jenis Pajak" name="product_tax_type" :options="['1' => 'Exclusive', '2' => 'Inclusive']" wire:model="product_tax_type" wire:change="calculateHargaJual"/>
        </div>
        <div class="col-md-6">
            <x-input label="Keuntungan (%)" name="profit_percentage" step="0.01" placeholder="Enter Profit Percentage" wire:model="profit_percentage" wire:input="calculateHargaJual"/>
        </div>
    </div>

    <div class="form-row">
        <div class="col-md-6">
            <x-input label="Harga Jual" name="product_price" step="0.01" wire:model="product_price" readonly/>
        </div>
    </div>
</div>
