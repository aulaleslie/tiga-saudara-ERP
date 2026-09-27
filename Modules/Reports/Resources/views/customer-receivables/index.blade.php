@extends('layouts.app')

@section('title', 'Laporan Piutang Pelanggan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Laporan Piutang Pelanggan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <livewire:reports.customer-receivables-report />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
