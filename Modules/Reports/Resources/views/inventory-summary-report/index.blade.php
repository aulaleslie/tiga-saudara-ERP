@extends('layouts.app')

@section('title', 'Ringkasan Persediaan Barang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Ringkasan Persediaan Barang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <livewire:reports.inventory-summary-report />
            </div>
        </div>
    </div>
@endsection
