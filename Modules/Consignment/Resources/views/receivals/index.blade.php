@extends('layouts.app')

@section('title', 'Dokumen Penerimaan Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Dokumen Penerimaan</li>
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
                                <h4 class="card-title mb-0">Dokumen Penerimaan Konsinyasi</h4>
                                <small class="text-muted">Kelola dokumen titipan supplier sebelum dicatat fisik penerimaannya.</small>
                            </div>
                            @can('consignments.create')
                                <a href="{{ route('consignments.receivals.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> Buat Dokumen Baru
                                </a>
                            @endcan
                        </div>

                        <!-- Filters -->
                        <form method="GET" action="{{ route('consignments.receivals.index') }}" class="row mb-3">
                            <div class="col-md-3">
                                <select name="status" class="form-control">
                                    <option value="">-- Semua Status --</option>
                                    <option value="DRAFT" {{ request('status') === 'DRAFT' ? 'selected' : '' }}>DRAFT</option>
                                    <option value="WAITING_APPROVAL" {{ request('status') === 'WAITING_APPROVAL' ? 'selected' : '' }}>MENUNGGU PERSETUJUAN</option>
                                    <option value="APPROVED" {{ request('status') === 'APPROVED' ? 'selected' : '' }}>DISETUJUI (APPROVED)</option>
                                    <option value="REJECTED" {{ request('status') === 'REJECTED' ? 'selected' : '' }}>DITOLAK</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="supplier_id" class="form-control">
                                    <option value="">-- Semua Supplier --</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" {{ request('supplier_id') == $supplier->id ? 'selected' : '' }}>
                                            {{ $supplier->supplier_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                                <a href="{{ route('consignments.receivals.index') }}" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>No. Ref</th>
                                        <th>Tanggal</th>
                                        <th>Supplier</th>
                                        <th>Ref Surat Jalan</th>
                                        <th>Total Item</th>
                                        <th>Estimasi Nilai</th>
                                        <th>Status</th>
                                        <th>Penerimaan Fisik</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($receivals as $receival)
                                        <tr>
                                            <td class="font-weight-bold">
                                                <a href="{{ route('consignments.receivals.show', $receival->id) }}">
                                                    {{ $receival->reference }}
                                                </a>
                                            </td>
                                            <td>{{ $receival->date->format('d/m/Y') }}</td>
                                            <td>{{ $receival->supplier->supplier_name }}</td>
                                            <td>{{ $receival->supplier_delivery_reference ?? '-' }}</td>
                                            <td>{{ $receival->lines->count() }} item ({{ $receival->lines->sum('quantity') }} unit)</td>
                                            <td>Rp {{ number_format($receival->lines->sum('total_cost'), 2, ',', '.') }}</td>
                                            <td>
                                                @if($receival->status === 'DRAFT')
                                                    <span class="badge badge-secondary">DRAFT</span>
                                                @elseif($receival->status === 'WAITING_APPROVAL')
                                                    <span class="badge badge-warning text-dark">MENUNGGU PERSETUJUAN</span>
                                                @elseif($receival->status === 'APPROVED')
                                                    <span class="badge badge-success">APPROVED</span>
                                                @elseif($receival->status === 'REJECTED')
                                                    <span class="badge badge-danger">REJECTED</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($receival->activeReceiving)
                                                    @if($receival->activeReceiving->status === 'APPROVED')
                                                        <span class="badge badge-success"><i class="bi bi-check-all"></i> Diterima</span>
                                                    @elseif($receival->activeReceiving->status === 'PENDING')
                                                        <span class="badge badge-info"><i class="bi bi-hourglass-split"></i> Pending Fisik</span>
                                                    @endif
                                                @elseif($receival->isApproved())
                                                    <span class="badge badge-light text-muted">Belum Dicatat</span>
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <a href="{{ route('consignments.receivals.show', $receival->id) }}" class="btn btn-sm btn-info" title="Lihat">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                @if($receival->canBeEdited())
                                                    @can('consignments.edit')
                                                        <a href="{{ route('consignments.receivals.edit', $receival->id) }}" class="btn btn-sm btn-warning" title="Ubah">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                    @endcan
                                                @endif
                                                @if($receival->isApproved() && !$receival->activeReceiving)
                                                    @can('consignments.receive')
                                                        <a href="{{ route('consignments.receivings.create', $receival->id) }}" class="btn btn-sm btn-success" title="Catat Penerimaan Fisik">
                                                            <i class="bi bi-box-arrow-in-down"></i> Terima
                                                        </a>
                                                    @endcan
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">Belum ada data dokumen penerimaan konsinyasi.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $receivals->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
