@extends('layouts.app')

@section('title', 'Detail Transaksi POS - ' . $transaction->code)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.global-payments.index') }}">Pembayaran POS Global</a></li>
        <li class="breadcrumb-item active">{{ $transaction->code }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                @include('utils.alerts')
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0">Detail Transaksi POS: <span class="text-primary">{{ $transaction->code }}</span></h4>
                        <span class="text-muted">Cabang/Setting: {{ $transaction->setting->company_name ?? 'N/A' }} | Dibuat: {{ \Carbon\Carbon::parse($transaction->created_at)->format('d/m/Y H:i') }}</span>
                    </div>
                    <div>
                        @can('pos.receipts.reprint')
                            <a href="{{ route('pos.global-payments.receipt.reprint', $transaction->id) }}" target="_blank" class="btn btn-secondary">
                                <i class="bi bi-printer"></i> Cetak Ulang Struk
                            </a>
                        @endcan
                        @can('posPayments.global.history')
                            <a href="{{ route('pos.global-payments.history', $transaction->id) }}" class="btn btn-outline-info">
                                <i class="bi bi-clock-history"></i> Riwayat Pembayaran
                            </a>
                        @endcan
                        @can('posPayments.global.create')
                            @if($projection['live_due'] > 0)
                                <a href="{{ route('pos.global-payments.create', $transaction->id) }}" class="btn btn-primary">
                                    <i class="bi bi-cash-stack"></i> Bayar Multi-POS
                                </a>
                            @endif
                        @endcan
                        <a href="{{ route('pos.global-payments.index') }}" class="btn btn-light">Kembali</a>
                    </div>
                </div>
            </div>

            <!-- Settlement Overview Cards -->
            <div class="col-md-3">
                <div class="card border-primary mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Total Nilai POS</h6>
                        <h4 class="mb-0 text-primary">{{ format_currency($projection['total_amount']) }}</h4>
                        <small class="text-muted">Total transaksi checkout</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Telah Dibayar (Efektif)</h6>
                        <h4 class="mb-0 text-success">{{ format_currency($projection['effective_paid']) }}</h4>
                        <small class="text-muted">Akumulasi pembayaran valid</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-danger mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Sisa Tagihan (Live Due)</h6>
                        <h4 class="mb-0 text-danger">{{ format_currency($projection['live_due']) }}</h4>
                        <small class="text-muted">
                            @if($projection['live_due'] <= 0)
                                <span class="badge bg-success">Lunas</span>
                            @else
                                <span class="badge bg-danger">Belum Lunas</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-1">Jatuh Tempo Terdekat</h6>
                        <h4 class="mb-0 text-warning">{{ $projection['effective_due_date'] ? \Carbon\Carbon::parse($projection['effective_due_date'])->format('d M Y') : '-' }}</h4>
                        <small class="text-muted">
                            @if($projection['is_overdue'])
                                <span class="badge bg-danger">Terlambat</span>
                            @else
                                <span class="badge bg-secondary">Aman</span>
                            @endif
                        </small>
                    </div>
                </div>
            </div>

            <!-- Transaction Info & Historical Tender -->
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold">Informasi Header Transaksi POS</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0">
                            <tr>
                                <th style="width: 35%;">Kode Transaksi</th>
                                <td>{{ $transaction->code }}</td>
                            </tr>
                            <tr>
                                <th>Pelanggan</th>
                                <td>{{ $transaction->customer->customer_name ?? 'Umum' }} ({{ $transaction->customer->customer_phone ?? '-' }})</td>
                            </tr>
                            <tr>
                                <th>Perusahaan / Cabang</th>
                                <td>{{ $transaction->setting->company_name ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <th>Kasir / Petugas</th>
                                <td>{{ $checkout?->cashier?->name ?? $transaction->creator?->name ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Terminal POS</th>
                                <td>{{ $checkout?->terminal?->name ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <th>Status Transaksi</th>
                                <td>
                                    <span class="badge bg-success">{{ $transaction->status }}</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold">Fakta Tender Checkout Historis (Read-Only)</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0">
                            <tr>
                                <th style="width: 35%;">Metode Pembayaran Kasir</th>
                                <td>{{ $checkout?->paymentMethod?->name ?? 'Tunai' }}</td>
                            </tr>
                            <tr>
                                <th>Nominal Diterima Kasir</th>
                                <td>{{ format_currency($checkout?->tender_amount ?? $transaction->amount_tendered ?? 0) }}</td>
                            </tr>
                            <tr>
                                <th>Nominal Kembalian Kasir</th>
                                <td>{{ format_currency($checkout?->change_amount ?? $transaction->amount_change ?? 0) }}</td>
                            </tr>
                            <tr>
                                <th>Catatan Checkout</th>
                                <td>{{ $checkout?->note ?? $transaction->note ?? '-' }}</td>
                            </tr>
                        </table>
                        @if($checkout && $checkout->payments->isNotEmpty())
                            <div class="p-2 border-top bg-light">
                                <small class="fw-bold">Item Tender Checkout Kasir:</small>
                                <ul class="mb-0 ps-3 small text-muted">
                                    @foreach($checkout->payments as $tenderItem)
                                        <li>{{ $tenderItem->paymentMethod->name ?? 'Metode #' . $tenderItem->payment_method_id }}: {{ format_currency($tenderItem->amount) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Reachable Sales & Canonical Ledger -->
            <div class="col-lg-12">
                <div class="card mb-3">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold">Penjualan Terkait (Reachable Sales) & Status Buku Besar Penjualan</h6>
                        <span class="badge bg-secondary">{{ $reachableSales->count() }} Penjualan Ditemukan</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Referensi Penjualan</th>
                                        <th>Perusahaan / Tenant</th>
                                        <th>Tanggal Penjualan</th>
                                        <th>Jatuh Tempo</th>
                                        <th class="text-end">Total Nilai</th>
                                        <th class="text-end">Dibayar</th>
                                        <th class="text-end">Sisa Tagihan</th>
                                        <th class="text-center">Status Pembayaran</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($reachableSales as $sale)
                                        @php
                                            $salePaid = $sale->salePayments->where('is_invalidated', false)->sum('amount');
                                            $saleDue = max(0, $sale->total_amount - $salePaid);
                                        @endphp
                                        <tr>
                                            <td>
                                                <strong>{{ $sale->reference }}</strong>
                                                @if($sale->imported_sales_reference_number)
                                                    <br><small class="text-muted">{{ $sale->imported_sales_reference_number }}</small>
                                                @endif
                                            </td>
                                            <td>{{ $sale->tenantSetting->company_name ?? 'N/A' }}</td>
                                            <td>{{ \Carbon\Carbon::parse($sale->date)->format('d/m/Y') }}</td>
                                            <td>{{ $sale->due_date ? \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') : '-' }}</td>
                                            <td class="text-end">{{ format_currency($sale->total_amount) }}</td>
                                            <td class="text-end text-success">{{ format_currency($salePaid) }}</td>
                                            <td class="text-end text-danger fw-bold">{{ format_currency($saleDue) }}</td>
                                            <td class="text-center">
                                                @if($sale->payment_status === 'PAID')
                                                    <span class="badge bg-success">Lunas</span>
                                                @elseif($sale->payment_status === 'PARTIAL')
                                                    <span class="badge bg-warning text-dark">Sebagian</span>
                                                @else
                                                    <span class="badge bg-danger">Belum Bayar</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <a href="{{ route('sales.show', $sale->id) }}" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="bi bi-eye"></i> Detail
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="text-center py-3 text-muted">
                                                Tidak ada record Penjualan yang terhubung secara kanonikal.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Purchased & Serial Tracking -->
            <div class="col-lg-12">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold">Daftar Barang & Serial Number</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kode / Barcode</th>
                                        <th>Nama Produk</th>
                                        <th class="text-center">Kuantitas</th>
                                        <th class="text-end">Harga Satuan</th>
                                        <th class="text-end">Diskon</th>
                                        <th class="text-end">Subtotal</th>
                                        <th>Serial Numbers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($transaction->lines as $line)
                                        <tr>
                                            <td>{{ $line->product?->product_code ?? '-' }}</td>
                                            <td>{{ $line->product_name }}</td>
                                            <td class="text-center">{{ (float) $line->quantity }}</td>
                                            <td class="text-end">{{ format_currency($line->unit_price) }}</td>
                                            <td class="text-end">{{ format_currency($line->discount_amount) }}</td>
                                            <td class="text-end fw-bold">{{ format_currency($line->subtotal) }}</td>
                                            <td>
                                                @if($line->serials->isNotEmpty())
                                                    <div class="small">
                                                        @foreach($line->serials as $serial)
                                                            <span class="badge bg-light text-dark border">{{ $serial->serial_number }}</span>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <span class="text-muted small">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print Logs -->
            @if(($printLogs ?? collect())->isNotEmpty())
                <div class="col-lg-12">
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0 fw-bold">Riwayat Cetak Struk</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Waktu Cetak</th>
                                        <th>Tipe Cetak</th>
                                        <th>Petugas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($printLogs as $log)
                                        <tr>
                                            <td>{{ $log->printed_at ? \Carbon\Carbon::parse($log->printed_at)->format('d/m/Y H:i:s') : '-' }}</td>
                                            <td>
                                                <span class="badge {{ $log->print_type === 'REPRINT' ? 'bg-warning text-dark' : 'bg-info' }}">
                                                    {{ $log->print_type }}
                                                </span>
                                            </td>
                                            <td>{{ $log->printer->name ?? 'Sistem' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
@endsection
