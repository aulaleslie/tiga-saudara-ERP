@extends('layouts.app')

@section('title', 'Adjustment Details')

@push('page_css')
    @livewireStyles
@endpush

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('adjustments.index') }}">Adjustments</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-12">
                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
                    Kembali
                </a>

                @if($adjustment->status === 'pending')
                    @can('approve_adjustments')
                        <form action="{{ route('adjustments.approve', $adjustment) }}" method="POST"
                              class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success">
                                Approve
                            </button>
                        </form>
                    @endcan

                    @can('reject_adjustments')
                        <form action="{{ route('adjustments.reject', $adjustment) }}" method="POST"
                              class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-danger">
                                Reject
                            </button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>

        <!-- Header Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Date</th>
                                    <td>{{ $adjustment->date }}</td>
                                    <th>Reference</th>
                                    <td>{{ $adjustment->reference }}</td>
                                </tr>
                                <tr>
                                    <th>Adjustment Type</th>
                                    <td colspan="3">
                                        {{ strtoupper($adjustment->type) }} <!-- Assuming type is 'breakage' or 'normal' -->
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Code</th>
                                    <th>Quantity</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($adjustment->adjustedProducts as $adjustedProduct)
                                    <tr>
                                        <td>{{ $adjustedProduct->product->product_name }}</td>
                                        <td>{{ $adjustedProduct->product->product_code }}</td>
                                        <td>{{ $adjustedProduct->quantity }}</td>

                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
