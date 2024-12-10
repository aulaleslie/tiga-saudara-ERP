@extends('layouts.app')

@section('title', 'Receive Purchase')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Purchases</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.show', $purchase->id) }}">Details</a></li>
        <li class="breadcrumb-item active">Receive</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5>Receive Purchase</h5>
                        <strong>Reference: {{ $purchase->reference }}</strong>
                    </div>
                    <div class="card-body">
                        <!-- Supplier and Invoice Info -->
                        <div class="row mb-4">
                            <div class="col-sm-6">
                                <h6>Supplier Info</h6>
                                <div><strong>{{ $purchase->supplier->supplier_name }}</strong></div>
                                <div>{{ $purchase->supplier->address }}</div>
                                <div>Email: {{ $purchase->supplier->supplier_email }}</div>
                                <div>Phone: {{ $purchase->supplier->supplier_phone }}</div>
                            </div>
                            <div class="col-sm-6">
                                <h6>Invoice Info</h6>
                                <div>Invoice: <strong>INV/{{ $purchase->reference }}</strong></div>
                                <div>Date: {{ \Carbon\Carbon::parse($purchase->date)->format('d M, Y') }}</div>
                                <div>Status: <strong>{{ $purchase->status }}</strong></div>
                            </div>
                        </div>

                        <!-- Receive Items -->
                        <form action="{{ route('purchases.storeReceive', $purchase->id) }}" method="POST">
                            @csrf
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>Product</th>
                                        <th>Quantity Ordered</th>
                                        <th>Quantity Received</th>
                                        <th>New Quantity to Receive</th>
                                        <th>Notes</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($purchase->purchaseDetails as $detail)
                                        <tr>
                                            <td>
                                                {{ $detail->product_name }}
                                                <br>
                                                <span class="badge badge-success">{{ $detail->product_code }}</span>
                                            </td>
                                            <td>{{ $detail->quantity }}</td>
                                            <td>{{ $detail->quantity_received ?? 0 }}</td>
                                            <td>
                                                <input type="number" name="received[{{ $detail->id }}]" class="form-control"
                                                       min="0" max="{{ $detail->quantity - ($detail->quantity_received ?? 0) }}"
                                                       value="0">
                                            </td>
                                            <td>
                                                <input type="text" name="notes[{{ $detail->id }}]" class="form-control" placeholder="Optional">
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <!-- Submit Button -->
                            <div class="text-right mt-3">
                                <button type="submit" class="btn btn-primary">Confirm Receipt</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
