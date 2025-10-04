@php use Illuminate\Support\Facades\Storage; @endphp
@extends('layouts.app')

@section('title', 'Purchase Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchase-returns.index') }}">Purchase Returns</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center">
                        <div>
                            Reference: <strong>{{ $purchase_return->reference }}</strong>
                        </div>
                        <a target="_blank" class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none" href="{{ route('purchase-returns.pdf', $purchase_return->id) }}">
                            <i class="bi bi-printer"></i> Print
                        </a>
                        <a target="_blank" class="btn btn-sm btn-info mfe-1 d-print-none" href="{{ route('purchase-returns.pdf', $purchase_return->id) }}">
                            <i class="bi bi-save"></i> Save
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Company Info:</h5>
                                <div><strong>{{ settings()->company_name }}</strong></div>
                                <div>{{ settings()->company_address }}</div>
                                <div>Email: {{ settings()->company_email }}</div>
                                <div>Phone: {{ settings()->company_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Supplier Info:</h5>
                                <div><strong>{{ $supplier->supplier_name }}</strong></div>
                                <div>{{ $supplier->address }}</div>
                                <div>Email: {{ $supplier->supplier_email }}</div>
                                <div>Phone: {{ $supplier->supplier_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Invoice Info:</h5>
                                <div>Invoice: <strong>INV/{{ $purchase_return->reference }}</strong></div>
                                <div>Tanggal: {{ \Carbon\Carbon::parse($purchase_return->date)->format('d M, Y') }}</div>
                                <div>Lokasi: <strong>{{ $purchase_return->location->name ?? '-' }}</strong></div>
                                <div>Status Dokumen: <span class="badge bg-secondary text-uppercase">{{ $purchase_return->status }}</span></div>
                                <div>Status Persetujuan: <span class="badge {{ $purchase_return->approval_status === 'approved' ? 'bg-success' : ($purchase_return->approval_status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') }} text-uppercase">{{ $purchase_return->approval_status }}</span></div>
                                <div>Metode Penyelesaian: <strong>{{ ucfirst($purchase_return->return_type ?? 'Tidak Ditentukan') }}</strong></div>
                                <div>Payment Status: <strong>{{ $purchase_return->payment_status }}</strong></div>
                            </div>

                        </div>

                        <div class="table-responsive-sm">
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Product</th>
                                    <th class="align-middle">Net Unit Price</th>
                                    <th class="align-middle">Quantity</th>
                                    <th class="align-middle">Discount</th>
                                    <th class="align-middle">Tax</th>
                                    <th class="align-middle">Sub Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase_return->purchaseReturnDetails as $item)
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
                                        <td class="left"><strong>Discount ({{ $purchase_return->discount_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Tax ({{ $purchase_return->tax_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Shipping)</strong></td>
                                        <td class="right">{{ format_currency($purchase_return->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Grand Total</strong></td>
                                        <td class="right"><strong>{{ format_currency($purchase_return->total_amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        @if($purchase_return->return_type === 'exchange' && $purchase_return->goods->isNotEmpty())
                            <div class="mt-4">
                                <h5 class="mb-3">Produk Pengganti</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                        <tr class="text-center">
                                            <th>Produk</th>
                                            <th>Jumlah</th>
                                            <th>Nilai Satuan</th>
                                            <th>Subtotal</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($purchase_return->goods as $good)
                                            <tr>
                                                <td>{{ $good->product_name }} <br><small class="text-muted">{{ $good->product_code }}</small></td>
                                                <td class="text-center">{{ $good->quantity }}</td>
                                                <td class="text-end">{{ format_currency($good->unit_value) }}</td>
                                                <td class="text-end">{{ format_currency($good->sub_total) }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        @if($purchase_return->return_type === 'deposit' && $purchase_return->supplierCredit)
                            <div class="alert alert-info mt-4" role="alert">
                                Kredit pemasok sebesar <strong>{{ format_currency($purchase_return->supplierCredit->amount) }}</strong> telah dibuat.
                                Sisa kredit: <strong>{{ format_currency($purchase_return->supplierCredit->remaining_amount) }}</strong> (Status: {{ ucfirst($purchase_return->supplierCredit->status) }}).
                            </div>
                        @endif

                        @if($purchase_return->return_type === 'cash')
                            <div class="mt-4">
                                <h5 class="mb-3">Pengembalian Tunai</h5>
                                <p>Total dikembalikan: <strong>{{ format_currency($purchase_return->total_amount) }}</strong></p>
                                @if($purchase_return->cash_proof_path)
                                    <a href="{{ Storage::url($purchase_return->cash_proof_path) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-paperclip"></i> Lihat Bukti Pengembalian
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

