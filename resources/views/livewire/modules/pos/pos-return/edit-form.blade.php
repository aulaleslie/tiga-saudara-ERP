<div>
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Edit Retur: {{ $return->code }} (Snapshot Transaksi: {{ $snapshot['header']['transaction_code'] }})</strong>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-sm-4">
                            <h6 class="mb-3">Informasi Transaksi:</h6>
                            <div>Struk: <strong>{{ $snapshot['header']['receipt_number'] }}</strong></div>
                            <div>Tanggal: {{ \Carbon\Carbon::parse($snapshot['header']['date'])->format('d M Y H:i') }}</div>
                            <div>Pelanggan: {{ $snapshot['header']['customer_name'] ?? 'Guest' }}</div>
                        </div>
                        <div class="col-sm-4">
                            <h6 class="mb-3">Pembayaran Asli:</h6>
                            @foreach($snapshot['payments'] as $payment)
                                <div>{{ $payment['method_name'] }}: {{ format_currency($payment['amount']) }}</div>
                            @endforeach
                            <div class="mt-2">Total: <strong>{{ format_currency($snapshot['header']['grand_total']) }}</strong></div>
                        </div>
                        <div class="col-sm-4">
                            <h6 class="mb-3">Informasi Tambahan:</h6>
                            <div class="alert alert-info py-2">
                                <small><i class="fas fa-info-circle mr-1"></i> Opsi penyelesaian (Tunai/Ganti) akan ditentukan oleh Approver saat proses persetujuan.</small>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th>Produk</th>
                                    <th class="text-center" title="Jumlah produk yang dapat diretur dari pembelian awal">
                                        Tersedia
                                        <i class="fas fa-info-circle text-muted" style="font-size: 0.85em; cursor: help;" data-toggle="tooltip" data-placement="top" title="Jumlah produk yang tersedia untuk diretur (termasuk yang sudah ada di retur ini)"></i>
                                    </th>
                                    <th class="text-center" style="width: 200px;">Jumlah Retur</th>
                                    <th class="text-right">Harga Satuan</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($snapshot['lines'] as $line)
                                    @php
                                        $productId = $line['product_id'];
                                        $currentlyInReturn = (float) $return->lines()->where('product_id', $productId)->sum('quantity');
                                        $availableQuantity = (float) $line['returnable_quantity'] + $currentlyInReturn;
                                        $isReturnable = $availableQuantity > 0;
                                        $isTracked = ($line['is_tracked'] ?? false) || !empty($line['serial_numbers']);
                                    @endphp
                                    
                                    {{-- Main Product Row --}}
                                    <tr wire:key="product-row-{{ $productId }}" class="{{ $line['is_bundle'] ? 'bundle-parent-row' : '' }}">
                                        <td>
                                            <div class="font-weight-bold">{{ $line['product_name'] }}</div>
                                            <small class="text-muted">{{ $line['product_code'] }}</small>
                                            @if($line['is_bundle'])
                                                <div class="mt-1">
                                                    <small class="badge badge-info">Bundle</small>
                                                </div>
                                            @elseif($isTracked)
                                                <div class="mt-1">
                                                    <small class="badge badge-primary">Tracked Serial</small>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <strong>{{ $availableQuantity }}</strong>
                                            @if($line['returned_quantity'] - $currentlyInReturn > 0)
                                                <div class="small text-danger">Retur Lain: {{ $line['returned_quantity'] - $currentlyInReturn }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($isReturnable)
                                                @if($isTracked)
                                                    <div class="input-group input-group-sm" x-data>
                                                        <input type="text" 
                                                               id="serial-input-{{ $productId }}"
                                                               wire:model.defer="serialInputs.{{ $productId }}" 
                                                               wire:keydown.enter.prevent="addSerialByScan({{ $productId }})"
                                                               x-on:serial-scanned.window="if ($event.detail.productId == {{ $productId }}) { $el.value = ''; $el.focus(); }"
                                                               class="form-control text-center @error('serialInputs.'.$productId) is-invalid @enderror" 
                                                               placeholder="Scan/Type SN...">
                                                        <div class="input-group-append">
                                                            <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                                        </div>
                                                    </div>
                                                    <div class="mt-2">
                                                        <input type="number" 
                                                               value="{{ $quantities[$productId] ?? 0 }}" 
                                                               class="form-control form-control-sm text-center bg-light font-weight-bold" 
                                                               readonly 
                                                               title="Jumlah otomatis dari scan serial number">
                                                        <small class="text-muted">Jumlah terpilih</small>
                                                    </div>
                                                @else
                                                    <input type="number"
                                                           wire:model.live="quantities.{{ $productId }}"
                                                           class="form-control form-control-sm text-center font-weight-bold"
                                                           min="0"
                                                           max="{{ $availableQuantity }}"
                                                           step="1">
                                                @endif
                                            @else
                                                <span class="badge badge-secondary">Habis</span>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ format_currency($line['unit_price']) }}</td>
                                        <td class="text-right">
                                            {{ format_currency(($quantities[$productId] ?? 0) * $line['unit_price']) }}
                                        </td>
                                    </tr>

                                    {{-- Bundle Item Rows --}}
                                    @if($line['is_bundle'] && !empty($line['bundle_items']))
                                        @foreach($line['bundle_items'] as $bundleItem)
                                            @php
                                                $maxBundleItemAvailable = $availableQuantity * $bundleItem['quantity_per_bundle'];
                                                $selectedBundleItemQty = ($quantities[$productId] ?? 0) * $bundleItem['quantity_per_bundle'];
                                            @endphp
                                            <tr wire:key="bundle-item-{{ $productId }}-{{ $bundleItem['product_id'] }}" class="bundle-item-row table-light">
                                                <td class="pl-5">
                                                    <div>{{ $bundleItem['product_name'] }}</div>
                                                    <small class="text-muted">{{ $bundleItem['product_code'] }}</small>
                                                </td>
                                                <td class="text-center text-muted">
                                                    <small>({{ $bundleItem['quantity_per_bundle'] }} per bundle)</small><br>
                                                    <strong>{{ $maxBundleItemAvailable }}</strong>
                                                </td>
                                                <td class="text-center text-muted">
                                                    <small>Otomatis</small><br>
                                                    <small class="text-muted">{{ $selectedBundleItemQty }}</small>
                                                </td>
                                                <td class="text-right text-muted">
                                                    <small>-</small>
                                                </td>
                                                <td class="text-right text-muted">
                                                    <small>-</small>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif

                                    {{-- Serial Numbers Selection Row --}}
                                    @if($isTracked && $isReturnable)
                                        <tr wire:key="serials-row-{{ $productId }}">
                                            <td colspan="5" class="bg-light py-2">
                                                <div class="small font-weight-bold mb-1 text-primary">Serial Numbers Terpilih:</div>
                                                <div class="d-flex flex-wrap gap-2 mb-2">
                                                    @forelse($selectedSerials[$productId] ?? [] as $snId)
                                                        @php
                                                            $snCode = collect($line['serial_numbers'])->firstWhere('id', $snId)['serial_number'] ?? $snId;
                                                        @endphp
                                                        <span wire:key="selected-sn-{{ $productId }}-{{ $snId }}" class="badge badge-info p-2 mr-1 mb-1">
                                                            {{ $snCode }}
                                                            <i class="fas fa-times ml-1 cursor-pointer" wire:click="removeSerial({{ $productId }}, {{ $snId }})" style="cursor: pointer;"></i>
                                                        </span>
                                                    @empty
                                                        <small class="text-muted italic">Belum ada serial yang discan/dipilih.</small>
                                                    @endforelse
                                                </div>
                                                
                                                <div class="p-4 border-primary rounded shadow-lg mb-4 {{ ($showAvailableSerials[$productId] ?? false) ? '' : 'd-none' }}" 
                                                     style="border: 2px solid #007bff !important; background-color: #f0f7ff !important; min-height: 100px;">
                                                    <div class="d-flex align-items-center mb-3">
                                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 35px; height: 35px;">
                                                            <i class="fas fa-hand-pointer"></i>
                                                        </div>
                                                        <h6 class="font-weight-bold text-primary mb-0" style="font-size: 1.1rem;">Pilih Manual Serial Tersedia:</h6>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                                        @foreach($line['serial_numbers'] as $sn)
                                                            @php
                                                                $isSelected = in_array($sn['id'], $selectedSerials[$productId] ?? []);
                                                            @endphp
                                                            <button wire:key="available-sn-{{ $productId }}-{{ $sn['id'] }}" 
                                                                    wire:click="toggleSerial({{ $productId }}, {{ $sn['id'] }})" 
                                                                    type="button"
                                                                    class="btn {{ $isSelected ? 'btn-primary shadow' : 'btn-outline-primary bg-white' }} btn-md mr-2 mb-2 px-4 py-2 font-weight-bold"
                                                                    style="transition: all 0.2s;">
                                                                {{ $sn['serial_number'] }}
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                    <div class="alert alert-info border-0 mb-0 py-2">
                                                        <i class="fas fa-info-circle mr-2"></i> 
                                                        <strong>Tips:</strong> Klik nomor serial di atas untuk memilih produk yang akan diretur. 
                                                        Serial yang dipilih akan berwarna biru penuh.
                                                    </div>
                                                </div>
                                                <div class="text-center mt-1">
                                                    <a href="javascript:void(0)" wire:click="toggleAvailableSerials({{ $productId }})" class="small">
                                                        Tampilkan/Sembunyikan Semua Serial Tersedia
                                                    </a>
                                                </div>
                                                @error("serialInputs.{$productId}") <div class="text-danger small">{{ $message }}</div> @enderror
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4" class="text-right">Total Retur Baru:</th>
                                    <th class="text-right">
                                        @php
                                            $totalRetur = 0;
                                            foreach($snapshot['lines'] as $line) {
                                                $totalRetur += ($quantities[$line['product_id']] ?? 0) * $line['unit_price'];
                                            }
                                        @endphp
                                        <strong>{{ format_currency($totalRetur) }}</strong>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    @if($error)
                        <div class="alert alert-danger mt-3">
                            {{ $error }}
                        </div>
                    @endif

                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <a href="{{ route('pos.returns.show', $return->id) }}" class="btn btn-secondary btn-lg">Batal</a>
                        <button wire:click="submit" class="btn btn-success btn-lg" wire:loading.attr="disabled">
                            <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" role="status"></span>
                            Simpan Perubahan Retur
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
