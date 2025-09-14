@php use Modules\Setting\Entities\Tax; @endphp
@extends('layouts.app')

@section('title', 'Product Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item active">Informasi</li>
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
                            @php
                                // Prefer per-setting prices from $price; fall back to legacy fields on $product
                                $salePrice   = $price->sale_price ?? $product->sale_price;
                                $tier1Price  = $price->tier_1_price ?? $product->tier_1_price;
                                $tier2Price  = $price->tier_2_price ?? $product->tier_2_price;

                                $lastBuy     = $price->last_purchase_price
                                                ?? ($product->last_purchase_price ?? $product->purchase_price);
                                $avgBuy      = $price->average_purchase_price
                                                ?? ($product->average_purchase_price ?? $product->purchase_price);

                                // Taxes now come from product_prices (IDs); quick lookup for names (single page -> 2 queries OK)
                                $purchaseTaxName = $price->purchase_tax_id
                                    ? Tax::find($price->purchase_tax_id)?->name
                                    : null;

                                $saleTaxName = $price->sale_tax_id
                                    ? Tax::find($price->sale_tax_id)?->name
                                    : null;
                            @endphp
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
                                    <th>Nama</th>
                                    <td>{{ $product->product_name }}</td>
                                </tr>
                                <tr>
                                    <th>Kategori</th>
                                    <td>{{ $product->category->category_name ?? 'N/A' }}</td>
                                </tr>
                                <!-- Replace "Harga Beli" with "Harga Beli Terakhir" and "Harga Beli Rata Rata" -->
                                <tr>
                                    <th>Harga Beli Terakhir</th>
                                    <td>{{ format_currency($lastBuy ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Beli Rata Rata</th>
                                    <td>{{ format_currency($avgBuy ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <th>Pajak Beli</th>
                                    <td>{{ $purchaseTaxName ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Jual</th>
                                    <td>{{ format_currency($salePrice ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Jual Partai Besar</th>
                                    <td>{{ format_currency($tier1Price ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Jual Reseller</th>
                                    <td>{{ format_currency($tier2Price ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <th>Pajak Jual</th>
                                    <td>{{ $saleTaxName ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Nilai Stok</th>
                                    <td>
                                        HARGA BELI: {{ format_currency(($avgBuy ?? 0) * ($product->product_quantity ?? 0)) }} /
                                        HARGA JUAL: {{ format_currency(($salePrice ?? 0) * ($product->product_quantity ?? 0)) }}
                                    </td>
                                </tr>
                                <tr>
                                    <th>Peringatan Kuantitas</th>
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
                <h5>Transaction History</h5>
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
                                <td colspan="6" class="text-center">No transactions found.</td>
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
                <h5>Product Stocks</h5>
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
                            <!-- Removed columns: Harga Beli Terakhir, Harga Beli Rata-rata, Harga Jual -->
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
                                <!-- Removed data for the columns we deleted -->
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center">No product stocks found.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- End Product Stocks -->

        <!-- Product Bundles -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Paket Penjualan</h5>
                @can('products.bundle.create')
                    <a href="{{ route('products.bundle.create', $product->id) }}" class="btn btn-secondary btn-sm">Tambah
                        Paket</a>
                @endcan
            </div>
            <div class="card-body">
                @if($bundles->count())
                    @foreach($bundles as $bundle)
                        <div class="mb-4">
                            <h6>{{ $bundle->name }} <span
                                    class="text-muted">({{ format_currency($bundle->price) }})</span></h6>
                            @if($bundle->description)
                                <p>{{ $bundle->description }}</p>
                            @endif
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th>Jumlah</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($bundle->items as $item)
                                        <tr>
                                            <td>{{ $item->product->product_name }}</td>
                                            <td>{{ $item->quantity }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @can('products.bundle.edit')
                                <a href="{{ route('products.bundle.edit', [$product->id, $bundle->id]) }}"
                                   class="btn btn-info btn-sm">Ubah Paket</a>
                                <form action="{{ route('products.bundle.destroy', [$product->id, $bundle->id]) }}"
                                      method="POST" style="display:inline;">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Are you sure?');">
                                        Hapus Paket
                                    </button>
                                </form>
                            @endcan
                        </div>
                    @endforeach
                @else
                    <p>Belum ada Paket Penjualan untuk produk ini.</p>
                @endif
            </div>
        </div>
        <!-- End Product Bundles -->

        <!-- Serial Numbers -->
        @if ($product->serial_number_required)
            <div class="card mt-4">
                <div class="card-header">
                    <h5>Serial Numbers</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                            <tr>
                                <th>Serial Number</th>
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
                                    <td colspan="3" class="text-center">No serial numbers found.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                        <div class="mt-3">
                            <a href="{{ route('products.index') }}" class="btn btn-secondary">Kembali</a>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        <!-- End Serial Numbers -->
    </div>
@endsection
