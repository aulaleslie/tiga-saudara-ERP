<div>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Jumlah (min 1)</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        @foreach($items as $index => $item)
            <tr>
                <td>
                    <livewire:auto-complete.product-loader :index="$index" :key="$index" />
                    @error("items.$index.product_id")
                    <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </td>
                <td>
                    <input
                        type="number"
                        wire:model="items.{{ $index }}.quantity"
                        class="form-control"
                        min="1">
                </td>
                <td>
                    <button type="button" class="btn btn-danger" wire:click="removeItem({{ $index }})">Hapus</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <button type="button" class="btn btn-secondary" wire:click="addItem">Add Item</button>

    <!-- Hidden inputs to pass bundle items data when the parent form is submitted -->
    @foreach($items as $index => $item)
        <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] }}">
        <input type="hidden" name="items[{{ $index }}][price]" value="{{ $item['price'] ?? 0 }}">
        <input type="hidden" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 0}}">
    @endforeach
</div>
