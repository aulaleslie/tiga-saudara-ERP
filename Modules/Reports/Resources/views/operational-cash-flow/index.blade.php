@extends('layouts.app')

@section('title', 'Arus Kas')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('reports.index', ['tab' => 'sekilas-bisnis']) }}">Laporan</a></li>
        <li class="breadcrumb-item active">Arus Kas</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.operational-cash-flow-report />
    </div>
@endsection
