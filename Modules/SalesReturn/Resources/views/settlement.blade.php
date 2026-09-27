@extends('layouts.app')

@section('title', 'Penyelesaian Retur Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sale-returns.index') }}">Retur Penjualan</a></li>
        <li class="breadcrumb-item active">Penyelesaian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:sales-return.sale-return-settlement-form :sale-return-id="$sale_return->id" />
    </div>
@endsection
