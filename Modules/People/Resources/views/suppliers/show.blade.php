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
                                    <th>Nama Pemasok</th>
                                    <td>{{ $supplier->contact_name }}</td>
                                </tr>
                                <tr>
                                    <th>Identitas</th>
                                    <td>{{ $supplier->identity }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Identitas</th>
                                    <td>{{ $supplier->identity_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Supplier Phone</th>
                                    <td>{{ $supplier->supplier_phone }}</td>
                                </tr>

                                <tr>
                                    <th>Nama Perusahaan</th>
                                    <td>{{ $supplier->supplier_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>NPWP</th>
                                    <td>{{ $supplier->npwp ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Penagihan</th>
                                    <td>{{ $supplier->billing_address }}</td>
                                </tr>
                                <tr>
                                    <th>Alamat Pengiriman</th>
                                    <td>{{ $supplier->shipping_address }}</td>
                                </tr>
                                <!-- Bank Information -->
                                <tr>
                                    <th>Nama Bank</th>
                                    <td>{{ $supplier->bank_name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Kantor Cabang Bank</th>
                                    <td>{{ $supplier->bank_branch ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Nomor Rekening</th>
                                    <td>{{ $supplier->account_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Pemegang Akun Bank</th>
                                    <td>{{ $supplier->account_holder ?? '-' }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="mt-3">
                            <a href="{{ route('suppliers.index') }}" class="btn btn-secondary">Kembali</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
