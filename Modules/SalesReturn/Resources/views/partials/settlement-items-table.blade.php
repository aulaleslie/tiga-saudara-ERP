@php
    use Modules\SalesReturn\Entities\SaleReturnDetail;
    use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
    use Illuminate\Support\Facades\Storage;

    $methodLabels = SaleReturnDetail::selectableSettlementMethods();
@endphp

@if($sale_return->settlementItems->isNotEmpty())
@php
    $allSettled = $sale_return->settlementItems->every(fn($i) => in_array($i->status, [
        SaleReturnItemSettlement::STATUS_APPROVED,
        SaleReturnItemSettlement::STATUS_DISPATCHED
    ]));
    $anySettled = $sale_return->settlementItems->contains(fn($i) => in_array($i->status, [
        SaleReturnItemSettlement::STATUS_APPROVED,
        SaleReturnItemSettlement::STATUS_DISPATCHED
    ]));
@endphp

<div class="mt-4 mb-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0 text-primary fw-bold">
            <i class="bi bi-list-check me-2"></i>Penyelesaian Per Item
        </h5>
        <div>
            @if($allSettled)
                <span class="badge bg-success px-3 py-2 text-uppercase">Fully Settled</span>
            @elseif($anySettled)
                <span class="badge bg-warning text-dark px-3 py-2 text-uppercase">Partially Settled</span>
            @endif
        </div>
    </div>
    <div class="table-responsive border rounded bg-white shadow-sm">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light text-muted small text-uppercase">
                <tr>
                    <th class="ps-4 py-3" style="width: 25%;">Produk</th>
                    <th class="py-3" style="width: 15%;">Metode</th>
                    <th class="py-3" style="width: 20%;">Detail</th>
                    <th class="py-3 text-end" style="width: 15%;">Nominal</th>
                    <th class="py-3 text-center" style="width: 12%;">Status</th>
                    <th class="py-3 text-center d-print-none" style="width: 13%;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale_return->settlementItems as $item)
                <tr>
                    <td class="ps-4">
                        <div class="fw-bold text-dark">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                        <div class="text-muted small mb-1">{{ $item->detail?->product_code ?? '-' }}</div>
                        @if($item->serialNumber)
                            <span class="badge bg-light text-dark border"><i class="bi bi-upc-scan me-1"></i>{{ $item->serialNumber->serial_number }}</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $methodClass = match($item->method) {
                                SaleReturnDetail::METHOD_CASH_REFUND => 'bg-success-soft text-success border-success-subtle',
                                SaleReturnDetail::METHOD_PRODUCT_REPAIR => 'bg-info-soft text-info border-info-subtle',
                                SaleReturnDetail::METHOD_UNPROCESSED => 'bg-secondary-soft text-secondary border-secondary-subtle',
                                default => 'bg-light text-muted',
                            };
                            $style = "padding: 0.4em 0.8em; border: 1px solid;";
                        @endphp
                        @if($item->method)
                            <span class="badge {{ $methodClass }} rounded-pill" style="{{ $style }}">
                                {{ $methodLabels[$item->method] ?? $item->method }}
                            </span>
                        @else
                            <span class="text-muted small fst-italic">Belum ditentukan</span>
                        @endif
                    </td>
                    <td>
                        <div class="small">
                            @if($item->method === SaleReturnDetail::METHOD_CASH_REFUND)
                                @if($item->proof_path)
                                    <a href="{{ Storage::url($item->proof_path) }}" target="_blank" class="text-primary text-decoration-none d-flex align-items-center">
                                        <i class="bi bi-file-earmark-image me-1"></i> Lihat Bukti
                                    </a>
                                @else
                                    <span class="text-muted fst-italic">Tanpa bukti</span>
                                @endif
                            @elseif($item->method === SaleReturnDetail::METHOD_PRODUCT_REPAIR)
                                @if($item->new_serial_number)
                                    <div class="mb-1"><span class="text-muted">Serial Baru:</span> <span class="fw-semibold text-dark">{{ $item->new_serial_number }}</span></div>
                                @endif
                                @if($item->location)
                                    <div><span class="text-muted">Lokasi:</span> <span class="fw-semibold text-dark">{{ $item->location->name }}</span></div>
                                @endif
                                @if(!$item->new_serial_number && !$item->location)
                                    <span class="text-muted">-</span>
                                @endif
                            @elseif($item->method === SaleReturnDetail::METHOD_UNPROCESSED)
                                @if($item->notes)
                                    <div class="text-muted fst-italic" title="{{ $item->notes }}">
                                        <i class="bi bi-chat-left-text me-1"></i>{{ Str::limit($item->notes, 40) }}
                                    </div>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </div>
                    </td>
                    <td class="text-end fw-bold text-dark">
                        {{ format_currency($item->getEffectiveNominal()) }}
                    </td>
                    <td class="text-center">
                        @include('salesreturn::partials.item-settlement-status', ['item' => $item])
                        @if($item->status === SaleReturnItemSettlement::STATUS_REJECTED && $item->rejection_reason)
                            <div class="small text-danger mt-1" title="{{ $item->rejection_reason }}">
                                <i class="bi bi-info-circle"></i> {{ Str::limit($item->rejection_reason, 20) }}
                            </div>
                        @endif
                    </td>
                    <td class="text-center d-print-none">
                        <div class="btn-group shadow-sm">
                            @if($item->status === SaleReturnItemSettlement::STATUS_SUBMITTED)
                                @can('saleReturnSettlements.approve')
                                    <button type="button" class="btn btn-sm btn-white text-success border" title="Setujui" 
                                        data-toggle="modal" data-target="#approveItemModal{{ $item->id }}"
                                        data-bs-toggle="modal" data-bs-target="#approveItemModal{{ $item->id }}">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-white text-danger border" title="Tolak" 
                                        data-toggle="modal" data-target="#rejectItemModal{{ $item->id }}"
                                        data-bs-toggle="modal" data-bs-target="#rejectItemModal{{ $item->id }}">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                @else
                                    <span class="text-muted small">-</span>
                                @endcan
                            @elseif($item->status === SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH)
                                @can('saleReturnSettlements.dispatch')
                                    <button type="button" class="btn btn-sm btn-warning" title="Proses Pengiriman" 
                                        data-toggle="modal" data-target="#dispatchItemModal{{ $item->id }}"
                                        data-bs-toggle="modal" data-bs-target="#dispatchItemModal{{ $item->id }}">
                                        <i class="bi bi-truck"></i> Proses
                                    </button>
                                @else
                                    <span class="text-muted small">-</span>
                                @endcan
                            @elseif($item->status === SaleReturnItemSettlement::STATUS_APPROVED && $item->method === SaleReturnDetail::METHOD_CASH_REFUND)
                                @can('saleReturnPayments.access')
                                    <a href="{{ route('sale-return-payments.index', $sale_return->id) }}" class="btn btn-sm btn-outline-info" title="Lihat Pembayaran">
                                        <i class="bi bi-cash-stack"></i>
                                    </a>
                                @else
                                    <span class="text-muted small">-</span>
                                @endcan
                            @else
                                <span class="text-muted small text-muted">Selesai</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<style>
    .bg-success-soft { background-color: rgba(25, 135, 84, 0.1); }
    .bg-info-soft { background-color: rgba(13, 202, 240, 0.1); }
    .bg-secondary-soft { background-color: rgba(108, 117, 125, 0.1); }
</style>
@endif

{{-- Approve Modals --}}
@foreach($sale_return->settlementItems->where('status', SaleReturnItemSettlement::STATUS_SUBMITTED) as $item)
<div class="modal fade" id="approveItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="approveItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('sale-return-settlements.item.approve', $item->id) }}">
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
                    @if($item->serialNumber)
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Serial Number:</strong></div>
                        <div class="col-sm-8">{{ $item->serialNumber->serial_number }}</div>
                    </div>
                    @endif
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Metode:</strong></div>
                        <div class="col-sm-8">{{ $methodLabels[$item->method] ?? $item->method }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Nominal:</strong></div>
                        <div class="col-sm-8">{{ format_currency($item->getEffectiveNominal()) }}</div>
                    </div>
                    @if($item->notes)
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Catatan:</strong></div>
                        <div class="col-sm-8">{{ $item->notes }}</div>
                    </div>
                    @endif
                    <hr>
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Catatan Approval</label>
                        <textarea name="approval_note" class="form-control" rows="2" placeholder="Catatan persetujuan (opsional)..."></textarea>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle mr-2"></i>
                        @if(in_array($item->method, [SaleReturnDetail::METHOD_PRODUCT_REPAIR, SaleReturnDetail::METHOD_UNPROCESSED]))
                            Item akan disetujui dan menunggu proses pengiriman.
                        @else
                            Pastikan data penyelesaian sudah benar sebelum menyetujui.
                        @endif
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

{{-- Reject Modals --}}
@foreach($sale_return->settlementItems->where('status', SaleReturnItemSettlement::STATUS_SUBMITTED) as $item)
<div class="modal fade" id="rejectItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="rejectItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('sale-return-settlements.item.reject', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectItemModalLabel{{ $item->id }}">Tolak Item: {{ $item->detail?->product_name }}</h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Produk:</strong></div>
                        <div class="col-sm-8">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                    </div>
                    @if($item->serialNumber)
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Serial Number:</strong></div>
                        <div class="col-sm-8">{{ $item->serialNumber->serial_number }}</div>
                    </div>
                    @endif
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Metode:</strong></div>
                        <div class="col-sm-8">{{ $methodLabels[$item->method] ?? $item->method }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>Nominal:</strong></div>
                        <div class="col-sm-8">{{ format_currency($item->getEffectiveNominal()) }}</div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label font-weight-bold">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Masukkan alasan penolakan..." required></textarea>
                    </div>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle mr-2"></i>
                        Item yang ditolak dapat diajukan ulang setelah direvisi.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Tolak Item</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach

{{-- Dispatch Modals --}}
@foreach($sale_return->settlementItems->where('status', SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH) as $item)
@php
    $isSerial = $item->serialNumber !== null;
    $isRepair = strtoupper($item->method) === 'REPAIR';
@endphp
<div class="modal fade" id="dispatchItemModal{{ $item->id }}" tabindex="-1" aria-labelledby="dispatchItemModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('sale-return-settlements.item.dispatch', $item->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dispatchItemModalLabel{{ $item->id }}">
                        Proses Pengiriman: {{ $item->detail?->product_name }}
                    </h5>
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
                        <div class="col-sm-4"><strong>Metode:</strong></div>
                        <div class="col-sm-8">{{ $methodLabels[$item->method] ?? $item->method }}</div>
                    </div>

                    @if($isSerial && $isRepair)
                        <div class="alert alert-light border mb-3">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-upc-scan mr-2 text-primary fs-4"></i>
                                <div>
                                    <small class="text-muted d-block">Serial Asli</small>
                                    <strong class="fs-5">{{ $item->serialNumber->serial_number }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">
                                Serial Pengganti/Perbaikan <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="dispatched_serial_number" class="form-control" required
                                   value="{{ $item->serialNumber->serial_number }}"
                                   placeholder="Masukkan serial number yang dikirim...">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i>
                                Jika serial sama (barang diperbaiki), biarkan tidak berubah.
                                Jika serial berbeda (barang diganti), masukkan serial baru.
                            </small>
                        </div>
                    @elseif($isSerial)
                        <div class="row mb-3">
                            <div class="col-sm-4"><strong>Serial Number:</strong></div>
                            <div class="col-sm-8">
                                <span class="badge bg-secondary">{{ $item->serialNumber->serial_number }}</span>
                            </div>
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Catatan Pengiriman</label>
                        <textarea name="dispatch_note" class="form-control" rows="2" placeholder="Catatan pengiriman (opsional)..."></textarea>
                    </div>

                    <div class="alert {{ $item->method === SaleReturnDetail::METHOD_UNPROCESSED ? 'alert-warning' : 'alert-info' }} mb-0">
                        <i class="bi {{ $item->method === SaleReturnDetail::METHOD_UNPROCESSED ? 'bi-exclamation-triangle' : 'bi-info-circle' }} mr-2"></i>
                        @if($item->method === SaleReturnDetail::METHOD_UNPROCESSED)
                            Barang akan dikembalikan ke pelanggan tanpa diproses.
                        @elseif($isRepair && $isSerial)
                            Barang pengganti/perbaikan akan dikirim ke pelanggan.
                        @else
                            Barang akan dikirim ke pelanggan.
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Proses Pengiriman</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endforeach
