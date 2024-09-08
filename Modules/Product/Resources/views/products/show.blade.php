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
                                    <th>Product Code</th>
                                    <td>{{ $product->product_code }}</td>
                                </tr>
                                <tr>
                                    <th>Barcode Symbology</th>
                                    <td>{{ $product->product_barcode_symbology }}</td>
                                </tr>
                                <tr>
                                    <th>Name</th>
                                    <td>{{ $product->product_name }}</td>
                                </tr>
                                <tr>
                                    <th>Category</th>
                                    <td>{{ $product->category->category_name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Harga Beli</th>
                                    <td>{{ format_currency($product->purchase_price) }}</td>
                                </tr>
                                <tr>
                                    <th>Pajak Beli</th>
                                    <td>
                                        @if($product->purchase_tax == 1)
                                            PPN 11%
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
                                        @if($product->sale_tax == 1)
                                            PPN 11%
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Quantity</th>
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
                                    <th>Alert Quantity</th>
                                    <td>{{ $product->product_stock_alert }}</td>
                                </tr>
                                <tr>
                                    <th>Note</th>
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
                            <th>Date</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Current Quantity</th>
                            <th>Location</th>
                            <th>Reason</th>
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
    </div>
@endsection
