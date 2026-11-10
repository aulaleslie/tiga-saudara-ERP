@extends('layouts.app')

@section('title', 'Pembelian Putus Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Pembelian Putus</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Pembelian Putus Barang Konsinyasi</h4>
                        <small class="text-muted">Beli barang konsinyasi yang belum terjual (dan/atau yang sudah terjual tetapi belum ditagih) dalam satu faktur.</small>
                    </div>
                    <div>
                        <a href="{{ route('consignments.buyouts.create') }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-clipboard-check"></i> Persiapan Pembelian Putus
                        </a>
                    </div>
                </div>

                @unless($mutationsEnabled)
                    <div class="alert alert-info py-2">Fitur pembuatan dan persetujuan pembelian putus belum diaktifkan. Halaman ini hanya menampilkan data.</div>
                @endunless

                <form method="GET" action="{{ route('consignments.buyouts.index') }}" class="row mb-4">
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
                            @foreach(['DRAFT' => 'DRAFT', 'WAITING_APPROVAL' => 'MENUNGGU PERSETUJUAN', 'APPROVED' => 'DISETUJUI (DICADANGKAN)', 'REJECTED' => 'DITOLAK', 'CANCELLED' => 'DIBATALKAN', 'FINALIZED' => 'FINAL', 'REVERSED' => 'DI-REVERSAL'] as $value => $label)
                                <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-2">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                        <a href="{{ route('consignments.buyouts.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Tanggal</th><th>No. Dokumen</th><th>Supplier</th><th>Status</th><th>Faktur Pembelian</th>
                                <th class="text-right">Total</th><th class="text-right">Sisa Tagihan</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($buyouts as $b)
                                <tr>
                                    <td>{{ $b->date->format('d/m/Y') }}</td>
                                    <td><a class="font-weight-bold" href="{{ route('consignments.buyouts.show', $b->id) }}">{{ $b->reference }}</a></td>
                                    <td>{{ $b->supplier->supplier_name ?? '-' }}</td>
                                    <td>@include('consignment::buyouts.partials.status-badge', ['status' => $b->status])</td>
                                    <td>{{ $b->purchase->reference ?? '-' }}</td>
                                    <td class="text-right">{{ $b->purchase ? number_format((float) $b->purchase->total_amount, 2, ',', '.') : '-' }}</td>
                                    <td class="text-right">{{ $b->purchase ? number_format($b->purchase->isCancelled() ? 0 : (float) $b->purchase->due_amount, 2, ',', '.') : '-' }}</td>
                                    <td><a href="{{ route('consignments.buyouts.show', $b->id) }}" class="btn btn-info btn-sm"><i class="bi bi-eye"></i> Detail</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada dokumen pembelian putus.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $buyouts->links() }}</div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    @include('consignment::partials.ajax-select-scripts')
@endpush
