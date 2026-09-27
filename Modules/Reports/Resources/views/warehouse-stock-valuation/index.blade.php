@extends('layouts.app')

@section('title', 'Nilai Stok Gudang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Nilai Stok Gudang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.warehouse-stock-valuation-report />
    </div>
@endsection
