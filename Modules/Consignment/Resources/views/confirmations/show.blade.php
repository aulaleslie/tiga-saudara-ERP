@extends('layouts.app')

@section('title', 'Detail Konfirmasi Alokasi ' . $confirmation->confirmation_number)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.confirmations.index') }}">Konfirmasi Alokasi</a></li>
        <li class="breadcrumb-item active">{{ $confirmation->confirmation_number }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <!-- Financially Inert Phase 2 Info Banner -->
        <div class="alert alert-info border-left-info shadow-sm mb-4">
            <i class="bi bi-info-circle-fill mr-2"></i>
            <strong>Informasi Phase 2 (Financially Inert):</strong> Persetujuan konfirmasi ini mengunci alokasi barang terjual ke penerimaan supplier (siap billing Phase 3). Proses ini <strong>TIDAK membuat Hutang, Pembelian (Purchase), atau Mutasi Kas/Stok</strong>.
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0">Konfirmasi Alokasi #{{ $confirmation->confirmation_number }}</h4>
                        <small class="text-muted">Tanggal: {{ $confirmation->date->format('d/m/Y') }} | Supplier: {{ $confirmation->supplier->supplier_name }}</small>
                    </div>
                    <div>
                        @if($confirmation->isBilled())
                            <span class="badge badge-primary p-2">BILLED (TERTAGIH)</span>
                        @elseif($confirmation->isApproved())
                            <span class="badge badge-success p-2">APPROVED (SIAP BILLING)</span>
                        @elseif($confirmation->isWaitingApproval())
                            <span class="badge badge-warning p-2">WAITING APPROVAL</span>
                        @elseif($confirmation->isDraft())
                            <span class="badge badge-secondary p-2">DRAFT</span>
                        @elseif($confirmation->isRejected())
                            <span class="badge badge-danger p-2">REJECTED</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-3">
                        <small class="text-muted d-block">Dibuat Oleh:</small>
                        <strong>{{ $confirmation->creator->name ?? '-' }}</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Diajukan Oleh:</small>
                        <strong>{{ $confirmation->submitter->name ?? '-' }}</strong>
                        @if($confirmation->submitted_at)
                            <small class="d-block text-muted">{{ $confirmation->submitted_at->format('d/m/Y H:i') }}</small>
                        @endif
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Disetujui Oleh:</small>
                        <strong>{{ $confirmation->approver->name ?? '-' }}</strong>
                        @if($confirmation->approved_at)
                            <small class="d-block text-muted">{{ $confirmation->approved_at->format('d/m/Y H:i') }}</small>
                        @endif
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Catatan:</small>
                        <span>{{ $confirmation->notes ?? '-' }}</span>
                    </div>
                </div>

                @if($confirmation->isBilled())
                    <div class="alert alert-success border-left-success shadow-sm mb-4">
                        <h6 class="font-weight-bold mb-1"><i class="bi bi-receipt mr-1"></i> Informasi Tagihan & Purchase Terbuat</h6>
                        <div>No. Faktur Supplier: <strong>{{ $confirmation->supplier_invoice_number }}</strong> (Tgl Faktur: {{ $confirmation->invoice_date?->format('d/m/Y') }})</div>
                        <div>Dikonversi oleh: <strong>{{ $confirmation->biller->name ?? '-' }}</strong> pada {{ $confirmation->billed_at?->format('d/m/Y H:i') }}</div>
                        @if($confirmation->purchase)
                            <div class="mt-2">
                                <a href="{{ route('purchases.show', $confirmation->purchase->id) }}" class="btn btn-outline-success btn-sm font-weight-bold">
                                    <i class="bi bi-box-arrow-up-right"></i> Lihat Purchase #{{ $confirmation->purchase->reference }} (Rp {{ number_format($confirmation->purchase->total_amount, 2, ',', '.') }})
                                </a>
                            </div>
                        @endif
                    </div>
                @endif

                @if($confirmation->isRejected() && $confirmation->rejection_reason)
                    <div class="alert alert-danger mb-4">
                        <strong>Alasan Penolakan:</strong> {{ $confirmation->rejection_reason }}
                    </div>
                @endif

                <!-- Actions -->
                <div class="mb-4">
                    @if($confirmation->canEdit())
                        @can('consignments.allocations.submit')
                            <form method="POST" action="{{ route('consignments.confirmations.submit', $confirmation->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-send"></i> Ajukan Konfirmasi (Reservasi Capacity)
                                </button>
                            </form>
                        @endcan

                        @can('consignments.allocations.edit')
                            <a href="{{ route('consignments.confirmations.edit', $confirmation->id) }}" class="btn btn-primary">
                                <i class="bi bi-pencil"></i> Ubah Draft
                            </a>
                        @endcan

                        @can('consignments.allocations.edit')
                            <form method="POST" action="{{ route('consignments.confirmations.destroy', $confirmation->id) }}" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus draft ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-trash"></i> Hapus Draft
                                </button>
                            </form>
                        @endcan
                    @endif

                    @if($confirmation->isWaitingApproval())
                        @can('consignments.allocations.approve')
                            <form method="POST" action="{{ route('consignments.confirmations.approve', $confirmation->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Setujui Alokasi
                                </button>
                            </form>
                        @endcan

                        @can('consignments.allocations.reject')
                            <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#rejectModal">
                                <i class="bi bi-x-circle"></i> Tolak Alokasi
                            </button>
                        @endcan
                    @endif

                    @if($confirmation->isApproved() && $confirmation->is_ready_for_billing && !$confirmation->isBilled())
                        @can('consignments.billing.convert')
                            <a href="{{ route('consignments.billing.create', $confirmation->id) }}" class="btn btn-success font-weight-bold">
                                <i class="bi bi-receipt"></i> Konversi Ke Tagihan Supplier (Purchase)
                            </a>
                        @endcan
                    @endif
                </div>

                <h5>Rincian Baris Alokasi</h5>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Sumber Penjualan</th>
                                <th>Lokasi</th>
                                <th>Produk</th>
                                <th>Alokasi Base Qty</th>
                                <th>Alokasi Penerimaan Supplier</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($confirmation->lines as $line)
                                <tr>
                                    <td>{{ $line->soldSource->sale->reference ?? "Dispatch #{$line->consignment_sold_source_id}" }}</td>
                                    <td>{{ $line->location->name ?? '-' }}</td>
                                    <td>{{ $line->product->product_name ?? '-' }}</td>
                                    <td class="font-weight-bold">{{ number_format($line->allocated_base_quantity, 3) }}</td>
                                    <td>
                                        @foreach($line->receiptAllocations as $ra)
                                            <div class="small">
                                                Receiving #{{ $ra->receiving_reference ?? $ra->consignment_receiving_detail_id }}:
                                                <strong>{{ number_format($ra->allocated_base_quantity, 3) }}</strong> PCS
                                                (Biaya DPP: Rp {{ number_format($ra->unit_dpp, 2, ',', '.') }})
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <h5>Audit Trail / Riwayat</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Waktu</th>
                                <th>Aksi</th>
                                <th>Aktor</th>
                                <th>Keterangan / Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($confirmation->auditLogs as $log)
                                <tr>
                                    <td>{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                                    <td><span class="badge badge-info">{{ $log->action }}</span></td>
                                    <td>{{ $log->actor->name ?? '-' }}</td>
                                    <td>{{ $log->reason ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-2">Belum ada riwayat audit.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="POST" action="{{ route('consignments.confirmations.reject', $confirmation->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Tolak Konfirmasi Alokasi</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Alasan Penolakan <span class="text-danger">*</span></label>
                            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Masukkan alasan penolakan..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Tolak & Lepas Reservasi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
