@extends('layouts.app')

@section('title', 'Rekonsiliasi Titipan Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Rekonsiliasi Titipan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Rekonsiliasi Fisik Titipan Konsinyasi</h4>
                        <small class="text-muted">Laporan audit penerimaan dan pembatalan fisik barang titipan supplier.</small>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="{{ route('consignments.reconciliation.index') }}" class="row mb-4">
                    <div class="col-md-3 mb-2">
                        <select name="supplier_id" class="form-control">
                            <option value="">-- Semua Supplier --</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" {{ request('supplier_id') == $s->id ? 'selected' : '' }}>
                                    {{ $s->supplier_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="location_id" class="form-control">
                            <option value="">-- Semua Lokasi Konsinyasi --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="status" class="form-control">
                            <option value="">-- Status Fisik (Default: Approved & Reversed) --</option>
                            <option value="APPROVED" {{ request('status') === 'APPROVED' ? 'selected' : '' }}>Hanya APPROVED (Aktif)</option>
                            <option value="REVERSED" {{ request('status') === 'REVERSED' ? 'selected' : '' }}>Hanya REVERSED (Dibatalkan)</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                        <a href="{{ route('consignments.reconciliation.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>No. Penerimaan</th>
                                <th>Supplier</th>
                                <th>Lokasi</th>
                                <th>Produk</th>
                                <th>Jumlah</th>
                                <th>Biaya DPP</th>
                                <th>Total Nilai</th>
                                <th>Status</th>
                                <th>Nomor Seri</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($details as $d)
                                <tr>
                                    <td>{{ $d->consignmentReceiving->date->format('d/m/Y') }}</td>
                                    <td>
                                        <a href="{{ route('consignments.receivings.show', $d->consignment_receiving_id) }}">
                                            {{ $d->consignmentReceiving->receiving_number }}
                                        </a>
                                    </td>
                                    <td>{{ $d->consignmentReceiving->receival->supplier->supplier_name ?? '-' }}</td>
                                    <td>{{ $d->consignmentReceiving->location->name ?? '-' }}</td>
                                    <td>
                                        <div class="font-weight-bold">{{ $d->product->product_name }}</div>
                                        <small class="text-muted">{{ $d->product->product_code }}</small>
                                    </td>
                                    <td class="font-weight-bold">{{ $d->quantity_received }} {{ $d->product->baseUnit->short_name ?? 'PCS' }}</td>
                                    <td>Rp {{ number_format($d->unit_dpp, 2, ',', '.') }}</td>
                                    <td>Rp {{ number_format($d->quantity_received * $d->unit_dpp, 2, ',', '.') }}</td>
                                    <td>
                                        @if($d->consignmentReceiving->status === 'APPROVED')
                                            <span class="badge badge-success">APPROVED</span>
                                        @elseif($d->consignmentReceiving->status === 'REVERSED')
                                            <span class="badge badge-secondary">REVERSED</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($d->serialNumbers->count() > 0)
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach($d->serialNumbers as $sn)
                                                    <span class="badge badge-info mr-1">{{ $sn->serial_number }}</span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">Tidak ada data rekonsiliasi konsinyasi.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $details->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
