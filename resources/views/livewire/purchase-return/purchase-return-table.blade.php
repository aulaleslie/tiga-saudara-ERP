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
                        @if(!empty($validationErrors["rows.$index.product_id"]))
                            <span class="text-danger">{{ $validationErrors["rows.$index.product_id"][0] }}</span>
                        @endif
                        @if(!empty($validationErrors["rows.$index.serial_numbers"]))
                            <span class="text-danger">{{ $validationErrors["rows.$index.serial_numbers"][0] }}</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-warning">
                            {{ $row['product_quantity'] ?? '-' }}
                        </span>
                    </td>
                    <td class="text-center">
                        @if (!empty($row['serial_number_required']))
                            {{-- Quantity Display (Read-only) --}}
                            <input type="number"
                                   class="form-control text-center rounded shadow-sm bg-light"
                                   style="min-width: 40px; max-width: 90px;"
                                   wire:model.defer="rows.{{ $index }}.quantity"
                                   readonly>
                        @else
                            {{-- Editable Quantity --}}
                            <input type="number"
                                   class="form-control text-center rounded shadow-sm"
                                   style="min-width: 40px; max-width: 90px;"
                                   wire:model="rows.{{ $index }}.quantity"
                                   wire:blur="emitUpdatedQuantity({{ $index }})">
                        @endif
                        @error("rows.".$index.".quantity")
                        <span class="text-danger">{{ $message }}</span>
                        @enderror
                        @if(!empty($validationErrors["rows.$index.quantity"]))
                            <span class="text-danger">{{ $validationErrors["rows.$index.quantity"][0] }}</span>
                        @endif
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

                @if (!empty($row['serial_number_required']))
                    <tr>
                        <td colspan="7">
                            <div class="p-3 border rounded bg-light">
                                <strong>Serial Numbers</strong>

                                {{-- Serial Number Loader --}}
                                <livewire:purchase-return.purchase-order-serial-number-loader
                                    :index="$index"
                                    :product_id="$row['product_id']"
                                    :is_broken="true"
                                    wire:key="serial-number-{{ $index }}" />

                                @error("rows.{$index}.serial_numbers")
                                <span class="text-danger">{{ $message }}</span>
                                @enderror

                                {{-- Serial Numbers Table --}}
                                <table class="table table-sm mt-2">
                                    <thead>
                                    <tr>
                                        <th>Serial Number</th>
                                        <th class="text-center" style="width: 5%;">Remove</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($row['serial_numbers'] ?? [] as $serialIndex => $serialNumber)
                                        <tr>
                                            <td>{{ $serialNumber['serial_number'] }}</td>
                                            <td class="text-center">
                                                <button type="button"
                                                        class="btn btn-danger btn-sm rounded-circle"
                                                        wire:click="removeSerialNumber({{ $index }}, {{ $serialIndex }})">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endif
</div>
