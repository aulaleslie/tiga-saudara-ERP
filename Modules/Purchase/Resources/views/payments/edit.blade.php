@extends('layouts.app')

@section('title', 'Detail Pembayaran')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.show', $purchase) }}">{{ $purchase->reference }}</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchase-payments.index', $purchase->id) }}">Pembayaran</a></li>
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
                        <h5 class="m-0">Informasi Pembayaran: {{ $purchasePayment->reference }}</h5>
                        <a href="{{ route('purchase-payments.index', $purchase->id) }}" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Kembali ke Riwayat
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">No. Referensi Pembayaran</label>
                                <div class="font-weight-bold">{{ $purchasePayment->reference }}</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Tanggal Pembayaran</label>
                                <div class="font-weight-bold">{{ $purchasePayment->date ? $purchasePayment->date->format('d M, Y') : '-' }}</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Referensi Pembelian</label>
                                <div class="font-weight-bold">
                                    <a href="{{ route('purchases.show', $purchase) }}">{{ $purchase->reference }}</a>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">Pemasok (Supplier)</label>
                                <div class="font-weight-bold">{{ $purchase->supplier ? $purchase->supplier->supplier_name : 'N/A' }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Jumlah Pembayaran</label>
                                <div class="font-weight-bold text-success h5 mb-0">{{ format_currency($purchasePayment->amount) }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Metode Pembayaran</label>
                                <div class="font-weight-bold">{{ $purchasePayment->paymentMethod ? $purchasePayment->paymentMethod->name : ($purchasePayment->payment_method ?: 'N/A') }}</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="text-muted small">Status Pembayaran</label>
                                <div>
                                    <span class="badge badge-{{ $purchasePayment->isActive() ? 'success' : 'danger' }}">
                                        {{ $purchasePayment->status }}
                                    </span>
                                </div>
                            </div>
                            @if($purchasePayment->getMedia('attachments')->isNotEmpty())
                                <div class="col-md-12 mb-3">
                                    <label class="text-muted small">Lampiran</label>
                                    <div>
                                        <a href="{{ $purchasePayment->getFirstMediaUrl('attachments') }}" class="btn btn-outline-primary btn-sm" target="_blank">
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
                        @can('purchasePayments.edit')
                            @if($purchasePayment->isActive() && !$purchase->isArchived())
                                <form action="{{ route('purchase-payments.update', $purchasePayment) }}" method="POST">
                                    @csrf
                                    @method('patch')
                                    <div class="form-group">
                                        <label for="note">Catatan</label>
                                        <textarea class="form-control" rows="4" id="note" name="note" maxlength="1000" placeholder="Tambahkan catatan pembayaran...">{{ old('note', $purchasePayment->note) }}</textarea>
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
                                    <p class="mb-0"><strong>Catatan:</strong> {{ $purchasePayment->note ?: '-' }}</p>
                                    <small class="text-muted">Catatan tidak dapat diubah karena pembayaran tidak aktif atau pembelian telah diarsipkan.</small>
                                </div>
                            @endif
                        @else
                            <p class="mb-0"><strong>Catatan:</strong> {{ $purchasePayment->note ?: '-' }}</p>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
