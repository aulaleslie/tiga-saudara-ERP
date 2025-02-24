@extends('layouts.app')

@section('title', 'Customer Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('customers.index') }}">Pelanggan</a></li>
        <li class="breadcrumb-item active">Rincian</li>
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
                                    <th>Tier</th>
                                    <td>{{ $customer->tier ?? 'Pelanggan Normal' }}</td>
                                </tr>
                                <tr>
                                    <th>Nama Pelanggan</th>
                                    <td>{{ $customer->contact_name }}</td>
                                </tr>
                                <tr>
                                    <th>Identitas</th>
                                    <td>{{ $customer->identity ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Identitas</th>
                                    <td>{{ $customer->identity_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Nama Perusahaan</th>
                                    <td>{{ $customer->customer_name }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Handphone</th>
                                    <td>{{ $customer->customer_phone?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>E-Mail</th>
                                    <td>{{ $customer->customer_email }}</td>
                                </tr>
                                <tr>
                                    <th>NPWP</th>
                                    <td>{{ $customer->npwp ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Penagihan</th>
                                    <td>{{ $customer->billing_address ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Pengiriman</th>
                                    <td>{{ $customer->shipping_address ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Syarat Pembayaran</th>
                                    <td>{{ $customer->paymentTerm->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Info Lainnya</th>
                                    <td>{{ $customer->additional_info ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Nama Bank</th>
                                    <td>{{ $customer->bank_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Kantor Cabang Bank</th>
                                    <td>{{ $customer->bank_branch ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Rekening</th>
                                    <td>{{ $customer->account_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Pemegang Akun Bank</th>
                                    <td>{{ $customer->account_holder ?? '-' }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="mt-3">
                            <a href="{{ route('customers.index') }}" class="btn btn-secondary">Kembali</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
