@php use Illuminate\Support\Facades\Storage; @endphp
@extends('layouts.app')

@section('title', 'Detail Retur Pembelian')

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
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center">
                        <div>
                            <h4 class="mb-0">Retur Pembelian #{{ $purchase_return->reference }}</h4>
                            <div class="small text-muted">Dibuat pada {{ \Carbon\Carbon::parse($purchase_return->date)->translatedFormat('d F Y') }}</div>
                        </div>
                        <div class="ms-auto d-flex flex-wrap align-items-center">
                            <span class="badge bg-secondary text-uppercase me-2 mb-1">{{ $purchase_return->status }}</span>
                            <span class="badge {{ $purchase_return->approval_status === 'approved' ? 'bg-success' : ($purchase_return->approval_status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') }} text-uppercase me-2 mb-1">{{ $purchase_return->approval_status }}</span>
                            <a target="_blank" class="btn btn-outline-primary btn-sm d-print-none me-2 mb-1" href="{{ route('purchase-returns.pdf', $purchase_return->id) }}">
                                <i class="bi bi-printer"></i> Cetak
                            </a>
                            <a target="_blank" class="btn btn-outline-secondary btn-sm d-print-none mb-1" href="{{ route('purchase-returns.pdf', $purchase_return->id) }}">
                                <i class="bi bi-download"></i> Unduh PDF
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-4 mb-4">
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Perusahaan</h6>
                                    <p class="mb-1 fw-semibold">{{ settings()->company_name }}</p>
                                    <p class="mb-1">{{ settings()->company_address }}</p>
                                    <p class="mb-1">Email: {{ settings()->company_email }}</p>
                                    <p class="mb-0">Telepon: {{ settings()->company_phone }}</p>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Pemasok</h6>
                                    <p class="mb-1 fw-semibold">{{ $supplier->supplier_name }}</p>
                                    <p class="mb-1">{{ $supplier->address }}</p>
                                    <p class="mb-1">Email: {{ $supplier->supplier_email }}</p>
                                    <p class="mb-0">Telepon: {{ $supplier->supplier_phone }}</p>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Ringkasan Dokumen</h6>
                                    <dl class="row mb-0 small">
                                        <dt class="col-5 text-muted">Invoice</dt>
                                        <dd class="col-7 fw-semibold">INV/{{ $purchase_return->reference }}</dd>
                                        <dt class="col-5 text-muted">Lokasi</dt>
                                        <dd class="col-7 fw-semibold">{{ $purchase_return->location->name ?? '-' }}</dd>
                                        <dt class="col-5 text-muted">Metode</dt>
                                        <dd class="col-7 fw-semibold">{{ ucfirst($purchase_return->return_type ?? 'Tidak Ditentukan') }}</dd>
                                        <dt class="col-5 text-muted">Status Pembayaran</dt>
                                        <dd class="col-7 fw-semibold">{{ $purchase_return->payment_status }}</dd>
                                    </dl>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center">Harga Satuan</th>
                                        <th class="text-center">Jumlah</th>
                                        <th class="text-end">Diskon</th>
                                        <th class="text-end">Pajak</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($purchase_return->purchaseReturnDetails as $item)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $item->product_name }}</div>
                                                <small class="badge bg-success">{{ $item->product_code }}</small>
                                            </td>
                                            <td class="text-center">{{ format_currency($item->unit_price) }}</td>
                                            <td class="text-center">{{ $item->quantity }}</td>
                                            <td class="text-end">{{ format_currency($item->product_discount_amount) }}</td>
                                            <td class="text-end">{{ format_currency($item->product_tax_amount) }}</td>
                                            <td class="text-end fw-semibold">{{ format_currency($item->sub_total) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="row justify-content-end mt-4">
                            <div class="col-md-6 col-lg-4">
                                <div class="border rounded p-3 bg-light">
                                    <ul class="list-unstyled mb-0">
                                        <li class="d-flex justify-content-between py-1">
                                            <span>Diskon ({{ $purchase_return->discount_percentage }}%)</span>
                                            <span>{{ format_currency($purchase_return->discount_amount) }}</span>
                                        </li>
                                        <li class="d-flex justify-content-between py-1">
                                            <span>Pajak ({{ $purchase_return->tax_percentage }}%)</span>
                                            <span>{{ format_currency($purchase_return->tax_amount) }}</span>
                                        </li>
                                        <li class="d-flex justify-content-between py-1">
                                            <span>Biaya Pengiriman</span>
                                            <span>{{ format_currency($purchase_return->shipping_amount) }}</span>
                                        </li>
                                        <li class="d-flex justify-content-between py-2 border-top mt-2 fw-semibold">
                                            <span>Total</span>
                                            <span>{{ format_currency($purchase_return->total_amount) }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        @if($purchase_return->return_type === 'exchange' && $purchase_return->goods->isNotEmpty())
                            <div class="mt-4">
                                <h5 class="mb-3">Detail Penggantian Produk</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle">
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
                                                    <td>
                                                        <div class="fw-semibold">{{ $good->product_name }}</div>
                                                        <small class="text-muted">{{ $good->product_code }}</small>
                                                    </td>
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
                                <p class="mb-2">Total dikembalikan: <strong>{{ format_currency($purchase_return->total_amount) }}</strong></p>
                                @if($purchase_return->cash_proof_path)
                                    <a href="{{ Storage::url($purchase_return->cash_proof_path) }}" target="_blank" class="btn btn-outline-primary btn-sm">
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

