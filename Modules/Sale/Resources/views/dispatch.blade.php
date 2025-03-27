@extends('layouts.app')

@section('content')
    <div class="container">
        <h2>Dispatch Sale #{{ $sale->id }}</h2>

        <!-- Display sale summary -->
        <div class="card mb-3">
            <div class="card-header">Sale Details</div>
            <div class="card-body">
                <p><strong>Customer:</strong> {{ $sale->customer_name }}</p>
                <p><strong>Date:</strong> {{ $sale->date }}</p>
                <p><strong>Total Amount:</strong> {{ number_format($sale->total_amount, 2) }}</p>
            </div>
        </div>

        <!-- Show validation errors -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Dispatch form -->
        <form action="{{ route('sales.storeDispatch', $sale->id) }}" method="POST">
            @csrf

            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Total Quantity</th>
                    <th>Already Dispatched</th>
                    <th>Quantity to Dispatch</th>
                </tr>
                </thead>
                <tbody>
                @foreach($aggregatedProducts as $product)
                    <tr>
                        <td>{{ $product['product_name'] }}</td>
                        <td>{{ $product['total_quantity'] }}</td>
                        <td>{{ $product['dispatched_quantity'] }}</td>
                        <td>
                            <input type="number"
                                   name="dispatched_quantities[{{ $product['product_id'] }}]"
                                   value="0"
                                   min="0"
                                   max="{{ $product['total_quantity'] - $product['dispatched_quantity'] }}"
                                   class="form-control">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <!-- Additional dispatch fields -->
            <div class="form-group">
                <label for="location">Select Dispatch Location</label>
                <select name="location_id" id="location" class="form-control" required>
                    <option value="">-- Choose a Location --</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Optionally, add a dispatch date field -->
            <div class="form-group">
                <label for="dispatch_date">Dispatch Date</label>
                <input type="datetime-local" name="dispatch_date" id="dispatch_date" class="form-control" value="{{ now()->format('Y-m-d\TH:i') }}">
            </div>

            <button type="submit" class="btn btn-success mt-3">Dispatch</button>
        </form>
    </div>
@endsection
