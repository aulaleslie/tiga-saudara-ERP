@if($purchase_return->settlementItems->isNotEmpty())
<div class="mt-5">
    <div class="d-flex align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-check mr-2"></i>Penyelesaian Per Item
        </h5>
    </div>
    
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="pl-3" style="width: 25%;">Produk</th>
                        <th style="width: 15%;">Serial Number</th>
                        <th style="width: 15%;">Metode</th>
                        <th class="text-end" style="width: 15%;">Nominal</th>
                        <th class="text-center" style="width: 15%;">Status</th>
                        <th class="text-center d-print-none" style="width: 15%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
@php
    $methodLabels = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::settlementMethods();
@endphp

                    @foreach($purchase_return->settlementItems as $item)
                    <tr>
                        <td class="pl-3">
                            <div class="font-weight-bold text-wrap">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                            <small class="text-muted">{{ $item->detail?->product_code ?? '-' }}</small>
                        </td>
                        <td>
                            @if($item->serialNumber)
                                <span class="badge bg-secondary">{{ $item->serialNumber->serial_number }}</span>
                            @else
                                <span class="text-muted small">N/A</span>
                            @endif
                        </td>
                        <td>
                            @if($item->method)
                                @php 
                                    $methodKey = strtoupper(str_replace(' ', '_', trim($item->method))); 
                                    $isPurchaseLinked = in_array($methodKey, ['MODIFY_PURCHASE', 'CREDIT', 'CASH'], true);
                                    $targetPurchase = $item->targetPurchase;
                                @endphp
                                <span class="font-weight-normal text-primary">{{ $methodLabels[$methodKey] ?? $methodLabels[$item->method] ?? $item->method }}</span>
                                @if($isPurchaseLinked)
                                    <div class="small text-muted mt-1">
                                        <div>Nomor Pembelian Supplier: {{ $targetPurchase?->supplier_purchase_number ?: '-' }}</div>
                                        <div>Referensi: {{ $targetPurchase?->reference ?: '-' }}</div>
                                    </div>
                                @endif
                            @else
                                <span class="text-muted small italic">Belum ditentukan</span>
                            @endif
                        </td>
                        <td class="text-end font-weight-bold">
                            {{ format_currency($item->getEffectiveNominal()) }}
                        </td>
                        <td class="text-center">
                            @include('purchasesreturn::partials.item-settlement-status', ['item' => $item])
                        </td>
                        <td class="text-center d-print-none">
                            @if($item->status === 'SUBMITTED')
                                @can('purchaseReturnSettlements.approve')
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-success border-0" title="Setujui" data-toggle="modal" data-target="#approveItemModal{{ $item->id }}" data-bs-toggle="modal" data-bs-target="#approveItemModal{{ $item->id }}">
                                        <i class="bi bi-check-circle-fill"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" title="Tolak" data-toggle="modal" data-target="#rejectItemModal{{ $item->id }}" data-bs-toggle="modal" data-bs-target="#rejectItemModal{{ $item->id }}">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                                @else
                                <span class="text-muted small">-</span>
                                @endcan
                            @elseif($item->status === 'APPROVED' && in_array(strtoupper($item->method), ['CREDIT', 'CASH']))
                                @php
                                    $isCredit = strtoupper($item->method) === 'CREDIT';
                                @endphp
                                @if($isCredit && $item->target_purchase_id)
                                    @can('purchasePayments.access')
                                        <a href="{{ route('purchase-payments.index', $item->target_purchase_id) }}" class="btn btn-sm btn-outline-info border-0" title="Lihat Pembayaran (Kredit)">
                                            <i class="bi bi-eye-fill"></i>
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endcan
                                @elseif(!$isCredit)
                                    @can('purchaseReturnPayments.access')
                                        <a href="{{ route('purchase-return-payments.index', $item->purchase_return_id) }}" class="btn btn-sm btn-outline-info border-0" title="Lihat Pembayaran (Tunai)">
                                            <i class="bi bi-eye-fill"></i>
                                        </a>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endcan
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            @elseif($item->status === 'APPROVED_AWAITING_RECEIVE')
                                @can('purchaseReturnSettlements.receive')
                                <button type="button" class="btn btn-sm btn-warning border-0" title="Terima Barang" data-toggle="modal" data-target="#receiveItemModal{{ $item->id }}" data-bs-toggle="modal" data-bs-target="#receiveItemModal{{ $item->id }}">
                                    <i class="bi bi-box-seam-fill"></i>
                                </button>
                                @else
                                <span class="text-muted small">-</span>
                                @endcan
                            @else
                                <span class="text-muted small">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@foreach($purchase_return->settlementItems->where('status', 'SUBMITTED') as $item)
<div class="modal fade" id="approveItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="approveItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('purchase-return-settlements.item.approve', $item->id) }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveItemModalLabel{{ $item->id }}">Setujui Item: {{ $item->detail?->product_name }}</h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Produk:</strong></div>
                        <div class="col-sm-8">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Serial Number:</strong></div>
                        <div class="col-sm-8">{{ $item->serialNumber?->serial_number ?? 'N/A' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Metode:</strong></div>
                        <div class="col-sm-8">
                            @php 
                                $methodKey = strtoupper(str_replace(' ', '_', trim($item->method))); 
                            @endphp
                            {{ $methodLabels[$methodKey] ?? $methodLabels[$item->method] ?? $item->method }}
                        </div>
                    </div>
                    @if(in_array($methodKey, ['MODIFY_PURCHASE', 'CREDIT', 'CASH'], true))
                        @php
                            $targetPurchase = $item->targetPurchase;
                        @endphp
                        <div class="row mb-3">
                            <div class="col-sm-4"><strong>Nomor Pembelian Supplier:</strong></div>
                            <div class="col-sm-8">{{ $targetPurchase?->supplier_purchase_number ?: '-' }}</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4"><strong>Referensi:</strong></div>
                            <div class="col-sm-8">{{ $targetPurchase?->reference ?: '-' }}</div>
                        </div>
                        
                        @if($methodKey === 'MODIFY_PURCHASE')
                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Target Alokasi Dana (Opsional)</label>
                                @livewire('purchase-return.unpaid-purchase-search-dropdown', [
                                    'supplier_id' => $purchase_return->supplier_id,
                                    'exclude_purchase_id' => $targetPurchase?->id,
                                    'name' => 'allocation_purchase_id',
                                    'placeholder' => '-- Biarkan Kosong (Refund Manual / Tanpa Alokasi) --',
                                    'zIndex' => 1100
                                ], key('unpaid-purchase-dropdown-' . $item->id))
                                <small class="text-muted">Pilih nota lain untuk memindahkan "Uang Retur" sebagai pembayaran nota tersebut.</small>
                            </div>
                        @endif
                    @endif
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Nominal:</strong></div>
                        <div class="col-sm-8">{{ format_currency($item->getEffectiveNominal()) }}</div>
                    </div>
                    @if($methodKey === 'CREDIT')
                        <hr>
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">Catatan Approval</label>
                            <textarea name="approval_note" class="form-control" rows="2" placeholder="Catatan untuk pembayaran (opsional)..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">Lampiran (JPG, PNG, PDF)</label>
                            <input type="file" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted">Opsional. Maksimal 5MB per file.</small>
                        </div>
                    @endif
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle mr-2"></i>
                        Pastikan data penyelesaian sudah benar sebelum menyetujui.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Setujui Item</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach



@foreach($purchase_return->settlementItems->where('status', 'APPROVED_AWAITING_RECEIVE') as $item)
@php
    $isProductRepair = strtoupper($item->method) === 'PRODUCT_REPAIR';
    $isBrokenStock = strtoupper($item->method) === 'BROKEN_STOCK';
    $isSerial = $item->serialNumber !== null;
    $expectedQuantity = $isSerial ? 1 : ($item->detail?->quantity ?? 1);
    $quantityLocked = $isProductRepair || $isBrokenStock;
@endphp
<div class="modal fade" id="receiveItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="receiveItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('purchase-return-settlements.item.receive', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="receiveItemModalLabel{{ $item->id }}">
                        Terima Item: {{ $item->detail?->product_name }}
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    {{-- Product Info --}}
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Produk:</strong></div>
                        <div class="col-sm-8">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Metode:</strong></div>
                        <div class="col-sm-8">
                            {{ $methodLabels[$item->method] ?? $item->method }}
                        </div>
                    </div>
                    
                    {{-- PRODUCT_REPAIR with Serial: Show old serial and replacement input --}}
                    @if($isProductRepair && $isSerial)
                        <div class="alert alert-light border mb-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-upc-scan mr-2 text-primary fs-4"></i>
                                <div>
                                    <small class="text-muted d-block">Serial Lama</small>
                                    <strong class="fs-5">{{ $item->serialNumber->serial_number }}</strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">
                                Serial Pengganti <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="replacement_serial_number" class="form-control prevent-enter-submit" required
                                   value="{{ $item->serialNumber->serial_number }}"
                                   placeholder="Masukkan serial number pengganti...">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> 
                                Jika serial sama (barang diperbaiki), biarkan tidak berubah.
                                Jika serial berbeda (barang diganti), masukkan serial baru.
                            </small>
                        </div>
                    @elseif($isSerial)
                        {{-- Non-repair serial: just show serial info --}}
                        <div class="row mb-3">
                            <div class="col-sm-4"><strong>Serial Number:</strong></div>
                            <div class="col-sm-8">
                                <span class="badge bg-secondary">{{ $item->serialNumber->serial_number }}</span>
                            </div>
                        </div>
                    @endif

                    {{-- Location Selection --}}
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">
                            Lokasi Tujuan <span class="text-danger">*</span>
                        </label>
                        @if($quantityLocked)
                            <input type="text" class="form-control" value="{{ $item->detail->location->name ?? 'N/A' }}" readonly>
                            <input type="hidden" name="location_id" value="{{ $item->detail->location_id }}">
                            <small class="text-muted"><i class="bi bi-lock"></i> Lokasi terkunci sesuai dokumen retur.</small>
                        @else
                            @livewire('modules.setting.location-search-dropdown', [
                                'selected' => $item->detail->location_id ?? $purchase_return->location_id,
                                'name' => 'location_id',
                                'placeholder' => 'Pilih Lokasi...',
                                'zIndex' => 1100
                            ], key('location-dropdown-' . $item->id))
                        @endif
                    </div>

                    {{-- Quantity field --}}
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">
                            Jumlah Diterima <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="received_quantity" class="form-control" 
                               min="1" value="{{ $expectedQuantity }}" 
                               {{ $quantityLocked ? 'readonly' : '' }} required>
                        @if($quantityLocked)
                            <small class="text-muted">
                                <i class="bi bi-lock"></i> Jumlah terkunci sesuai dengan data penyelesaian.
                            </small>
                        @endif
                    </div>

                    {{-- Note --}}
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Catatan penerimaan..."></textarea>
                    </div>

                    {{-- Info message based on method --}}
                    <div class="alert {{ $isBrokenStock ? 'alert-warning' : 'alert-info' }} mb-0">
                        <i class="bi {{ $isBrokenStock ? 'bi-exclamation-triangle' : 'bi-info-circle' }} mr-2"></i>
                        @if($isBrokenStock)
                            Barang akan dicatat sebagai stok rusak di lokasi yang dipilih.
                            Stok akan dikurangi dari lokasi asal ({{ $purchase_return->location?->name ?? 'N/A' }}).
                        @elseif($isProductRepair && $isSerial)
                            Jika serial sama, status akan dikembalikan ke aktif.
                            Jika serial berbeda, serial lama akan ditandai "Returned" dan serial baru akan dibuat.
                        @elseif($isProductRepair && !$isSerial)
                            Stok akan dipindahkan ke lokasi yang dipilih. Transaksi pergerakan stok akan dicatat.
                        @else
                            Barang akan dipindahkan ke lokasi yang dipilih.
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Terima Barang</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach
