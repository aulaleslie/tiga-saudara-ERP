@extends('layouts.app')

@section('title', 'Daftar Penerimaan Fisik Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Penerimaan Fisik</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h4 class="card-title mb-0">Penerimaan Fisik Konsinyasi</h4>
                                <small class="text-muted">Daftar pencatatan fisik barang titipan ke lokasi konsinyasi.</small>
                            </div>
                        </div>

                        <!-- Filters -->
                        <form method="GET" action="{{ route('consignments.receivings.index') }}" class="row mb-3">
                            <div class="col-md-3">
                                <select name="status" class="form-control">
                                    <option value="">-- Semua Status --</option>
                                    <option value="PENDING" {{ request('status') === 'PENDING' ? 'selected' : '' }}>PENDING</option>
                                    <option value="APPROVED" {{ request('status') === 'APPROVED' ? 'selected' : '' }}>APPROVED</option>
                                    <option value="REJECTED" {{ request('status') === 'REJECTED' ? 'selected' : '' }}>REJECTED</option>
                                    <option value="REVERSED" {{ request('status') === 'REVERSED' ? 'selected' : '' }}>REVERSED</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="location_id" class="form-control">
                                    <option value="">-- Semua Lokasi Konsinyasi --</option>
                                    @foreach($locations as $loc)
                                        <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                            {{ $loc->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                                <a href="{{ route('consignments.receivings.index') }}" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>No. Penerimaan</th>
                                        <th>Dokumen Ref</th>
                                        <th>Tanggal</th>
                                        <th>Supplier</th>
                                        <th>Lokasi</th>
                                        <th>Status</th>
                                        <th>Pencatat</th>
                                        <th>Penyetujui</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($receivings as $receiving)
                                        <tr>
                                            <td class="font-weight-bold">
                                                <a href="{{ route('consignments.receivings.show', $receiving->id) }}">
                                                    {{ $receiving->receiving_number }}
                                                </a>
                                            </td>
                                            <td>
                                                <a href="{{ route('consignments.receivals.show', $receiving->consignment_receival_id) }}">
                                                    {{ $receiving->receival->reference }}
                                                </a>
                                            </td>
                                            <td>{{ $receiving->date->format('d/m/Y') }}</td>
                                            <td>{{ $receiving->receival->supplier->supplier_name ?? '-' }}</td>
                                            <td><span class="badge badge-warning text-dark"><i class="bi bi-box-seam"></i> {{ $receiving->location->name ?? '-' }}</span></td>
                                            <td>
                                                @if($receiving->status === 'PENDING')
                                                    <span class="badge badge-warning text-dark">PENDING</span>
                                                @elseif($receiving->status === 'APPROVED')
                                                    <span class="badge badge-success">APPROVED</span>
                                                @elseif($receiving->status === 'REJECTED')
                                                    <span class="badge badge-danger">REJECTED</span>
                                                @elseif($receiving->status === 'REVERSED')
                                                    <span class="badge badge-secondary">REVERSED</span>
                                                @endif
                                            </td>
                                            <td>{{ $receiving->receiver->name ?? '-' }}</td>
                                            <td>{{ $receiving->approver->name ?? '-' }}</td>
                                            <td class="text-center">
                                                <a href="{{ route('consignments.receivings.show', $receiving->id) }}" class="btn btn-sm btn-info" title="Lihat">
                                                    <i class="bi bi-eye"></i> Detail
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">Belum ada data penerimaan fisik konsinyasi.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $receivings->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
