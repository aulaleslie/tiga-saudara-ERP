@extends('layouts.app')

@section('title', 'Pengiriman Barang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Pengiriman Barang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            Menampilkan penjualan yang sudah disetujui atau sebagian dikirim untuk diproses pengiriman barang.
                        </p>

                        @if ($sale)
                            <div class="alert alert-info" role="alert">
                                Menyaring daftar untuk penjualan dengan referensi <strong>{{ $sale->reference }}</strong>.
                            </div>
                        @endif

                        <div class="table-responsive">
                            <livewire:sale.sale-table :status-filter="[
                                \Modules\Sale\Entities\Sale::STATUS_APPROVED,
                                \Modules\Sale\Entities\Sale::STATUS_DISPATCHED_PARTIALLY,
                            ]" :sale-id="optional($sale)->id" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    @include('sale::partials.lifecycle-warning-modal')
@endpush
