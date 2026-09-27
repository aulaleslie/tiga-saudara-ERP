@extends('layouts.app')

@section('title', 'Buku Besar')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('reports.index', ['tab' => 'sekilas-bisnis']) }}">Laporan</a></li>
        <li class="breadcrumb-item active">Buku Besar</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.operational-general-ledger-report />
    </div>
@endsection
