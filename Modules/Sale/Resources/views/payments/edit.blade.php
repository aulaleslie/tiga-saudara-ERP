@extends('layouts.app')

@section('title', 'Detail Pembayaran')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.show', $sale) }}">{{ $sale->reference }}</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sale-payments.index', $sale->id) }}">Pembayaran</a></li>
        <li class="breadcrumb-item active">Detail Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                @include('utils.alerts')
            </div>

            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="m-0">Informasi Pembayaran: {{ $salePayment->reference }}</h5>
                        <a href="{{ route('sale-payments.index', $sale->id) }}" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Kembali ke Riwayat
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">No. Referensi Pembayaran</label>
                                <div class="font-weight-bold">{{ $salePayment->reference }}</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Tanggal Pembayaran</label>
                                <div class="font-weight-bold">{{ $salePayment->date }}</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Referensi Penjualan</label>
                                <div class="font-weight-bold">
                                    <a href="{{ route('sales.show', $sale) }}">{{ $sale->reference }}</a>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Pelanggan</label>
                                <div class="font-weight-bold">{{ $sale->customer_name ?? ($sale->customer ? $sale->customer->customer_name : 'N/A') }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Jumlah Pembayaran</label>
                                <div class="font-weight-bold text-success h5 mb-0">{{ format_currency($salePayment->amount) }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Metode Pembayaran</label>
                                <div class="font-weight-bold">{{ $salePayment->paymentMethod ? $salePayment->paymentMethod->name : ($salePayment->payment_method ?: 'N/A') }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Status Pembayaran</label>
                                <div>
                                    <span class="badge badge-{{ $salePayment->isActive() ? 'success' : 'danger' }}">
                                        {{ $salePayment->status }}
                                    </span>
                                </div>
                            </div>
                            @if($salePayment->getMedia('attachments')->isNotEmpty())
                                <div class="col-md-12 mb-3">
                                    <label class="text-muted small">Lampiran</label>
                                    <div>
                                        <a href="{{ $salePayment->getFirstMediaUrl('attachments') }}" class="btn btn-outline-primary btn-sm" target="_blank">
                                            <i class="bi bi-paperclip"></i> Lihat Lampiran
                                        </a>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="m-0">Catatan Pembayaran</h5>
                    </div>
                    <div class="card-body">
                        @can('salePayments.edit')
                            @if($salePayment->isActive() && !$sale->isArchived())
                                <form action="{{ route('sale-payments.update', $salePayment) }}" method="POST">
                                    @csrf
                                    @method('patch')
                                    <div class="form-group">
                                        <label for="note">Catatan</label>
                                        <textarea class="form-control" rows="4" id="note" name="note" maxlength="1000" placeholder="Tambahkan catatan pembayaran...">{{ old('note', $salePayment->note) }}</textarea>
                                        <small class="form-text text-muted">Maksimal 1000 karakter.</small>
                                    </div>
                                    <div class="form-group mb-0">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg"></i> Simpan Catatan
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="alert alert-secondary mb-0">
                                    <p class="mb-0"><strong>Catatan:</strong> {{ $salePayment->note ?: '-' }}</p>
                                    <small class="text-muted">Catatan tidak dapat diubah karena pembayaran tidak aktif atau penjualan telah diarsipkan.</small>
                                </div>
                            @endif
                        @else
                            <p class="mb-0"><strong>Catatan:</strong> {{ $salePayment->note ?: '-' }}</p>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
