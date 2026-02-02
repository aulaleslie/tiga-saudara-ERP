<div>
    <div class="card" style="overflow: visible;">
        <div class="card-body bundle-items-table" style="overflow: visible;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Item Paket</h5>
                <button type="button"
                        class="btn btn-outline-primary btn-sm"
                        wire:click="addItem">
                    <i class="bi bi-plus"></i> Tambah
                </button>
            </div>

            <div class="table-responsive" style="overflow-x: auto; overflow-y: visible;">
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Jumlah (min 1)</th>
                        <th class="text-end" style="white-space: nowrap;">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $index => $item)
                        <tr>
                            <td style="min-width: 220px;">
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
                            <td class="text-end">
                                <button type="button" class="btn btn-danger" wire:click="removeItem({{ $index }})">Hapus</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Hidden inputs to pass bundle items data when the parent form is submitted -->
    @foreach($items as $index => $item)
        <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $item['product_id'] }}">
        <input type="hidden" name="items[{{ $index }}][price]" value="{{ $item['price'] ?? 0 }}">
        <input type="hidden" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 0}}">
    @endforeach

    <style>
        .bundle-items-table {
            overflow: visible !important;
        }
        .bundle-items-table .table-responsive {
            overflow: visible !important;
        }
        .bundle-items-table .dropdown-menu {
            z-index: 5000;
        }
    </style>
</div>
