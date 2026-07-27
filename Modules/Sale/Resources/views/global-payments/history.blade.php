@extends('layouts.app')

@section('title', 'Riwayat Pembayaran - Pembayaran Global')

@section('third_party_stylesheets')
    <link rel="stylesheet" href="{{ asset('vendor/datatables/datatables.min.css') }}">
@endsection

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.global-payments.index') }}">Pembayaran Penjualan Global</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.global-payments.show', $sale) }}">{{ $sale->reference }}</a></li>
        <li class="breadcrumb-item active">Riwayat Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h5 class="mb-0">Riwayat Pembayaran untuk {{ $sale->reference }}</h5>
                        <a href="{{ route('sales.global-payments.show', $sale->id) }}" class="btn btn-sm btn-info ms-auto">
                            <i class="bi bi-back"></i> Kembali
                        </a>
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
