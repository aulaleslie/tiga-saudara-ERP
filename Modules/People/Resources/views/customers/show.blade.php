@extends('layouts.app')

@section('title', 'Customer Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('customers.index') }}">Customers</a></li>
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
                                    <th>Contact Name</th>
                                    <td>{{ $customer->contact_name }}</td>
                                </tr>
                                <tr>
                                    <th>Identity</th>
                                    <td>{{ $customer->identity ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Identity Number</th>
                                    <td>{{ $customer->identity_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Company Name</th>
                                    <td>{{ $customer->customer_name }}</td>
                                </tr>
                                <tr>
                                    <th>Customer Email</th>
                                    <td>{{ $customer->customer_email ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Customer Phone</th>
                                    <td>{{ $customer->customer_phone }}</td>
                                </tr>
                                <tr>
                                    <th>Telephone</th>
                                    <td>{{ $customer->telephone ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Fax</th>
                                    <td>{{ $customer->fax ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>NPWP</th>
                                    <td>{{ $customer->npwp ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Billing Address</th>
                                    <td>{{ $customer->billing_address ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Shipping Address</th>
                                    <td>{{ $customer->shipping_address ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Additional Info</th>
                                    <td>{{ $customer->additional_info ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Bank Name</th>
                                    <td>{{ $customer->bank_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Bank Branch</th>
                                    <td>{{ $customer->bank_branch ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Account Number</th>
                                    <td>{{ $customer->account_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Account Holder</th>
                                    <td>{{ $customer->account_holder ?? '-' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
