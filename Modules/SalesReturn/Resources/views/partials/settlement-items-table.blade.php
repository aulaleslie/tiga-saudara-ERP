@php
    use Modules\SalesReturn\Entities\SaleReturnDetail;
    use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
    use Illuminate\Support\Facades\Storage;

    $methodLabels = SaleReturnDetail::selectableSettlementMethods();
@endphp

@if($sale_return->settlementItems->isNotEmpty())
<div class="mt-5">
    <div class="d-flex align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-check mr-2"></i>Penyelesaian Per Item
        </h5>
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
        @if($allSettled)
            <span class="ml-3 badge bg-success text-uppercase">Fully Settled</span>
        @elseif($anySettled)
            <span class="ml-3 badge bg-warning text-dark text-uppercase">Partially Settled</span>
        @endif
    </div>

    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="pl-3" style="width: 25%;">Produk</th>
                        <th style="width: 15%;">Metode</th>
                        <th style="width: 20%;">Detail</th>
                        <th class="text-end" style="width: 15%;">Nominal</th>
                        <th class="text-center" style="width: 12%;">Status</th>
                        <th class="text-center d-print-none" style="width: 13%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sale_return->settlementItems as $item)
                    <tr>
                        <td class="pl-3">
                            <div class="font-weight-bold text-wrap">{{ $item->detail?->product_name ?? 'N/A' }}</div>
                            <small class="text-muted">{{ $item->detail?->product_code ?? '-' }}</small>
                            @if($item->serialNumber)
                                <div class="mt-1">
                                    <span class="badge bg-secondary">{{ $item->serialNumber->serial_number }}</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($item->method)
                                <span class="font-weight-normal text-primary">{{ $methodLabels[$item->method] ?? $item->method }}</span>
                            @else
                                <span class="text-muted small italic">Belum ditentukan</span>
                            @endif
                        </td>
                        <td>
                            @if($item->method === SaleReturnDetail::METHOD_CASH_REFUND)
                                @if($item->proof_path)
                                    <a href="{{ Storage::url($item->proof_path) }}" target="_blank" class="small text-primary text-decoration-none">
                                        <i class="bi bi-paperclip"></i> Bukti
                                    </a>
                                @endif
                            @elseif($item->method === SaleReturnDetail::METHOD_PRODUCT_REPAIR)
                                @if($item->new_serial_number)
                                    <div><small class="text-muted">Serial Baru:</small> <strong>{{ $item->new_serial_number }}</strong></div>
                                @endif
                                @if($item->location)
                                    <div><small class="text-muted">Lokasi:</small> <strong>{{ $item->location->name }}</strong></div>
                                @endif
                            @elseif($item->method === SaleReturnDetail::METHOD_UNPROCESSED)
                                @if($item->notes)
                                    <div class="text-muted fst-italic small">"{{ Str::limit($item->notes, 50) }}"</div>
                                @endif
                            @endif
                        </td>
                        <td class="text-end font-weight-bold">
                            {{ format_currency($item->getEffectiveNominal()) }}
                        </td>
                        <td class="text-center">
                            @include('salesreturn::partials.item-settlement-status', ['item' => $item])
                            @if($item->status === SaleReturnItemSettlement::STATUS_REJECTED && $item->rejection_reason)
                                <div class="small text-danger mt-1" title="{{ $item->rejection_reason }}">
                                    {{ Str::limit($item->rejection_reason, 30) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center d-print-none">
                            @if($item->status === SaleReturnItemSettlement::STATUS_SUBMITTED)
                                @can('saleReturnSettlements.approve')
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
                            @elseif($item->status === SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH)
                                @can('saleReturnSettlements.dispatch')
                                <button type="button" class="btn btn-sm btn-warning border-0" title="Proses Pengiriman" data-toggle="modal" data-target="#dispatchItemModal{{ $item->id }}" data-bs-toggle="modal" data-bs-target="#dispatchItemModal{{ $item->id }}">
                                    <i class="bi bi-truck"></i>
                                </button>
                                @else
                                <span class="text-muted small">-</span>
                                @endcan
                            @elseif($item->status === SaleReturnItemSettlement::STATUS_APPROVED && $item->method === SaleReturnDetail::METHOD_CASH_REFUND)
                                @can('saleReturnPayments.access')
                                <a href="{{ route('sale-return-payments.index', $sale_return->id) }}" class="btn btn-sm btn-outline-info border-0" title="Lihat Pembayaran">
                                    <i class="bi bi-eye-fill"></i>
                                </a>
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
