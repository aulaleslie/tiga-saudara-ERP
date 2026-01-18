@if($purchase_return->settlementItems->isNotEmpty())
<div class="mt-5">
    <div class="d-flex align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-check me-2"></i>Penyelesaian Per Item
        </h5>
        @if($purchase_return->settlementItems->every(fn($i) => $i->status === 'APPROVED'))
             <span class="ms-3 badge bg-success text-uppercase">Fully Settled</span>
        @elseif($purchase_return->settlementItems->contains(fn($i) => $i->status === 'APPROVED'))
             <span class="ms-3 badge bg-warning text-dark text-uppercase">Partially Settled</span>
        @endif
    </div>
    
    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-3" style="width: 25%;">Produk</th>
                        <th style="width: 15%;">Serial Number</th>
                        <th style="width: 15%;">Metode</th>
                        <th class="text-end" style="width: 15%;">Nominal</th>
                        <th class="text-center" style="width: 15%;">Status</th>
                        <th class="text-center d-print-none" style="width: 15%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchase_return->settlementItems as $item)
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold text-wrap">{{ $item->detail?->product_name ?? 'N/A' }}</div>
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
                                <span class="fw-medium text-primary">{{ str_replace('_', ' ', $item->method) }}</span>
                            @else
                                <span class="text-muted small italic">Belum ditentukan</span>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">
                            {{ format_currency($item->getEffectiveNominal()) }}
                        </td>
                        <td class="text-center">
                            @include('purchasesreturn::partials.item-settlement-status', ['item' => $item])
                        </td>
                        <td class="text-center d-print-none">
                            @if($item->status === 'SUBMITTED')
                                @can('purchaseReturnSettlements.approve')
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-success border-0" title="Setujui" data-bs-toggle="modal" data-bs-target="#approveItemModal{{ $item->id }}">
                                        <i class="bi bi-check-circle-fill"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger border-0" title="Tolak" data-bs-toggle="modal" data-bs-target="#rejectItemModal{{ $item->id }}">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                </div>
                                @else
                                <span class="text-muted small">-</span>
                                @endcan
                            @elseif($item->status === 'APPROVED_AWAITING_RECEIVE')
                                @can('purchaseReturnSettlements.receive')
                                <button type="button" class="btn btn-sm btn-warning border-0" title="Terima Barang" data-bs-toggle="modal" data-bs-target="#receiveItemModal{{ $item->id }}">
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
        <form method="POST" action="{{ route('purchase-return-settlements.item.approve', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveItemModalLabel{{ $item->id }}">Setujui Item: {{ $item->detail?->product_name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <div class="col-sm-8">{{ str_replace('_', ' ', $item->method ?? 'N/A') }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Nominal:</strong></div>
                        <div class="col-sm-8">{{ format_currency($item->getEffectiveNominal()) }}</div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Pastikan data penyelesaian sudah benar sebelum menyetujui.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Setujui Item</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach

@foreach($purchase_return->settlementItems->where('status', 'APPROVED_AWAITING_RECEIVE') as $item)
<div class="modal fade" id="receiveItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="receiveItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('purchase-return-settlements.item.receive', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="receiveItemModalLabel{{ $item->id }}">Terima Item: {{ $item->detail?->product_name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <div class="col-sm-8">{{ str_replace('_', ' ', $item->method ?? 'N/A') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Lokasi Tujuan <span class="text-danger">*</span></label>
                        <select name="location_id" class="form-select" required>
                            <option value="">Pilih Lokasi...</option>
                            @foreach($locations ?? [] as $location)
                                <option value="{{ $location->id }}">{{ $location->name }} ({{ $location->setting?->company_name ?? 'N/A' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Jumlah Diterima <span class="text-danger">*</span></label>
                        <input type="number" name="received_quantity" class="form-control" min="1" value="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Catatan penerimaan..."></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Barang akan dipindahkan ke lokasi yang dipilih.
                        @if(in_array(strtoupper($item->method), ['BROKEN_STOCK']))
                            Serial number akan ditandai sebagai rusak.
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Terima Barang</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach
