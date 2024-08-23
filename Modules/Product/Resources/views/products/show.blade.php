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
            <!-- Left Section -->
            <div class="col-lg-9 d-flex flex-column">
                <div class="card flex-grow-1">
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
                                    <th>Cost</th>
                                    <td>{{ format_currency($product->product_cost) }}</td>
                                </tr>
                                <tr>
                                    <th>Price</th>
                                    <td>{{ format_currency($product->product_price) }}</td>
                                </tr>
                                <tr>
                                    <th>Quantity</th>
                                    <td>{{ $displayQuantity }}</td>
                                </tr>
                                <tr>
                                    <th>Stock Worth</th>
                                    <td>
                                        COST: {{ format_currency($product->product_cost * $product->product_quantity) }} /
                                        PRICE: {{ format_currency($product->product_price * $product->product_quantity) }}
                                    </td>
                                </tr>
                                <tr>
                                    <th>Alert Quantity</th>
                                    <td>{{ $product->product_stock_alert }}</td>
                                </tr>
                                <tr>
                                    <th>Tax (%)</th>
                                    <td>{{ $product->product_order_tax ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Tax Type</th>
                                    <td>
                                        @if($product->product_tax_type == 1)
                                            Exclusive
                                        @elseif($product->product_tax_type == 2)
                                            Inclusive
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Note</th>
                                    <td>{{ $product->product_note ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Transaction History -->
                <div class="card mt-4 flex-grow-1">
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
                                @forelse($product->transactions as $transaction)
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

            <!-- Right Section (Image) -->
            <div class="col-lg-3 d-flex">
                <div class="card flex-grow-1">
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
    </div>
@endsection
