@extends('layouts.app')

@section('title', 'Penjualan per Produk')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Penjualan per Produk</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.sale-by-product-report />
    </div>
@endsection
