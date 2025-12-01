@extends('layouts.app')

@section('title', 'Riwayat Transaksi POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('app.pos.index') }}">POS</a></li>
        <li class="breadcrumb-item active">Riwayat Transaksi</li>
    </ol>
@endsection

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            @include('utils.alerts')
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Riwayat Transaksi POS</h5>
                </div>

                <div class="card-body">
                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <input type="text" class="form-control" wire:model.live.debounce.300ms="search"
                                   placeholder="Cari nomor struk atau nama pelanggan...">
                        </div>
                        <div class="col-md-2">
                            <select class="form-control" wire:model.live="status">
                                <option value="">Semua Status</option>
                                <option value="Paid">Lunas</option>
                                <option value="Partial">Sebagian</option>
                                <option value="Unpaid">Belum Bayar</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-control" wire:model.live="sessionId">
                                <option value="">Semua Sesi</option>
                                @foreach($this->posSessions as $session)
                                    <option value="{{ $session->id }}">
                                        {{ $session->location->name ?? 'Unknown' }} -
                                        {{ $session->started_at->format('d/m/Y H:i') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" wire:model.live="dateFrom"
                                   placeholder="Dari Tanggal">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" wire:model.live="dateTo"
                                   placeholder="Sampai Tanggal">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-secondary btn-block" wire:click="clearFilters">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Results Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>No. Struk</th>
                                    <th>Tanggal</th>
                                    <th>Pelanggan</th>
                                    <th>Total</th>
                                    <th>Status Pembayaran</th>
                                    <th>Sesi POS</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($receipts as $receipt)
                                    <tr>
                                        <td>
                                            <strong>{{ $receipt->receipt_number }}</strong>
                                        </td>
                                        <td>
                                            {{ $receipt->created_at->format('d/m/Y H:i') }}
                                        </td>
                                        <td>
                                            {{ $receipt->customer_name ?: ($receipt->sales->first()?->customer?->customer_name ?: 'Walk-in') }}
                                        </td>
                                        <td>
                                            {{ format_currency($receipt->total_amount) }}
                                        </td>
                                        <td>
                                            <span class="badge badge-{{ $this->getStatusBadgeClass($receipt->payment_status) }}">
                                                {{ $receipt->payment_status }}
                                            </span>
                                        </td>
                                        <td>
                                            {{ $receipt->posSession?->location?->name ?? 'Unknown' }}
                                            <br>
                                            <small class="text-muted">
                                                {{ $receipt->posSession?->started_at?->format('d/m/Y H:i') ?? '' }}
                                            </small>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary"
                                                    wire:click="reprintReceipt({{ $receipt->id }})"
                                                    wire:confirm="Apakah Anda yakin ingin mencetak ulang struk ini?">
                                                <i class="fas fa-print"></i> Cetak Ulang
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <div class="text-muted">
                                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                                <p>Tidak ada transaksi POS ditemukan</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    @if($receipts->hasPages())
                        <div class="d-flex justify-content-center mt-3">
                            {{ $receipts->links() }}
                        </div>
                    @endif

                    <!-- Results Info -->
                    <div class="mt-3 text-muted">
                        Menampilkan {{ $receipts->firstItem() ?? 0 }} sampai {{ $receipts->lastItem() ?? 0 }}
                        dari total {{ $receipts->total() }} transaksi
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('styles')
<style>
.table th {
    border-top: none;
    font-weight: 600;
}

.badge {
    font-size: 0.75rem;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>
@endsection