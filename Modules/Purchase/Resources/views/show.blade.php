@php use Modules\Purchase\Entities\Purchase; @endphp
@extends('layouts.app')

@section('title', 'Purchases Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Rincian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center">
                        <div>
                            Referensi: <strong>{{ $purchase->reference }}</strong>
                        </div>
                        <a target="_blank" class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none"
                           href="{{ route('purchases.pdf', $purchase->id) }}">
                            <i class="bi bi-printer"></i> Print
                        </a>
                        <a target="_blank" class="btn btn-sm btn-info mfe-1 d-print-none"
                           href="{{ route('purchases.pdf', $purchase->id) }}">
                            <i class="bi bi-save"></i> Simpan
                        </a>
                        <a class="btn btn-sm btn-info mfe-1 d-print-none"
                           href="{{ route('purchases.index') }}">
                            <i class="bi bi-back"></i> Kembali
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                                <div><strong>{{ settings()->company_name }}</strong></div>
                                <div>{{ settings()->company_address }}</div>
                                <div>Email: {{ settings()->company_email }}</div>
                                <div>Kontak: {{ settings()->company_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Pemasok:</h5>
                                <div><strong>{{ $supplier->supplier_name }}</strong></div>
                                <div>{{ $supplier->address }}</div>
                                <div>Email: {{ $supplier->supplier_email }}</div>
                                <div>Kontak: {{ $supplier->supplier_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
                                <div>Faktur: <strong>INV/{{ $purchase->reference }}</strong></div>
                                <div>Tanggal: {{ \Carbon\Carbon::parse($purchase->date)->format('d M, Y') }}</div>
                                <div>
                                    Status: <strong>{{ $purchase->status }}</strong>
                                </div>
                                <div>
                                    Status Pembayaran: <strong>{{ $purchase->payment_status }}</strong>
                                </div>
                            </div>

                        </div>

                        <div class="table-responsive-sm">
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Produk</th>
                                    <th class="align-middle">Harga Satuan</th>
                                    <th class="align-middle">Kuantitas</th>
                                    <th class="align-middle">Diskon</th>
                                    <th class="align-middle">Pajak</th>
                                    <th class="align-middle">Jumlah Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase->purchaseDetails as $item)
                                    <tr>
                                        <td class="align-middle">
                                            {{ $item->product_name }} <br>
                                            <span class="badge badge-success">
                                                {{ $item->product_code }}
                                            </span>
                                        </td>

                                        <td class="align-middle">{{ format_currency($item->unit_price) }}</td>

                                        <td class="align-middle">
                                            {{ $item->quantity }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_discount_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_tax_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->sub_total) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="row">
                            <div class="col-lg-4 col-sm-5 ml-md-auto">
                                <table class="table">
                                    <tbody>
                                    <tr>
                                        <td class="left"><strong>Diskon ({{ $purchase->discount_percentage }}
                                                %)</strong></td>
                                        <td class="right">{{ format_currency($purchase->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Pajak ({{ $purchase->tax_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Pengiriman)</strong></td>
                                        <td class="right">{{ format_currency($purchase->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Total Keseluruhan</strong></td>
                                        <td class="right">
                                            <strong>{{ format_currency($purchase->total_amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-sm-12">
                                <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                                <p>{{ $purchase->note ?? 'Tidak ada catatan.' }}</p>
                            </div>
                        </div>

                        <!-- Payments Table -->
                        <div class="row mt-4">
                            <div class="col-lg-12">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="mb-3">Pembayaran</h4>
                                        <div class="table-responsive">
                                            <table id="payments-table" class="table table-striped table-bordered">
                                                <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Referensi</th>
                                                    <th>Jumlah Pembayaran</th>
                                                    <th>Metode Pembayaran</th>
                                                    <th>Lampiran</th>
                                                    <th>Aksi</th>
                                                </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer text-end">
                            @if ($purchase->status === Purchase::STATUS_DRAFTED)
                                <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ Purchase::STATUS_WAITING_APPROVAL }}">
                                    <button type="submit" class="btn btn-warning">Kirim untuk Persetujuan</button>
                                </form>
                            @endif

                            @if ($purchase->status === Purchase::STATUS_WAITING_APPROVAL)
                                <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ Purchase::STATUS_APPROVED }}">
                                    <button type="submit" class="btn btn-success">Setuju</button>
                                </form>
                                <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ Purchase::STATUS_REJECTED }}">
                                    <button type="submit" class="btn btn-danger">Tolak</button>
                                </form>
                            @endif

                            @if ($purchase->status === Purchase::STATUS_APPROVED || $purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY)
                                <a href="{{ route('purchases.receive', $purchase->id) }}" class="btn btn-primary">
                                    Menerima
                                </a>
                            @endif
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
            $('#payments-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route("datatable.purchase_payments", ":purchase_id") }}'.replace(':purchase_id', '{{ $purchase->id }}'),
                },
                columns: [
                    { data: 'date', name: 'date', title: 'Tanggal' },
                    { data: 'reference', name: 'reference', title: 'Referensi' },
                    { data: 'amount', name: 'amount', title: 'Jumlah Pembayaran' },
                    { data: 'payment_method', name: 'payment_method', title: 'Metode Pembayaran' },
                    {
                        data: 'attachment',
                        name: 'attachment',
                        title: 'Lampiran',
                        render: function(data) {
                            return data ? data : 'Tidak ada';
                        }
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false,
                        title: 'Aksi'
                    },
                ]
            });
        });
    </script>
@endpush

