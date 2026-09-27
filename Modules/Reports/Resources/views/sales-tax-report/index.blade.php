@extends('layouts.app')

@section('title', 'Laporan Pajak Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index', ['tab' => 'pajak']) }}">Laporan</a></li>
        <li class="breadcrumb-item active">Pajak Penjualan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.sales-tax-report />
    </div>
@endsection
