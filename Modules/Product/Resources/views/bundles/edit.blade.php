@extends('layouts.app')

@section('title', 'Ubah Paket Penjualan')

@section('content')
    <div class="container">
        <h3>Ubah Paket Penjualan untuk "{{ $parentProduct->product_name }}"</h3>
        <form action="{{ route('products.bundle.update', [$parentProduct->id, $bundle->id]) }}" method="POST">
            @csrf
            @method('PUT')
            <!-- Row for Nama Paket -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="bundle_name">Nama Paket</label>
                        <input type="text" name="name" id="bundle_name" class="form-control" value="{{ $bundle->name }}" required>
                    </div>
                </div>
            </div>

            <!-- Row for Periode Mulai and Periode Selesai -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_from">Periode Mulai</label>
                        <input type="date" name="active_from" id="active_from" class="form-control" value="{{ $bundle->active_from ? $bundle->active_from->format('Y-m-d') : '' }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_to">Periode Selesai</label>
                        <input type="date" name="active_to" id="active_to" class="form-control" value="{{ $bundle->active_to ? $bundle->active_to->format('Y-m-d') : '' }}">
                    </div>
                </div>
            </div>

            <!-- Row for Deskripsi -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="bundle_description">Deskripsi</label>
                        <textarea name="description" id="bundle_description" class="form-control">{{ $bundle->description }}</textarea>
                    </div>
                </div>
            </div>

            <hr>
            <h5>Item</h5>
            <!-- Embed the Livewire component for bundle items -->
            <livewire:product.bundle-table
                :productId="$parentProduct->id"
                :initialItems="$bundle->items->toArray()"
                :bundleId="$bundle->id"
                key="{{ $bundle->id }}"
            />
            <hr>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            <a href="javascript:history.back()" class="btn btn-secondary">Batal</a>
        </form>
    </div>
@endsection
