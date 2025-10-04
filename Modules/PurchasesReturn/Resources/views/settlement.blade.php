@extends('layouts.app')

@section('title', 'Metode Penyelesaian Retur Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchase-returns.index') }}">Retur Pembelian</a></li>
        <li class="breadcrumb-item active">Penyelesaian</li>
    </ol>
@endsection

@section('content')
    <livewire:purchase-return.purchase-return-settlement-form :purchase-return-id="$purchase_return->id" />
@endsection
