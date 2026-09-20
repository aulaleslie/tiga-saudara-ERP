@extends('layouts.app')

@section('title', 'Pembayaran POS Global')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">Pembayaran POS Global</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')

                @include('pos::global-payments.partials.workspace', [
                    'customerId' => null,
                    'keyPrefix' => 'standalone',
                ])
            </div>
        </div>
    </div>
@endsection
