@extends('layouts.app')

@section('title', 'Pengiriman Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item">Laporan</li>
        <li class="breadcrumb-item">Penjualan</li>
        <li class="breadcrumb-item active">Pengiriman Penjualan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.sale-delivery-report />
    </div>
@endsection
