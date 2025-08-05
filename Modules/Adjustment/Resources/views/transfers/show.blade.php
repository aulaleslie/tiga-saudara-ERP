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
                        <table class="table table-bordered mb-4">
                            <tr>
                                <th>Transfer Date:</th>
                                <td>{{ $transfer->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                            <tr>
                                <th>Origin Location:</th>
                                <td>
                                    {{ $transfer->originLocation->name ?? '-' }}<br>
                                    <small>{{ $transfer->originLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Destination Location:</th>
                                <td>
                                    {{ $transfer->destinationLocation->name ?? '-' }}<br>
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
                            @if($transfer->approver)
                                <tr>
                                    <th>Approved By:</th>
                                    <td>{{ $transfer->approver->name }}</td>
                                </tr>
                            @endif
                            @if($transfer->dispatcher)
                                <tr>
                                    <th>Dispatched By:</th>
                                    <td>{{ $transfer->dispatcher->name }}</td>
                                </tr>
                            @endif
                            @if($transfer->receiver)
                                <tr>
                                    <th>Received By:</th>
                                    <td>{{ $transfer->receiver->name }}</td>
                                </tr>
                            @endif
                        </table>

                        <h5 class="card-title">Products</h5>
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Code</th>
                                <th class="text-center">Tax</th>
                                <th class="text-center">Non-Tax</th>
                                <th class="text-center">Broken Tax</th>
                                <th class="text-center">Broken Non-Tax</th>
                                <th class="text-center">Total</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($transfer->products as $i => $item)
                                @php
                                    $qt  = $item->quantity_tax;
                                    $qn  = $item->quantity_non_tax;
                                    $bqt = $item->quantity_broken_tax;
                                    $bqn = $item->quantity_broken_non_tax;
                                    $total = $qt + $qn + $bqt + $bqn;
                                @endphp
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $item->product->product_name }}</td>
                                    <td>{{ $item->product->product_code }}</td>
                                    <td class="text-center">{{ $qt }}</td>
                                    <td class="text-center">{{ $qn }}</td>
                                    <td class="text-center">{{ $bqt }}</td>
                                    <td class="text-center">{{ $bqn }}</td>
                                    <td class="text-center">{{ $total }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <div class="mt-4">
                            {{-- Approve/Reject: only DESTINATION on PENDING --}}
                            @if($transfer->status === 'PENDING' && $isDestination)
                                @canany(['stockTransfers.edit','stockTransfers.approval'])
                                    <form action="{{ route('transfers.approve', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Approve</button>
                                    </form>
                                    <form action="{{ route('transfers.reject', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-danger">Reject</button>
                                    </form>
                                @endcanany

                                {{-- Dispatch: only ORIGIN on APPROVED --}}
                            @elseif($transfer->status === 'APPROVED' && $isOrigin)
                                @can('stockTransfers.dispatch')
                                    <form action="{{ route('transfers.dispatch', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-primary">Dispatch</button>
                                    </form>
                                @endcan

                                {{-- Receive: only DESTINATION on DISPATCHED --}}
                            @elseif($transfer->status === 'DISPATCHED' && $isDestination)
                                @can('stockTransfers.receive')
                                    <form action="{{ route('transfers.receive', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Receive</button>
                                    </form>
                                @endcan
                            @endif

                            <a href="{{ route('transfers.index') }}" class="btn btn-secondary ml-2">
                                Back to Transfers
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
