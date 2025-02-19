<div>
    @if ($supplier_id)
        <table class="table table-bordered align-middle">
            <thead class="table-primary">
            <tr class="text-center">
                <th style="width: 20%">Produk</th>
                <th style="width: 10%">Stok Rusak</th>
                <th style="width: 10%">Jumlah</th>
                <th style="width: 20%">Nomor Purchase Order</th>
                <th style="width: 10%">Tanggal Purchase Order</th>
                <th style="width: 10%">Harga Saat Beli/Harga Beli Terakhir</th>
                <th style="width: 5%">
                    <button type="button" class="btn btn-success btn-sm rounded-circle shadow-sm"
                            wire:click="addProductRow">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $index => $row)
                <tr>
                    <td>
                        <livewire:purchase-return.product-search-purchase-return
                            :index="$index"
                            :supplier_id="$supplier_id"
                            wire:key="product-{{ $index }}"/>
                        @if(isset($validationErrors["rows.$index.product_id"]))
                            <span class="text-danger">{{ $validationErrors["rows.$index.product_id"][0] }}</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-warning">
                            {{ $row['product_quantity'] ?? '-' }}
                        </span>
                    </td>
                    <td class="text-center">
                        <input type="number" class="form-control text-center rounded shadow-sm"
                               wire:model.defer="rows.{{ $index }}.quantity"
                               min="1" style="max-width: 80px;">
                        @error("rows.".$index.".quantity")
                        <span class="text-danger">{{ $message }}</span>
                        @enderror
                    </td>
                    <td>
                        @if (!empty($row['product_id']))
                            <livewire:purchase-return.purchase-order-search-purchase-return
                                :index="$index"
                                :supplier_id="$supplier_id"
                                :product_id="$row['product_id']"
                                wire:key="po-{{ $index }}"/>
                            @error("rows.".$index.".purchase_order_id")
                            <span class="text-danger">{{ $message }}</span>
                            @enderror
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="text-muted">
                            {{ $row['purchase_order_date'] ?? '-' }}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-success">
                            {{ !empty($row['purchase_price']) ? 'Rp ' . number_format($row['purchase_price'], 0, ',', '.') . ',-' : '-' }}
                        </span>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-danger btn-sm rounded-circle shadow-sm"
                                wire:click="removeProductRow({{ $index }})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
