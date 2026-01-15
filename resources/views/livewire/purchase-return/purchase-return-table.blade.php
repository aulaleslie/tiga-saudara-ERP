<div>
    <style>
        .sticky-action-header {
            position: sticky;
            right: 0;
            z-index: 2;
            background-color: #f8f9fa !important;
            box-shadow: -2px 0 5px rgba(0,0,0,0.05); /* Subtle separator */
        }
        .sticky-action-col {
            position: sticky;
            right: 0;
            z-index: 1;
            background-color: #fff;
            box-shadow: -2px 0 5px rgba(0,0,0,0.05);
        }
        /* Ensure specific background functionality on hover */
        .table-hover tbody tr:hover .sticky-action-col {
            background-color: var(--bs-table-hover-bg);
        }
    </style>
    @if ($supplierId)
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="min-width: 1300px;">
                <thead class="table-light">
                    <tr class="text-center text-uppercase small text-muted">
                        <th style="width: 22%">Produk</th>
                        <th style="width: 15%">Lokasi</th>
                        <th style="width: 18%">Jumlah di Lokasi</th>
                        <th style="width: 9%">Jumlah Retur</th>
                        <th style="width: 18%">Nomor Pembelian</th>
                        <th style="width: 10%">Tanggal Purchase</th>
                        @if(!$hidePrice)
                            <th style="width: 11%">Harga Beli</th>
                        @endif
                        <th style="width: 3%;" class="sticky-action-header text-center">
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-circle"
                                    wire:click="addProductRow">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $index => $row)
                        <tr wire:key="row-{{ $index }}">
                            <td>
                                <livewire:purchase-return.product-search-dropdown
                                    :index="$index"
                                    :supplier_id="$supplierId"
                                    :selected="$row['product_id']"
                                    :error="$validationErrors['rows.'.$index.'.product_id'][0] ?? null"
                                    wire:key="product-{{ $index }}" />
                                @if(!empty($validationErrors["rows.$index.product_id"]))
                                    <span class="invalid-feedback d-block text-start">{{ $validationErrors["rows.$index.product_id"][0] }}</span>
                                @endif
                                @if(!empty($validationErrors["rows.$index.serial_numbers"]))
                                    <span class="invalid-feedback d-block text-start">{{ $validationErrors["rows.$index.serial_numbers"][0] }}</span>
                                @endif
                                
                            </td>
                            <td>
                                @if (!empty($row['product_id']))
                                    @if (!empty($row['location_locked']))
                                        {{-- Read-only display for serial-locked locations --}}
                                        <div class="form-control form-control-sm bg-light text-muted text-truncate" 
                                             style="cursor: not-allowed;"
                                             title="{{ $row['location_name'] ?? 'Pilih lokasi...' }}">
                                            <i class="bi bi-lock-fill me-1"></i>
                                            {{ $row['location_name'] ?? 'Pilih lokasi...' }}
                                        </div>
                                    @else
                                        <livewire:purchase-return.location-search-dropdown-per-line
                                            :index="$index"
                                            :product_id="$row['product_id']"
                                            :selected="$row['location_id']"
                                            :error="$validationErrors['rows.'.$index.'.location_id'][0] ?? null"
                                            wire:key="location-{{ $index }}" />
                                    @endif
                                    @if(!empty($validationErrors["rows.$index.location_id"]))
                                        <span class="invalid-feedback d-block text-start">{{ $validationErrors["rows.$index.location_id"][0] }}</span>
                                    @endif
                                @else
                                    <span class="text-muted small">Pilih produk dahulu</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-primary fw-semibold">{{ $row['stock_at_location'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                @if (!empty($row['serial_number_required']))
                                    <div class="form-control text-center bg-light" style="min-width: 60px;">
                                        {{ $row['quantity'] ?? 0 }}
                                    </div>
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
                                    @if (!empty($row['purchase_order_locked']))
                                        {{-- Read-only display for serial-locked POs --}}
                                        <div class="form-control form-control-sm bg-light text-muted" style="cursor: not-allowed;">
                                            <i class="bi bi-lock-fill me-1"></i>
                                            {{ $row['purchase_order_reference'] ?? 'Pilih purchase order...' }}
                                        </div>
                                    @else
                                        <livewire:purchase-return.purchase-order-search-dropdown
                                            :index="$index"
                                            :supplier_id="$supplierId"
                                            :product_id="$row['product_id']"
                                            :selected="$row['purchase_order_id']"
                                            :error="$validationErrors['rows.'.$index.'.purchase_order_id'][0] ?? null"
                                            wire:key="po-{{ $index }}" />
                                    @endif
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
                            @if(!$hidePrice)
                                <td class="text-center">
                                    <span class="fw-semibold text-success">
                                        {{ !empty($row['purchase_price']) ? 'Rp ' . number_format($row['purchase_price'], 0, ',', '.') . ',-' : '-' }}
                                    </span>
                                </td>
                            @endif
                            <td class="text-center sticky-action-col">
                                <button type="button" class="btn btn-outline-danger btn-sm rounded-circle"
                                        wire:click="removeProductRow({{ $index }})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>

                        @if (!empty($row['serial_number_required']))
                            <tr class="bg-light">
                                <td colspan="{{ $hidePrice ? 7 : 8 }}">
                                    <div class="p-3 rounded border">
                                        <livewire:purchase-return.purchase-order-serial-number-loader
                                            :index="$index"
                                            :product_id="$row['product_id']"
                                            :purchase_id="$row['purchase_order_id'] ?? null"
                                            :existingSerials="$row['serial_numbers'] ?? []"
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
                            <td colspan="{{ $hidePrice ? 7 : 8 }}" class="text-center text-muted py-4">Belum ada produk yang ditambahkan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
