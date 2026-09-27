@extends('layouts.app')

@section('title', $transfer ? 'Ubah Transfer Stok' : 'Buat Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item active">{{ $transfer ? 'Ubah Transfer Stok' : 'Buat Transfer Stok' }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">
                    {{ $transfer ? 'Ubah Transfer Stok ' . $transfer->document_number : 'Buat Transfer Stok' }}
                </h5>
                <p class="text-muted">Catat barang yang akan dipindahkan. Lokasi sumber dan tujuan ditentukan oleh penyetuju.</p>
                @include('utils.alerts')
                @if($transfer)
                    <livewire:transfer.transfer-v3-goods-form :transfer="$transfer" />
                @else
                    <livewire:transfer.transfer-v3-goods-form />
                @endif
            </div>
        </div>
    </div>
@endsection
