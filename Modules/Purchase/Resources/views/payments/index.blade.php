@extends('layouts.app')

@section('title', 'Purchase Payments')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Purchases</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.show', $purchase) }}">{{ $purchase->reference }}</a></li>
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
                            @if(($purchase->status === \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED || $purchase->status === \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY) && $purchase->due_amount > 0)
                                <a href="{{ route('purchase-payments.create', $purchase->id) }}" class="btn btn-primary">
                                    Tambah Pembayaran <i class="bi bi-plus"></i>
                                </a>
                            @endif
                        @endcan
                    </div>
                    <div class="card-body">
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
