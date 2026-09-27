@extends('layouts.app')

@section('title', 'Detail Dokumen Penerimaan Konsinyasi - ' . $receival->reference)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.receivals.index') }}">Konsinyasi</a></li>
        <li class="breadcrumb-item active">{{ $receival->reference }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <div>
                    <h5 class="mb-0 font-weight-bold">Dokumen Konsinyasi: {{ $receival->reference }}</h5>
                    <small class="text-muted">Tanggal: {{ $receival->date->format('d/m/Y') }} | Supplier: {{ $receival->supplier->supplier_name }}</small>
                </div>
                <div>
                    @if($receival->status === 'DRAFT')
                        <span class="badge badge-secondary p-2">DRAFT</span>
                    @elseif($receival->status === 'WAITING_APPROVAL')
                        <span class="badge badge-warning text-dark p-2">MENUNGGU PERSETUJUAN</span>
                    @elseif($receival->status === 'APPROVED')
                        <span class="badge badge-success p-2">DISETUJUI (APPROVED)</span>
                    @elseif($receival->status === 'REJECTED')
                        <span class="badge badge-danger p-2">DITOLAK (REJECTED)</span>
                    @endif
                </div>
            </div>

            <div class="card-body">
                <!-- Info Grid -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <small class="text-muted d-block">Supplier:</small>
                        <strong>{{ $receival->supplier->supplier_name }}</strong>
                        <div>{{ $receival->supplier->supplier_phone ?? '-' }}</div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">No. Ref Surat Jalan Supplier:</small>
                        <strong>{{ $receival->supplier_delivery_reference ?? '-' }}</strong>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Dibuat Oleh:</small>
                        <div>{{ $receival->creator->name ?? '-' }} ({{ $receival->created_at->format('d/m/Y H:i') }})</div>
                        @if($receival->submitted_at)
                            <small class="text-muted d-block mt-1">Diajukan Oleh:</small>
                            <div>{{ $receival->submitter->name ?? '-' }} ({{ $receival->submitted_at->format('d/m/Y H:i') }})</div>
                        @endif
                    </div>
                    <div class="col-md-3">
                        @if($receival->approved_at)
                            <small class="text-muted d-block">Disetujui Oleh:</small>
                            <div class="text-success font-weight-bold">{{ $receival->approver->name ?? '-' }} ({{ $receival->approved_at->format('d/m/Y H:i') }})</div>
                        @endif
                        @if($receival->rejected_at)
                            <small class="text-muted d-block">Ditolak Oleh:</small>
                            <div class="text-danger font-weight-bold">{{ $receival->rejecter->name ?? '-' }} ({{ $receival->rejected_at->format('d/m/Y H:i') }})</div>
                            <div class="small text-danger">Alasan: {{ $receival->rejection_reason }}</div>
                        @endif
                    </div>
                </div>

                @if($receival->note)
                    <div class="alert alert-light border">
                        <strong>Catatan:</strong> {{ $receival->note }}
                    </div>
                @endif

                <!-- Product Lines -->
                <h6 class="font-weight-bold mb-3">Rincian Produk Titipan:</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-striped mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>No.</th>
                                <th>Produk</th>
                                <th>Kode</th>
                                <th>Jumlah</th>
                                <th>Satuan</th>
                                <th>Biaya Satuan DPP (Rp)</th>
                                <th>Pajak</th>
                                <th>Subtotal (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($receival->lines as $idx => $line)
                                <tr>
                                    <td>{{ $idx + 1 }}</td>
                                    <td class="font-weight-bold">
                                        {{ $line->product_name }}
                                        @if($line->is_serialized)
                                            <span class="badge badge-info ml-1">Serial Number</span>
                                        @endif
                                    </td>
                                    <td>{{ $line->product_code }}</td>
                                    <td>{{ $line->quantity }}</td>
                                    <td>{{ $line->unit_code }}</td>
                                    <td>Rp {{ number_format($line->unit_dpp, 2, ',', '.') }}</td>
                                    <td>
                                        @if($line->tax_name)
                                            {{ $line->tax_name }} ({{ $line->tax_rate }}%)
                                        @else
                                            <span class="text-muted">Non-PKP / Bebas Pajak</span>
                                        @endif
                                    </td>
                                    <td class="font-weight-bold">Rp {{ number_format($line->total_cost, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="7" class="text-right">Grand Total Nilai Titipan:</th>
                                <th class="font-weight-bold">Rp {{ number_format($receival->lines->sum('total_cost'), 2, ',', '.') }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Receivings History Section -->
                @if($receival->receivings->count() > 0)
                    <h6 class="font-weight-bold mb-3">Riwayat Penerimaan Fisik:</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>No. Penerimaan</th>
                                    <th>Tanggal</th>
                                    <th>Lokasi Konsinyasi</th>
                                    <th>Status Fisik</th>
                                    <th>Pencatat</th>
                                    <th>Penyetujui</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($receival->receivings as $rcv)
                                    <tr>
                                        <td class="font-weight-bold">{{ $rcv->receiving_number }}</td>
                                        <td>{{ $rcv->date->format('d/m/Y') }}</td>
                                        <td>{{ $rcv->location->name ?? '-' }}</td>
                                        <td>
                                            @if($rcv->status === 'PENDING')
                                                <span class="badge badge-warning text-dark">PENDING</span>
                                            @elseif($rcv->status === 'APPROVED')
                                                <span class="badge badge-success">APPROVED</span>
                                            @elseif($rcv->status === 'REJECTED')
                                                <span class="badge badge-danger">REJECTED</span>
                                            @elseif($rcv->status === 'REVERSED')
                                                <span class="badge badge-secondary">REVERSED</span>
                                            @endif
                                        </td>
                                        <td>{{ $rcv->receiver->name ?? '-' }}</td>
                                        <td>{{ $rcv->approver->name ?? '-' }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('consignments.receivings.show', $rcv->id) }}" class="btn btn-sm btn-outline-info">
                                                Lihat Fisik
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <!-- Actions / Buttons -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <a href="{{ route('consignments.receivals.index') }}" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali ke Daftar
                    </a>

                    <div class="d-flex gap-2">
                        @if($receival->status === 'DRAFT' || $receival->status === 'REJECTED')
                            @can('consignments.edit')
                                <a href="{{ route('consignments.receivals.edit', $receival->id) }}" class="btn btn-warning mr-2">
                                    <i class="bi bi-pencil"></i> Ubah Draf
                                </a>
                            @endcan

                            @can('consignments.submit')
                                <form action="{{ route('consignments.receivals.submit', $receival->id) }}" method="POST" class="d-inline mr-2">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-send"></i> Ajukan Persetujuan
                                    </button>
                                </form>
                            @endcan
                        @endif

                        @if($receival->status === 'WAITING_APPROVAL')
                            @can('consignments.approve')
                                <form action="{{ route('consignments.receivals.approve', $receival->id) }}" method="POST" class="d-inline mr-2">
                                    @csrf
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle"></i> Setujui Dokumen
                                    </button>
                                </form>
                            @endcan

                            @can('consignments.reject')
                                <button type="button" class="btn btn-danger mr-2" data-toggle="modal" data-target="#rejectModal">
                                    <i class="bi bi-x-circle"></i> Tolak Dokumen
                                </button>
                            @endcan
                        @endif

                        @if($receival->isApproved() && !$receival->activeReceiving)
                            @can('consignments.receive')
                                <a href="{{ route('consignments.receivings.create', $receival->id) }}" class="btn btn-success">
                                    <i class="bi bi-box-arrow-in-down"></i> Catat Penerimaan Fisik
                                </a>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    @if($receival->status === 'WAITING_APPROVAL')
        <div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <form action="{{ route('consignments.receivals.reject', $receival->id) }}" method="POST">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title font-weight-bold">Tolak Dokumen Konsinyasi</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="rejection_reason" class="font-weight-bold">Alasan Penolakan <span class="text-danger">*</span></label>
                                <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan dokumen ditolak..."></textarea>
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
@endsection
