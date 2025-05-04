@extends('layouts.app')

@section('title', 'Buat Paket Penjualan')

@section('content')
    <div class="container">
        <h3>Buat Paket Penjualan untuk "{{ $product->product_name }}"</h3>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('products.bundle.store', $product->id) }}" method="POST">
            @csrf

            <!-- Row for Nama Paket dan Harga Paket -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="bundle_name">Nama Paket</label>
                        <input type="text" value="{{ old('name') }}" name="name" id="bundle_name" class="form-control" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="bundle_price">Harga Paket</label>
                        <input type="text" name="price" id="bundle_price" class="form-control"
                               value="{{ old('price', 0) }}">
                    </div>
                </div>
            </div>

            <!-- Row for Periode Mulai and Periode Selesai -->
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_from">Periode Mulai</label>
                        <input type="date" name="active_from" id="active_from" class="form-control"
                               value="{{ old('active_from') }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label for="active_to">Periode Selesai</label>
                        <input type="date" name="active_to" id="active_to" class="form-control"
                               value="{{ old('active_to') }}">
                    </div>
                </div>
            </div>

            <!-- Row for Deskripsi -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="bundle_description">Deskripsi</label>
                        <textarea name="description" id="bundle_description" class="form-control">{{ old('description') }}</textarea>
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

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const priceInput = document.getElementById('bundle_price');
            const form = priceInput.closest('form');

            // Format number to Indonesian currency
            const formatToRupiah = (value) => {
                const number = parseFloat(value.replace(/[^0-9.,]/g, '').replace(',', '.'));
                if (isNaN(number)) return '';
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 2
                }).format(number);
            };

            // Unformat: convert formatted to plain decimal string (e.g., "Rp 10.000,00" → "10000.00")
            const unformat = (value) => {
                if (!value) return '';
                return value.replace(/[^0-9,]/g, '')   // Remove non-numeric (keep comma)
                    .replace(',', '.');        // Convert comma to dot for decimals
            };

            // On blur → show formatted
            priceInput.addEventListener('blur', function () {
                const unformatted = unformat(this.value);
                this.value = formatToRupiah(unformatted);
            });

            // On focus → show plain number
            priceInput.addEventListener('focus', function () {
                this.value = unformat(this.value);
            });

            // On submit → set input value to plain decimal before sending to backend
            form.addEventListener('submit', function () {
                priceInput.value = unformat(priceInput.value);
            });

            // Format price once on page load
            if (priceInput.value) {
                priceInput.value = formatToRupiah(priceInput.value);
            }
        });
    </script>
@endpush
