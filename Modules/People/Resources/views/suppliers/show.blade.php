@extends('layouts.app')

@section('title', 'Supplier Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('suppliers.index') }}">Suppliers</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Supplier Name</th>
                                    <td>{{ $supplier->supplier_name }}</td>
                                </tr>
                                <tr>
                                    <th>Supplier Email</th>
                                    <td>{{ $supplier->supplier_email }}</td>
                                </tr>
                                <tr>
                                    <th>Supplier Phone</th>
                                    <td>{{ $supplier->supplier_phone }}</td>
                                </tr>
                                <tr>
                                    <th>Contact Name</th>
                                    <td>{{ $supplier->contact_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Identity</th>
                                    <td>{{ $supplier->identity ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Identity Number</th>
                                    <td>{{ $supplier->identity_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Fax</th>
                                    <td>{{ $supplier->fax ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>NPWP</th>
                                    <td>{{ $supplier->npwp ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>City</th>
                                    <td>{{ $supplier->city }}</td>
                                </tr>
                                <tr>
                                    <th>Country</th>
                                    <td>{{ $supplier->country }}</td>
                                </tr>
                                <tr>
                                    <th>Address</th>
                                    <td>{{ $supplier->address }}</td>
                                </tr>
                                <tr>
                                    <th>Billing Address</th>
                                    <td>{{ $supplier->billing_address ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Shipping Address</th>
                                    <td>{{ $supplier->shipping_address ?? '-' }}</td>
                                </tr>
                                <!-- Bank Information -->
                                <tr>
                                    <th>Bank Name</th>
                                    <td>{{ $supplier->bank_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Bank Branch</th>
                                    <td>{{ $supplier->bank_branch ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Account Number</th>
                                    <td>{{ $supplier->account_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Account Holder</th>
                                    <td>{{ $supplier->account_holder ?? '-' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
