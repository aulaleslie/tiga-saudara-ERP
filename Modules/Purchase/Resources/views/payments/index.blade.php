@extends('layouts.app')

@section('title', 'Purchase Payments')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        @if(isset($globalMode) && $globalMode)
            <li class="breadcrumb-item"><a href="{{ route('purchases.global-payments.index') }}">Pembayaran Pembelian Global</a></li>
            <li class="breadcrumb-item"><a href="{{ route('purchases.global-payments.show', $purchase) }}">{{ $purchase->reference }}</a></li>
        @else
            <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Purchases</a></li>
            <li class="breadcrumb-item"><a href="{{ route('purchases.show', $purchase) }}">{{ $purchase->reference }}</a></li>
        @endif
        <li class="breadcrumb-item active">Payments</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
                <div class="card">
                    <div class="card-header">
                        @can('purchasePayments.create')
                            @php
                                $hasDebt = (isset($globalMode) && $globalMode) ? ($purchase->live_due_amount > 0) : ($purchase->due_amount > 0);
                            @endphp
                            @if(($purchase->status === \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED || $purchase->status === \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY) && $hasDebt)
                                <a href="{{ isset($globalMode) && $globalMode ? route('purchases.global-payments.create', ['supplier' => $purchase->supplier_id, 'purchase_id' => $purchase->id]) : route('purchase-payments.create', $purchase->id) }}" class="btn btn-primary">
                                    Tambah Pembayaran <i class="bi bi-plus"></i>
                                </a>
                            @endif
                        @endcan
                    </div>
                    <div class="card-body">
                        @if($purchase->isConsignmentBilling() && $purchase->consignmentBillingConfirmation)
                            <div class="alert alert-info border-left-info shadow-sm mb-3">
                                <i class="bi bi-link-45deg mr-1"></i>
                                <strong>Tagihan Konsinyasi:</strong>
                                Dikonversi dari Konfirmasi Alokasi
                                <a href="{{ route('consignments.confirmations.show', $purchase->consignmentBillingConfirmation->id) }}" class="font-weight-bold alert-link">
                                    #{{ $purchase->consignmentBillingConfirmation->confirmation_number }}
                                </a>
                                @if($purchase->consignmentBillingConfirmation->supplier_invoice_number)
                                    &middot; Faktur Pemasok: {{ $purchase->consignmentBillingConfirmation->supplier_invoice_number }}
                                @endif
                                &middot; Nilai komersial bersifat read-only; pembayaran tetap dapat dicatat.
                            </div>
                        @endif
                        <div class="table-responsive">
                            {!! $dataTable->table() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    {!! $dataTable->scripts() !!}
@endpush
