@extends('layouts.app')

@section('title', 'Create Sale Return')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sale-returns.index') }}">Retur Penjualan</a></li>
        <li class="breadcrumb-item active">Tambahkan</li>
    </ol>
@endsection

@section('content')
    <livewire:sales-return.sale-return-create-form />
@endsection
