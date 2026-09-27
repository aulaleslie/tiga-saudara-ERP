@extends('layouts.app')

@section('title', 'Riwayat Pembayaran POS - ' . $transaction->code)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.global-payments.index') }}">Pembayaran POS Global</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.global-payments.show', $transaction->id) }}">{{ $transaction->code }}</a></li>
        <li class="breadcrumb-item active">Riwayat Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                @include('utils.alerts')
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0">Riwayat Pembayaran Transaksi POS: <span class="text-primary">{{ $transaction->code }}</span></h4>
                        <span class="text-muted">Pelanggan: {{ $transaction->customer->customer_name ?? 'Umum' }} | Cabang: {{ $transaction->setting->company_name ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <a href="{{ route('pos.global-payments.show', $transaction->id) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Kembali ke Detail
                        </a>
                        @can('posPayments.global.create')
                            @if($projection['live_due'] > 0)
                                <a href="{{ route('pos.global-payments.create', $transaction->id) }}" class="btn btn-primary">
                                    <i class="bi bi-cash-stack"></i> Bayar Multi-POS
                                </a>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>

            <!-- Summary Status -->
            <div class="col-md-4">
                <div class="card border-primary mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Total Nilai POS</h6>
                        <h4 class="mb-0 text-primary">{{ format_currency($projection['total_amount']) }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Total Pembayaran Efektif</h6>
                        <h4 class="mb-0 text-success">{{ format_currency($projection['effective_paid']) }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-danger mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Sisa Saldo Tagihan</h6>
                        <h4 class="mb-0 text-danger">{{ format_currency($projection['live_due']) }}</h4>
                    </div>
                </div>
            </div>

            <!-- Sale Payments Ledger Entries -->
            <div class="col-lg-12">
                <div class="card mb-3">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold">Entri Pembayaran Penjualan (Sale Payments)</h6>
                        <span class="badge bg-secondary">{{ $salePayments->count() }} Pembayaran</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Referensi Pembayaran</th>
                                        <th>Referensi Penjualan</th>
                                        <th>Tenant / Cabang</th>
                                        <th>Metode Pembayaran</th>
                                        <th class="text-end">Jumlah</th>
                                        <th class="text-center">Status Entri</th>
                                        <th>Lampiran</th>
                                        <th>Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($salePayments as $payment)
                                        <tr class="{{ $payment->is_invalidated ? 'table-secondary text-muted' : '' }}">
                                            <td>{{ \Carbon\Carbon::parse($payment->date)->format('d/m/Y') }}</td>
                                            <td><strong>{{ $payment->reference }}</strong></td>
                                            <td>{{ $payment->sale?->reference ?? '-' }}</td>
                                            <td>{{ $payment->sale?->tenantSetting?->company_name ?? 'N/A' }}</td>
                                            <td>{{ $payment->paymentMethod?->name ?? $payment->payment_method }}</td>
                                            <td class="text-end fw-bold {{ $payment->is_invalidated ? 'text-decoration-line-through' : 'text-success' }}">
                                                {{ format_currency($payment->amount) }}
                                            </td>
                                            <td class="text-center">
                                                @if($payment->is_invalidated)
                                                    <span class="badge bg-danger">Dibatalkan / Invalid</span>
                                                @else
                                                    <span class="badge bg-success">Aktif</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($payment->getFirstMedia('document'))
                                                    <a href="{{ $payment->getFirstMediaUrl('document') }}" target="_blank" class="btn btn-sm btn-outline-info">
                                                        <i class="bi bi-file-earmark-arrow-down"></i> Berkas
                                                    </a>
                                                @else
                                                    <span class="text-muted small">-</span>
                                                @endif
                                            </td>
                                            <td>{{ $payment->note ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center py-3 text-muted">
                                                Belum ada data pembayaran buku besar untuk transaksi ini.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Global POS Payment Batch Allocations Audit Trail -->
            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold">Audit Alokasi Batch Pembayaran POS Global</h6>
                        <span class="badge bg-secondary">{{ $allocations->count() }} Alokasi Batch</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Waktu Transaksi</th>
                                        <th>Ref Batch</th>
                                        <th>Petugas</th>
                                        <th>Penjualan Target</th>
                                        <th>Tenant Target</th>
                                        <th>Metode Pembayaran Batch</th>
                                        <th class="text-end">Nominal Dialokasikan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($allocations as $allocation)
                                        <tr>
                                            <td>{{ \Carbon\Carbon::parse($allocation->created_at)->format('d/m/Y H:i:s') }}</td>
                                            <td>{{ $allocation->batch?->reference ?? 'Batch #' . $allocation->global_pos_payment_batch_id }}</td>
                                            <td>{{ $allocation->batch?->user?->name ?? 'Sistem' }}</td>
                                            <td>{{ $allocation->sale?->reference ?? '-' }}</td>
                                            <td>{{ $allocation->sale?->tenantSetting?->company_name ?? 'N/A' }}</td>
                                            <td>{{ $allocation->batch?->paymentMethod?->name ?? '-' }}</td>
                                            <td class="text-end fw-bold text-primary">{{ format_currency($allocation->allocated_amount) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center py-3 text-muted">
                                                Belum ada alokasi dari batch pembayaran POS global.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
