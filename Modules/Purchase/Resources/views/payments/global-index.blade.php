@extends('layouts.app')

@section('title', 'Pembayaran Pembelian Global')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Pembayaran Pembelian Global</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @if($projectionProduct)
            <div class="alert alert-info">
                <strong>Pembelian terkait proyeksi HPP:</strong>
                {{ $projectionProduct->product_name }}
                @if($projectionProduct->product_code)
                    ({{ $projectionProduct->product_code }})
                @endif
                <span class="d-block small mt-1">Menampilkan pembelian disetujui dengan sisa kuantitas produk ini di seluruh bisnis.</span>
            </div>
        @endif
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        {{-- No create or import buttons for global payment view --}}

                        @include('purchase::payments.partials.workspace', [
                            'supplierId' => null,
                            'keyPrefix' => 'standalone',
                            'projectionProduct' => $projectionProduct,
                        ])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
