@extends('layouts.app')

@section('title', 'Inisiasi Stok Produk')

@section('content')
    <div class="container-fluid">
        <form id="initialize-product-stock-form"
              action="{{ route('products.storeInitialProductStock', ['product_id' => $product->id]) }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.index') }}" class="btn btn-secondary mr-2">Kembali</a>

                        <!-- Conditionally show save buttons based on serial_number_required -->
                        <button type="submit" class="btn btn-primary" id="save-btn">Simpan</button>

                        @if ($product->serial_number_required)
                            <button type="submit" class="btn btn-primary" id="save-and-serial-btn"
                                    formaction="{{ route('products.storeInitialProductStockAndRedirectToInputSerialNumbers', ['product_id' => $product->id]) }}">
                                Simpan & Lanjut Input Serial Number
                            </button>
                        @endif
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <!-- Quantity -->
                            <div class="form-row">
                                <div class="col-md-4">
                                    <x-input label="Jumlah Stok" name="quantity" type="number" step="1" required/>
                                </div>
                            </div>

                            <!-- Quantities for non-tax, tax, broken items -->
                            <div class="form-row">
                                <div class="col-md-3">
                                    <x-input label="Stok Non-PPN" name="quantity_non_tax" type="number" step="1"
                                             required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok PPN" name="quantity_tax" type="number" step="1" required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok Rusak Non-PPN" name="broken_quantity_non_tax" type="number"
                                             step="1" required/>
                                </div>
                                <div class="col-md-3">
                                    <x-input label="Stok Rusak PPN" name="broken_quantity_tax" type="number" step="1"
                                             required/>
                                </div>
                            </div>

                            <!-- Purchase and sale prices (readonly) -->
                            <div class="form-row">
                                <div class="col-md-4">
                                    <x-input label="Harga Beli Terakhir" name="last_purchase_price" type="number"
                                             step="0.01" value="{{ $last_purchase_price }}" disabled required/>
                                </div>
                                <div class="col-md-4">
                                    <x-input label="Harga Beli Rata-Rata" name="average_purchase_price" type="number"
                                             step="0.01" value="{{ $average_purchase_price }}" disabled required/>
                                </div>
                                <div class="col-md-4">
                                    <x-input label="Harga Jual" name="sale_price" type="number" step="0.01"
                                             value="{{ $sale_price }}" disabled required/>
                                </div>
                            </div>

                            <!-- Location -->
                            <div class="form-row">
                                <div class="col-md-4">
                                    <x-select label="Lokasi" name="location_id"
                                              :options="$locations->pluck('name', 'id')" required/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection
