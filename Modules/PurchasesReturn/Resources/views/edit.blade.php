@extends('layouts.app')

@section('title', 'Edit Purchase Return')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchase-returns.index') }}">Retur Pembelian</a></li>
        <li class="breadcrumb-item active">ubah</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:purchase-return.purchase-return-edit-form :purchaseReturn="$purchase_return" />
    </div>
@endsection
