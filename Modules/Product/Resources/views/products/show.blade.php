@extends('layouts.app')

@section('title', 'Product Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Products</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <!-- Left Section (Product Details) -->
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <tr>
                                    <th>Kode Produk</th>
                                    <td>{{ $product->product_code }}</td>
                                </tr>
                                <tr>
                                    <th>Barcode Symbology</th>
                                    <td>{{ $product->product_barcode_symbology }}</td>
                                </tr>
                                <tr>
                                    <th>Nama Produk</th>
                                    <td>{{ $product->product_name }}</td>
                                </tr>
                                <tr>
                                    <th>Kategori</th>
                                    <td>{{ $product->category->category_name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Beli</th>
                                    <td>{{ format_currency($product->purchase_price) }}</td>
                                </tr>
                                <tr>
                                    <th>Pajak Beli</th>
                                    <td>
                                        @if($product->purchaseTax)
                                            {{ $product->purchaseTax->name }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Harga Jual</th>
                                    <td>{{ format_currency($product->sale_price) }}</td>
                                </tr>
                                <tr>
                                    <th>Pajak Jual</th>
                                    <td>
                                        @if($product->saleTax)
                                            {{ $product->saleTax->name }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Kuantitas</th>
                                    <td>{{ $displayQuantity }}</td>
                                </tr>
                                <tr>
                                    <th>Stock Worth</th>
                                    <td>
                                        HARGA BELI: {{ format_currency($product->purchase_price * $product->product_quantity) }} /
                                        HARGA JUAL: {{ format_currency($product->sale_price * $product->product_quantity) }}
                                    </td>
                                </tr>
                                <tr>
                                    <th>Peringantan Stok</th>
                                    <td>{{ $product->product_stock_alert }}</td>
                                </tr>
                                <tr>
                                    <th>Catatan</th>
                                    <td>{{ $product->product_note ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Section (Image) -->
            <div class="col-lg-3">
                <div class="card">
                    <div class="card-body">
                        @forelse($product->getMedia('images') as $media)
                            <img src="{{ $media->getUrl() }}" alt="Product Image" class="img-fluid img-thumbnail mb-2">
                        @empty
                            <img src="{{ $product->getFirstMediaUrl('images') }}" alt="Product Image"
                                 class="img-fluid img-thumbnail mb-2">
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="card mt-4">
            <div class="card-header">
                <h5>Riwayat Transaksi</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Jumlah Saat Ini</th>
                            <th>Lokasi</th>
                            <th>Alasan</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($transactions as $transaction)
                            <tr>
                                <td>{{ $transaction->formatted_created_at }}</td>
                                <td>{{ $transaction->type }}</td>
                                <td>{{ $transaction->quantity }}</td>
                                <td>{{ $transaction->current_quantity }}</td>
                                <td>{{ $transaction->location->name ?? 'N/A' }}</td>
                                <td>{{ $transaction->reason ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center">Transaksi tidak Ditemukan !</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- End Transaction History -->

        <!-- Product Stocks -->
        <div class="card mt-4">
            <div class="card-header">
                <h5>Stok Produk</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                        <tr>
                            <th>Lokasi</th>
                            <th>Jumlah</th>
                            <th>Jumlah Non Pajak</th>
                            <th>Jumlah Pajak</th>
                            <th>Jumlah Barang Rusak Non Pajak</th>
                            <th>Jumlah Barang Rusak Pajak</th>
                            <th>Harga Beli Terakhir</th>
                            <th>Harga Beli Rata-rata</th>
                            <th>Harga Jual</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($productStocks as $stock)
                            <tr>
                                <td>{{ $stock->location->name ?? 'N/A' }}</td>
                                <td>{{ $stock->quantity }}</td>
                                <td>{{ $stock->quantity_non_tax }}</td>
                                <td>{{ $stock->quantity_tax }}</td>
                                <td>{{ $stock->broken_quantity_non_tax }}</td>
                                <td>{{ $stock->broken_quantity_tax }}</td>
                                <td>{{ format_currency($stock->last_purchase_price) }}</td>
                                <td>{{ format_currency($stock->average_purchase_price) }}</td>
                                <td>{{ format_currency($stock->sale_price) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center">Stok Produk tidak Ditemukan!.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- End Product Stocks -->

        <!-- Serial Numbers -->
        <div class="card mt-4">
            <div class="card-header">
                <h5>Nomor Serial</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                        <tr>
                            <th>Nomor Serial</th>
                            <th>Lokasi</th>
                            <th>Pajak</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($serialNumbers as $serial)
                            <tr>
                                <td>{{ $serial->serial_number }}</td>
                                <td>{{ $serial->location->name ?? 'N/A' }}</td>
                                <td>{{ $serial->tax->name ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center">Nomor Serial Tidak Ditemukan !.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- End Serial Numbers -->
    </div>
@endsection
