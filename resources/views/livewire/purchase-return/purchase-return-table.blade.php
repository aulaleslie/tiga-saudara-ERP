<div>
    @if ($supplier_id)
        <table class="table table-bordered align-middle">
            <thead class="table-primary">
            <tr class="text-center">
                <th style="width: 20%">Produk</th>
                <th style="width: 10%">Harga</th>
                <th style="width: 10%">Stok</th>
                <th style="width: 10%">Jumlah</th>
                <th style="width: 20%">Nomor Purchase Order</th>
                <th style="width: 10%">Tanggal Purchase Order</th>
                <th style="width: 5%">
                    <!-- Add Button in Header -->
                    <button type="button" class="btn btn-success btn-sm rounded-circle shadow-sm"
                            wire:click="addProductRow">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </th>
            </tr>
            </thead>
            <tbody>
            @foreach ($selectedProducts as $index => $selectedProduct)
                <tr>
                    <td>
                        <livewire:purchase-return.product-search-purchase-return
                            :index="$index"
                            :supplier_id="$supplier_id"
                            wire:key="product-{{ $index }}"/>
                    </td>
                    <td class="text-center">
                                <span class="fw-bold text-success">
                                    {{ !empty($selectedProduct['purchase_price']) ? 'Rp ' . number_format($selectedProduct['purchase_price'], 0, ',', '.') . ',-' : '-' }}
                                </span>
                    </td>
                    <td class="text-center">
                                <span class="fw-bold text-warning">
                                    {{ $selectedProduct['product_quantity'] ?? '-' }}
                                </span>
                    </td>
                    <td class="text-center">
                        <input type="number" class="form-control text-center rounded shadow-sm"
                               wire:model.defer="selectedProducts.{{ $index }}.quantity"
                               min="1" style="max-width: 80px;">
                    </td>
                    <td>
                        @if (!empty($selectedProduct['product_id']))
                            <livewire:purchase-return.purchase-order-search-purchase-return
                                :index="$index"
                                :supplier_id="$supplier_id"
                                :product_id="$selectedProduct['product_id']"
                                wire:key="po-{{ $index }}"/>
                        @endif
                    </td>
                    <td class="text-center">
                                <span class="text-muted">
                                    {{ $selectedProduct['purchase_order_date'] ?? '-' }}
                                </span>
                    </td>
                    <td class="text-center">
                        <!-- Remove Button -->
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
