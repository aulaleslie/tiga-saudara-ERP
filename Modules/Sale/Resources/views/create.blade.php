@extends('layouts.app')

@section('title', 'Buat Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item active">Tambah</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Search Product Livewire Component -->
        <div class="row">
            <div class="col-12">
                <livewire:sale.search-product/>
            </div>
        </div>

        <!-- Sale Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <!-- Alert Messages -->
                        @include('utils.alerts')

                        <!-- Sale Form Start -->
                        <form id="sale-form" action="{{ route('sales.store') }}" method="POST">
                            @csrf

                            <livewire:sale.form-header />

                            <!-- Product Cart Livewire Component -->
                            <livewire:sale.product-cart :cartInstance="'sale'"/>

                            <!-- Catatan -->
                            <div class="form-group mt-4">
                                <label for="note">Catatan (Jika Diperlukan)</label>
                                <textarea name="note" id="note" rows="5"
                                          class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                                @error('note')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Submit Button -->
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary"
                                        onclick="showConfirmationModal(() => document.getElementById('sale-form').submit(), 'Apakah Anda yakin ingin membuat penjualan ini?')">
                                    Buat Penjualan <i class="bi bi-check"></i>
                                </button>
                                <a href="{{ route('sales.index') }}" class="btn btn-secondary">Kembali</a>
                            </div>
                        </form>
                        <!-- Sale Form End -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('components.confirmation-modal')
@endsection
