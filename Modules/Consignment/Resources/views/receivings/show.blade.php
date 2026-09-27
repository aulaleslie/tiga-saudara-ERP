@extends('layouts.app')

@section('title', 'Detail Penerimaan Fisik Konsinyasi - ' . $receiving->receiving_number)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.index') }}">Konsinyasi</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivings.index') }}">Penerimaan Fisik</a></li>
        <li class="breadcrumb-item active">{{ $receiving->receiving_number }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <div>
                    <h5 class="mb-0 font-weight-bold">Penerimaan Fisik: {{ $receiving->receiving_number }}</h5>
                    <small class="text-muted">
                        Ref Dokumen: <a href="{{ route('consignments.receivals.show', $receiving->consignment_receival_id) }}">{{ $receiving->receival->reference }}</a>
                        | Supplier: {{ $receiving->receival->supplier->supplier_name }}
                    </small>
                </div>
                <div>
                    @if($receiving->status === 'PENDING')
                        <span class="badge badge-warning text-dark p-2">PENDING PERSETUJUAN FISIK</span>
                    @elseif($receiving->status === 'APPROVED')
                        <span class="badge badge-success p-2">DISETUJUI (APPROVED)</span>
                    @elseif($receiving->status === 'REJECTED')
                        <span class="badge badge-danger p-2">DITOLAK (REJECTED)</span>
                    @elseif($receiving->status === 'REVERSED')
                        <span class="badge badge-secondary p-2">DIBATALKAN (REVERSED)</span>
                    @endif
                </div>
            </div>

            <div class="card-body">
                <!-- Info Grid -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <small class="text-muted d-block">Lokasi Penerimaan Konsinyasi:</small>
                        <strong class="text-primary font-weight-bold"><i class="bi bi-box-seam"></i> {{ $receiving->location->name }}</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Tanggal Fisik Diterima:</small>
                        <strong>{{ $receiving->date->format('d/m/Y') }}</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Dicatat Oleh:</small>
                        <div>{{ $receiving->receiver->name ?? '-' }} ({{ $receiving->received_at?->format('d/m/Y H:i') }})</div>
                    </div>
                    <div class="col-md-3">
                        @if($receiving->approved_at)
                            <small class="text-muted d-block">Disetujui Oleh:</small>
                            <div class="text-success font-weight-bold">{{ $receiving->approver->name ?? '-' }} ({{ $receiving->approved_at->format('d/m/Y H:i') }})</div>
                        @endif
                        @if($receiving->rejected_at)
                            <small class="text-muted d-block">Ditolak Oleh:</small>
                            <div class="text-danger font-weight-bold">{{ $receiving->rejecter->name ?? '-' }} ({{ $receiving->rejected_at->format('d/m/Y H:i') }})</div>
                            <div class="small text-danger">Alasan: {{ $receiving->rejection_reason }}</div>
                        @endif
                        @if($receiving->reversed_at)
                            <small class="text-muted d-block">Dibatalkan (Reversed) Oleh:</small>
                            <div class="text-secondary font-weight-bold">{{ $receiving->reverser->name ?? '-' }} ({{ $receiving->reversed_at->format('d/m/Y H:i') }})</div>
                            <div class="small text-muted">Alasan: {{ $receiving->reversal_reason }}</div>
                        @endif
                    </div>
                </div>

                <!-- Product Details Table -->
                <h6 class="font-weight-bold mb-3">Detail Barang Diterima:</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>No.</th>
                                <th>Produk</th>
                                <th>Jumlah Diterima</th>
                                <th>Biaya Satuan DPP</th>
                                <th>Pajak</th>
                                <th>Nomor Seri</th>
                                @if($receiving->isApproved() || $receiving->isReversed())
                                    <th>Stok Sebelum/Sesudah</th>
                                    <th>HPP Setting Sebelum/Sesudah</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($receiving->details as $idx => $detail)
                                <tr>
                                    <td>{{ $idx + 1 }}</td>
                                    <td>
                                        <div class="font-weight-bold">{{ $detail->product->product_name }}</div>
                                        <small class="text-muted">{{ $detail->product->product_code }}</small>
                                    </td>
                                    <td class="font-weight-bold">{{ $detail->quantity_received }}</td>
                                    <td>Rp {{ number_format($detail->unit_dpp, 2, ',', '.') }}</td>
                                    <td>
                                        @if($detail->tax_id)
                                            Rp {{ number_format($detail->tax_amount, 2, ',', '.') }} ({{ $detail->tax_rate }}%)
                                        @else
                                            <span class="text-muted">Non-PKP</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($detail->receivalLine->is_serialized)
                                            @if($detail->serialNumbers->count() > 0)
                                                <div class="d-flex flex-wrap gap-1">
                                                    @foreach($detail->serialNumbers as $sn)
                                                        <span class="badge badge-info mr-1">{{ $sn->serial_number }}</span>
                                                    @endforeach
                                                </div>
                                            @elseif(!empty($detail->pending_serial_numbers))
                                                <div class="d-flex flex-wrap gap-1">
                                                    @foreach($detail->pending_serial_numbers as $snStr)
                                                        <span class="badge badge-warning text-dark mr-1">{{ $snStr }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    @if($receiving->isApproved() || $receiving->isReversed())
                                        <td>
                                            <small class="d-block">Lokasi: {{ $detail->stock_before }} &rarr; <strong class="text-success">{{ $detail->stock_after }}</strong></small>
                                            <small class="d-block text-muted">Total Bisnis: {{ $detail->setting_quantity_before }} &rarr; {{ $detail->setting_quantity_after }}</small>
                                        </td>
                                        <td>
                                            <small class="d-block">Rp {{ number_format($detail->setting_avg_cost_before, 2, ',', '.') }} &rarr; <strong class="text-primary">Rp {{ number_format($detail->setting_avg_cost_after, 2, ',', '.') }}</strong></small>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Reversal Eligibility Warnings if applicable -->
                @if($receiving->isApproved() && isset($reversalPreview))
                    @if(!$reversalPreview['can_reverse'])
                        <div class="alert alert-secondary">
                            <h6 class="font-weight-bold mb-1"><i class="bi bi-shield-lock"></i> Catatan Reversal:</h6>
                            <ul class="mb-0 small">
                                @foreach($reversalPreview['blockers'] as $b)
                                    <li>{{ $b }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif

                <!-- Actions -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <a href="{{ route('consignments.receivings.index') }}" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali
                    </a>

                    <div class="d-flex gap-2">
                        @if($receiving->status === 'PENDING')
                            @can('consignments.receive.approve')
                                <form action="{{ route('consignments.receivings.approve', $receiving->id) }}" method="POST" class="d-inline mr-2">
                                    @csrf
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-all"></i> Setujui & Tambahkan ke Stok
                                    </button>
                                </form>
                            @endcan

                            @can('consignments.receive.reject')
                                <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#rejectReceivingModal">
                                    <i class="bi bi-x-circle"></i> Tolak Penerimaan Fisik
                                </button>
                            @endcan
                        @endif

                        @if($receiving->status === 'APPROVED' && isset($reversalPreview) && $reversalPreview['can_reverse'])
                            @can('consignments.reverse')
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#reverseModal">
                                    <i class="bi bi-arrow-counterclockwise"></i> Batalkan Penerimaan (Reversal)
                                </button>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    @if($receiving->status === 'PENDING')
        <div class="modal fade" id="rejectReceivingModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form action="{{ route('consignments.receivings.reject', $receiving->id) }}" method="POST">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title font-weight-bold">Tolak Penerimaan Fisik</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="rejection_reason" class="font-weight-bold">Alasan Penolakan Fisik <span class="text-danger">*</span></label>
                                <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required placeholder="Jelaskan kondisi barang rusak/tidak sesuai..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Konfirmasi Tolak</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Reverse Modal -->
    @if($receiving->status === 'APPROVED')
        <div class="modal fade" id="reverseModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form action="{{ route('consignments.receivings.reverse', $receiving->id) }}" method="POST">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title font-weight-bold">Konfirmasi Reversal Penerimaan Konsinyasi</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="text-danger font-weight-bold">
                                PERHATIAN: Tindakan ini akan memulihkan kembali saldo stok fisik, status nomor seri, dan HPP rata-rata ke kondisi sebelum penerimaan ini.
                            </p>
                            <div class="form-group">
                                <label for="reversal_reason" class="font-weight-bold">Alasan Pembatalan / Reversal <span class="text-danger">*</span></label>
                                <textarea name="reversal_reason" id="reversal_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan pembatalan penerimaan..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Lakukan Reversal</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
