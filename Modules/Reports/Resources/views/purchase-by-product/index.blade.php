@extends('layouts.app')

@section('title', 'Pembelian per produk')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item">Laporan</li>
        <li class="breadcrumb-item">Pembelian</li>
        <li class="breadcrumb-item active">Pembelian per produk</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.purchase-by-product-report />
    </div>
@endsection
