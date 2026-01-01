@extends('layouts.app')

@section('title', $isGlobal ? 'Laporan Pembelian Global' : 'Laporan Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item active">{{ $isGlobal ? 'Laporan Pembelian Global' : 'Laporan Pembelian' }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @if($isGlobal)
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-globe"></i> <strong>Mode Global:</strong> Menampilkan data dari semua setting/cabang.
                    </div>
                @endif
                <livewire:reports.purchase-report :isGlobal="$isGlobal" />
            </div>
        </div>
    </div>
@endsection
