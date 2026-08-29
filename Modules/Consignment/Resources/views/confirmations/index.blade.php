@extends('layouts.app')

@section('title', 'Konfirmasi Alokasi Billing Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Konfirmasi Alokasi</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Konfirmasi Alokasi Penjualan Supplier</h4>
                        <small class="text-muted">Dokumen alokasi penjualan konsinyasi per supplier (Phase 2 - Financially Inert).</small>
                    </div>
                    <div>
                        @can('consignments.allocations.create')
                            <a href="{{ route('consignments.confirmations.create') }}" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg"></i> Buat Draft Konfirmasi
                            </a>
                        @endcan
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="{{ route('consignments.confirmations.index') }}" class="row mb-4">
                    <div class="col-md-4 mb-2">
                        @include('consignment::partials.ajax-select', [
                            'name' => 'supplier_id',
                            'url' => route('consignments.select.suppliers'),
                            'selectedId' => request('supplier_id'),
                            'selectedText' => $selectedSupplierText ?? null,
                            'placeholder' => '-- Semua Supplier --',
                        ])
                    </div>
                    <div class="col-md-4 mb-2">
                        <select name="status" class="form-control">
                            <option value="">-- Semua Status --</option>
                            <option value="DRAFT" {{ request('status') === 'DRAFT' ? 'selected' : '' }}>DRAFT</option>
                            <option value="WAITING_APPROVAL" {{ request('status') === 'WAITING_APPROVAL' ? 'selected' : '' }}>MENUNGGU PERSETUJUAN</option>
                            <option value="APPROVED" {{ request('status') === 'APPROVED' ? 'selected' : '' }}>APPROVED (SIAP BILLING)</option>
                            <option value="REJECTED" {{ request('status') === 'REJECTED' ? 'selected' : '' }}>REJECTED</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-2">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                        <a href="{{ route('consignments.confirmations.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>No. Konfirmasi</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Dibuat Oleh</th>
                                <th>Disetujui Oleh</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($confirmations as $conf)
                                <tr>
                                    <td>{{ $conf->date->format('d/m/Y') }}</td>
                                    <td>
                                        <a href="{{ route('consignments.confirmations.show', $conf->id) }}" class="font-weight-bold">
                                            {{ $conf->confirmation_number }}
                                        </a>
                                    </td>
                                    <td>{{ $conf->supplier->supplier_name ?? '-' }}</td>
                                    <td>
                                        @if($conf->isDraft())
                                            <span class="badge badge-secondary">DRAFT</span>
                                        @elseif($conf->isWaitingApproval())
                                            <span class="badge badge-warning">WAITING APPROVAL</span>
                                        @elseif($conf->isApproved())
                                            <span class="badge badge-success">APPROVED</span>
                                        @elseif($conf->isRejected())
                                            <span class="badge badge-danger">REJECTED</span>
                                        @endif
                                    </td>
                                    <td>{{ $conf->creator->name ?? '-' }}</td>
                                    <td>{{ $conf->approver->name ?? '-' }}</td>
                                    <td>
                                        <a href="{{ route('consignments.confirmations.show', $conf->id) }}" class="btn btn-info btn-sm">
                                            <i class="bi bi-eye"></i> Detail
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Tidak ada data konfirmasi alokasi.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $confirmations->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
@endpush
