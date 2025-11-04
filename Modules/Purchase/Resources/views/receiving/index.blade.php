@extends('layouts.app')

@section('title', 'Penerimaan Barang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Penerimaan Barang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            Menampilkan pembelian yang sudah disetujui atau sebagian diterima untuk diproses penerimaan barang.
                        </p>

                        <div class="table-responsive">
                            <livewire:purchase.purchase-table :status-filter="[
                                \Modules\Purchase\Entities\Purchase::STATUS_APPROVED,
                                \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED_PARTIALLY,
                            ]" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
