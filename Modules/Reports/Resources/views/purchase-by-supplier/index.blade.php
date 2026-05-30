@extends('layouts.app')

@section('title', 'Pembelian Per Supplier')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item">Laporan</li>
        <li class="breadcrumb-item">Pembelian</li>
        <li class="breadcrumb-item active">Pembelian Per Supplier</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.purchase-by-supplier-report />
    </div>
@endsection
