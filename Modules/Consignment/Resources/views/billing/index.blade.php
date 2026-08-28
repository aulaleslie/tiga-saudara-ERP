@extends('layouts.app')

@section('title', 'Tagihan Konsinyasi Siap Konversi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Tagihan Siap Konversi</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Tagihan Konsinyasi Supplier Siap Konversi</h4>
                        <small class="text-muted">Daftar konfirmasi alokasi yang telah disetujui (Phase 2) dan siap dikonversi menjadi Purchase/Hutang (Phase 3).</small>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Tanggal Approval</th>
                                <th>No. Konfirmasi</th>
                                <th>Supplier</th>
                                <th>Disetujui Oleh</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($readyConfirmations as $conf)
                                <tr>
                                    <td>{{ $conf->approved_at ? $conf->approved_at->format('d/m/Y H:i') : $conf->date->format('d/m/Y') }}</td>
                                    <td>
                                        <a href="{{ route('consignments.confirmations.show', $conf->id) }}" class="font-weight-bold">
                                            {{ $conf->confirmation_number }}
                                        </a>
                                    </td>
                                    <td>{{ $conf->supplier->supplier_name ?? '-' }}</td>
                                    <td>{{ $conf->approver->name ?? '-' }}</td>
                                    <td>
                                        @can('consignments.billing.convert')
                                            <a href="{{ route('consignments.billing.create', $conf->id) }}" class="btn btn-primary btn-sm">
                                                <i class="bi bi-receipt"></i> Proses Billing / Konversi
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Tidak ada konfirmasi alokasi yang siap untuk dikonversi menjadi tagihan/Purchase.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $readyConfirmations->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
