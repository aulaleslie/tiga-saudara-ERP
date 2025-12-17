@extends('layouts.app')

@section('title', 'Buat Pembelian Baru')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Tambah Baru</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Product Search Component -->
        <div class="row">
            <div class="col-12">
                <livewire:purchase.search-product />
            </div>
        </div>

        <!-- Purchase Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <livewire:purchase.create-form :idempotencyToken="$idempotencyToken" />
                </div>
            </div>
        </div>
    </div>

    @include('components.confirmation-modal')
@endsection
