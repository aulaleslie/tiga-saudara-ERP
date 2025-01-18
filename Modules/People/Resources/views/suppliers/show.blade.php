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
                                    <td>{{ $supplier->paymentTerm->name }}</td>
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
                        <div class="table-responsive">
                            <table id="purchases-table" class="table table-striped table-bordered">
                                <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Nama Pemasok</th>
                                    <th>Status</th>
                                    <th>Jumlah Total</th>
                                    <th>Jumlah yang Dibayar</th>
                                    <th>Jumlah Jatuh Tempo</th>
                                    <th>Status Pembayaran</th>
                                    <th>Aksi</th>
                                </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        $(document).ready(function () {
            $('#purchases-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route("datatable.purchases") }}',
                    data: function (d) {
                        d.supplier_id = '{{ $supplier->id }}'; // Add supplier_id to the request
                    }
                },
                columns: [
                    { data: 'reference', name: 'reference' },
                    { data: 'supplier_name', name: 'supplier_name' },
                    { data: 'status', name: 'status', orderable: false },
                    { data: 'total_amount', name: 'total_amount', orderable: false },
                    { data: 'paid_amount', name: 'paid_amount', orderable: false },
                    { data: 'due_amount', name: 'due_amount', orderable: false },
                    { data: 'payment_status', name: 'payment_status', orderable: false },
                    { data: 'action', name: 'action', orderable: false, searchable: false },
                ]
            });
        });
    </script>
@endpush
