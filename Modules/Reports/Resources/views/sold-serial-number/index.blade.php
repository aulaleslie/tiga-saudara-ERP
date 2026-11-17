@extends('layouts.app')

@section('title', 'Serial Number Terjual')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Laporan</a></li>
        <li class="breadcrumb-item active">Serial Number Terjual</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:reports.sold-serial-number-report />
    </div>
@endsection
