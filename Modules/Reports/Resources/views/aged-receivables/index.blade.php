@extends('layouts.app')

@section('title', 'Usia piutang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index', ['tab' => 'penjualan']) }}">Laporan</a></li>
        <li class="breadcrumb-item active">Usia piutang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.aged-receivables-report />
    </div>
@endsection
