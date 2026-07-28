@extends('layouts.app')

@section('title', 'Supplier Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('suppliers.index') }}">Suppliers</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <!-- Supplier Details -->
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Nama Kontak</th>
                                    <td>{{ $supplier->contact_name }}</td>
                                </tr>
                                <tr>
                                    <th>Nama Supplier</th>
                                    <td>{{ $supplier->supplier_name }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Kontak</th>
                                    <td>{{ $supplier->supplier_phone }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Penagihan</th>
                                    <td>{{ $supplier->billing_address }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Pengiriman</th>
                                    <td>{{ $supplier->shipping_address }}</td>
                                </tr>
                                <tr>
                                    <th>Syarat Pembayaran</th>
                                    <td>{{ $supplier->paymentTerm?->name ?? '-' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchases Table -->
        <div class="row mt-4">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="mb-3">Pembelian</h4>
                        <div class="table-responsive" style="min-height: 300px;">
                            <livewire:purchase.purchase-table :supplier-id="$supplier->id" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
