@extends('layouts.app')

@section('title', 'Stock Transfer Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Stock Transfers</a></li>
        <li class="breadcrumb-item active">Stock Transfer Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Transfer Information</h5>

                        <!-- Transfer Info -->
                        <table class="table table-bordered">
                            <tr>
                                <th>Transfer Date:</th>
                                <td>{{ $transfer->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                            <tr>
                                <th>Origin Location:</th>
                                <td>
                                    {{ $transfer->originLocation->name ?? '-' }} <br>
                                    <small>{{ $transfer->originLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Destination Location:</th>
                                <td>
                                    {{ $transfer->destinationLocation->name ?? '-' }} <br>
                                    <small>{{ $transfer->destinationLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>{{ strtoupper($transfer->status) }}</td>
                            </tr>
                            <tr>
                                <th>Created By:</th>
                                <td>{{ $transfer->createdBy->name ?? '-' }}</td>
                            </tr>
                            @if ($transfer->approver)
                                <tr>
                                    <th>Approved By:</th>
                                    <td>{{ $transfer->approver->name }}</td>
                                </tr>
                            @endif
                            @if ($transfer->dispatcher)
                                <tr>
                                    <th>Dispatched By:</th>
                                    <td>{{ $transfer->dispatcher->name }}</td>
                                </tr>
                            @endif
                        </table>

                        <h5 class="card-title mt-4">Products</h5>

                        <!-- Products Table -->
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Product Name</th>
                                <th>Product Code</th>
                                <th>Quantity</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($transfer->products as $key => $product)
                                <tr>
                                    <td>{{ $key + 1 }}</td>
                                    <td>{{ $product->product->product_name }}</td>
                                    <td>{{ $product->product->product_code }}</td>
                                    <td>{{ $product->quantity }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <!-- Action Buttons: Approve, Reject, Dispatch, Receive -->
                        @if ($transfer->status === 'PENDING')
                            <div class="mt-4">
                                <form action="{{ route('transfers.approve', $transfer->id) }}" method="POST" style="display:inline-block;">
                                    @csrf
                                    @method('POST')
                                    <button type="submit" class="btn btn-success">Approve</button>
                                </form>

                                <form action="{{ route('transfers.reject', $transfer->id) }}" method="POST" style="display:inline-block;">
                                    @csrf
                                    @method('POST')
                                    <button type="submit" class="btn btn-danger">Reject</button>
                                </form>
                            </div>
                        @elseif ($transfer->status === 'APPROVED')
                            <div class="mt-4">
                                <form action="{{ route('transfers.dispatch', $transfer->id) }}" method="POST" style="display:inline-block;">
                                    @csrf
                                    @method('POST')
                                    <button type="submit" class="btn btn-primary">Dispatch</button>
                                </form>
                            </div>
                        @elseif ($transfer->status === 'DISPATCHED' && $transfer->originLocation->setting->id === $transfer->destinationLocation->setting->id)
                            <div class="mt-4">
                                <form action="{{ route('transfers.receive', $transfer->id) }}" method="POST" style="display:inline-block;">
                                    @csrf
                                    @method('POST')
                                    <button type="submit" class="btn btn-success">Receive</button>
                                </form>
                            </div>
                        @endif

                        <a href="{{ route('transfers.index') }}" class="btn btn-secondary mt-4">
                            Back to Transfers
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
