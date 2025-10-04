<div>
    @if ($supplier_id)
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-center text-uppercase small text-muted">
                        <th style="width: 22%">Produk</th>
                        <th style="width: 9%">Tersedia Non Pajak</th>
                        <th style="width: 9%">Tersedia Pajak</th>
                        <th style="width: 9%">Jumlah Retur</th>
                        <th style="width: 18%">Nomor Purchase</th>
                        <th style="width: 10%">Tanggal Purchase</th>
                        <th style="width: 11%">Harga Beli</th>
                        <th style="width: 9%" class="text-end">Subtotal</th>
                        <th style="width: 3%">
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-circle"
                                    wire:click="addProductRow">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $index => $row)
                        <tr>
                            <td>
                                <livewire:purchase-return.product-search-purchase-return
                                    :index="$index"
                                    :supplier_id="$supplier_id"
                                    wire:key="product-{{ $index }}" />
                                @if(!empty($validationErrors["rows.$index.product_id"]))
                                    <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.product_id"][0] }}</span>
                                @endif
                                @if(!empty($validationErrors["rows.$index.serial_numbers"]))
                                    <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.serial_numbers"][0] }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-primary fw-semibold">{{ $row['available_quantity_non_tax'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-success fw-semibold">{{ $row['available_quantity_tax'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                @if (!empty($row['serial_number_required']))
                                    <input type="number"
                                           class="form-control text-center bg-light"
                                           style="min-width: 60px;"
                                           wire:model.defer="rows.{{ $index }}.quantity"
                                           readonly>
                                @else
                                    <input type="number"
                                           class="form-control text-center"
                                           style="min-width: 60px;"
                                           wire:model="rows.{{ $index }}.quantity"
                                           wire:blur="emitUpdatedQuantity({{ $index }})">
                                @endif
                                @error("rows.".$index.".quantity")
                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                @enderror
                                @if(!empty($validationErrors["rows.$index.quantity"]))
                                    <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.quantity"][0] }}</span>
                                @endif
                            </td>
                            <td>
                                @if (!empty($row['product_id']))
                                    <livewire:purchase-return.purchase-order-search-purchase-return
                                        :index="$index"
                                        :supplier_id="$supplier_id"
                                        :product_id="$row['product_id']"
                                        wire:key="po-{{ $index }}" />
                                    @error("rows.".$index.".purchase_order_id")
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                @else
                                    <span class="text-muted small">Pilih produk terlebih dahulu</span>
                                @endif
                            </td>
                            <td class="text-center text-muted">
                                {{ $row['purchase_order_date'] ?? '-' }}
                            </td>
                            <td class="text-center">
                                <span class="fw-semibold text-success">
                                    {{ !empty($row['purchase_price']) ? 'Rp ' . number_format($row['purchase_price'], 0, ',', '.') . ',-' : '-' }}
                                </span>
                            </td>
                            <td class="text-end fw-semibold">
                                {{ isset($row['total']) ? 'Rp ' . number_format($row['total'], 0, ',', '.') . ',-' : '-' }}
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-danger btn-sm rounded-circle"
                                        wire:click="removeProductRow({{ $index }})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>

                        @if (!empty($row['serial_number_required']))
                            <tr class="bg-light">
                                <td colspan="9">
                                    <div class="p-3 rounded border">
                                        <livewire:purchase-return.purchase-order-serial-number-loader
                                            :index="$index"
                                            :product_id="$row['product_id']"
                                            :location_id="$location_id"
                                            :is_broken="true"
                                            wire:key="serial-number-{{ $index }}" />

                                        @error("rows.{$index}.serial_numbers")
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror

                                        <table class="table table-sm mt-3 mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Serial Number</th>
                                                    <th class="text-center" style="width: 10%;">Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (($row['serial_numbers'] ?? []) as $serialIndex => $serialNumber)
                                                    <tr>
                                                        <td>{{ $serialNumber['serial_number'] }}</td>
                                                        <td class="text-center">
                                                            <button type="button"
                                                                    class="btn btn-outline-danger btn-sm rounded-circle"
                                                                    wire:click="removeSerialNumber({{ $index }}, {{ $serialIndex }})">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                @if(empty($row['serial_numbers']))
                                                    <tr>
                                                        <td colspan="2" class="text-center text-muted">Belum ada nomor seri yang dipilih.</td>
                                                    </tr>
                                                @endif
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Belum ada produk yang ditambahkan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
