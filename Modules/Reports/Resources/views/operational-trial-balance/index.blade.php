@extends('layouts.app')

@section('title', 'Neraca Saldo')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('reports.index', ['tab' => 'sekilas-bisnis']) }}">Laporan</a></li>
        <li class="breadcrumb-item active">Neraca Saldo</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.operational-trial-balance-report />
    </div>
@endsection
