@extends('layouts.app')

@section('title', 'Buat Paket Penjualan')

@section('content')
    <div class="container">
        <h3>Buat Paket Penjualan untuk "{{ $product->product_name }}"</h3>
        <form action="{{ route('products.bundle.store', $product->id) }}" method="POST">
            @csrf
            <!-- Row for Nama Paket -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="bundle_name">Nama Paket</label>
                        <input type="text" name="name" id="bundle_name" class="form-control" required>
                    </div>
                </div>
            </div>

            <!-- Row for Periode Mulai and Periode Selesai -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_from">Periode Mulai</label>
                        <input type="date" name="active_from" id="active_from" class="form-control">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_to">Periode Selesai</label>
                        <input type="date" name="active_to" id="active_to" class="form-control">
                    </div>
                </div>
            </div>

            <!-- Row for Deskripsi -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="bundle_description">Deskripsi</label>
                        <textarea name="description" id="bundle_description" class="form-control"></textarea>
                    </div>
                </div>
            </div>
            <hr>
            <h5>Item</h5>
            <!-- Embed the Livewire component for bundle items -->
            <livewire:product.bundle-table :productId="$product->id" />
            <hr>
            <button type="submit" class="btn btn-primary">Simpan</button>
            <a href="javascript:history.back()" class="btn btn-secondary">Batal</a>
        </form>
    </div>
@endsection
