@extends('layouts.app')

@section('title', 'Penyelesaian Pemesanan Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Penyelesaian Pemesanan Pembelian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.purchase-order-completion-report />
    </div>
@endsection
