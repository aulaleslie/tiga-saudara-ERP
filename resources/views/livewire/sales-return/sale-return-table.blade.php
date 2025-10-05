<div>
    @if (!empty($rows))
        <div class="table-responsive" style="overflow: visible;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-center text-uppercase small text-muted">
                        <th style="width: 26%">Produk</th>
                        <th style="width: 12%">Lokasi</th>
                        <th style="width: 8%">Dikirim</th>
                        <th style="width: 8%">Sudah Diretur</th>
                        <th style="width: 8%">Tersedia</th>
                        <th style="width: 10%">Jumlah Retur</th>
                        <th style="width: 12%">Harga Unit</th>
                        <th style="width: 12%" class="text-end">Subtotal</th>
                        <th style="width: 4%"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $row['product_name'] ?? '-' }}</div>
                                <div class="text-muted small">{{ $row['product_code'] ?? '—' }}</div>
                                @if (!empty($row['serial_number_required']))
                                    <span class="badge bg-info text-dark mt-1">Serial Number</span>
                                @endif
                                @if(!empty($validationErrors["rows.$index.product_id"]))
                                    <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.product_id"][0] }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-primary">{{ $row['location_name'] ?? '-' }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark">{{ $row['dispatched_quantity'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary">{{ $row['returned_quantity'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success fw-semibold">{{ $row['available_quantity'] ?? 0 }}</span>
                            </td>
                            <td class="text-center">
                                @if (!empty($row['serial_number_required']))
                                    <input type="number" class="form-control text-center bg-light" value="{{ $row['quantity'] ?? 0 }}" readonly>
                                @else
                                    <input type="number" min="0" class="form-control text-center"
                                           wire:model="rows.{{ $index }}.quantity"
                                           wire:blur="updateQuantity({{ $index }})">
                                @endif
                                @error("rows.".$index.".quantity")
                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                @enderror
                                @if(!empty($validationErrors["rows.$index.quantity"]))
                                    <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.quantity"][0] }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="fw-semibold">{{ format_currency($row['unit_price'] ?? 0) }}</span>
                            </td>
                            <td class="text-end fw-semibold">
                                {{ format_currency($row['total'] ?? 0) }}
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-outline-danger btn-sm rounded-circle" wire:click="removeRow({{ $index }})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>

                        @if (!empty($row['serial_number_required']))
                            <tr class="bg-light">
                                <td colspan="9">
                                    <div class="p-3 rounded border">
                                        <livewire:sales-return.sale-serial-number-loader
                                            :index="$index"
                                            :dispatch-detail-id="$row['dispatch_detail_id']"
                                            :product-id="$row['product_id']"
                                            :sale-return-id="$saleReturnId"
                                            wire:key="serial-loader-{{ $index }}"
                                        />

                                        @error("rows.{$index}.serial_numbers")
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                        @if(!empty($validationErrors["rows.$index.serial_numbers"]))
                                            <span class="invalid-feedback d-block">{{ $validationErrors["rows.$index.serial_numbers"][0] }}</span>
                                        @endif

                                        <table class="table table-sm mt-3 mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Nomor Seri</th>
                                                    <th class="text-center" style="width: 10%;">Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse (($row['serial_numbers'] ?? []) as $serialIndex => $serial)
                                                    <tr>
                                                        <td>{{ $serial['serial_number'] ?? '-' }}</td>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-outline-danger btn-sm rounded-circle"
                                                                    wire:click="removeSerialNumber({{ $index }}, {{ $serialIndex }})">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="2" class="text-center text-muted">Belum ada nomor seri yang dipilih.</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="alert alert-light border" role="alert">
            Belum ada produk yang dapat diretur dari penjualan ini.
        </div>
    @endif
</div>
