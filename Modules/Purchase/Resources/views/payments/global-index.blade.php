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
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        {{-- No create or import buttons for global payment view --}}

                        @include('purchase::payments.partials.workspace', [
                            'supplierId' => null,
                            'keyPrefix' => 'standalone',
                        ])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
