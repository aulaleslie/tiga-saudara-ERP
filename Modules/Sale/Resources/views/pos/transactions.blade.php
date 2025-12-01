@extends('layouts.app')

@section('title', 'Riwayat Transaksi POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('app.pos.index') }}">POS</a></li>
        <li class="breadcrumb-item active">Riwayat Transaksi</li>
    </ol>
@endsection

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            @livewire('pos-transactions')
        </div>
    </div>
</div>
@endsection