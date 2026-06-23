@extends('layouts.app')

@section('title', 'Kuantitas Stok Gudang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Kuantitas Stok Gudang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <livewire:reports.warehouse-stock-quantity-report />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
