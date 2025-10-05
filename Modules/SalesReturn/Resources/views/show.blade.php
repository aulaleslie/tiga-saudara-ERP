@php($approvalStatus = strtolower($sale_return->approval_status ?? ''))
@php($status = strtolower($sale_return->status ?? ''))
@php use Illuminate\Support\Facades\Storage; @endphp
@extends('layouts.app')

@section('title', 'Sales Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sale-returns.index') }}">Sale Returns</a></li>
        <li class="breadcrumb-item active">Details</li>
    </ol>
@endsection

@can('saleReturns.approve')
    @if($approvalStatus === 'pending')
        @push('page_scripts')
            <script>
                function saleReturnReject{{ $sale_return->id }}() {
                    const reason = prompt('Masukkan alasan penolakan (opsional):');
                    if (reason !== null) {
                        const form = document.getElementById('sale-return-reject-form-{{ $sale_return->id }}');
                        form.querySelector('input[name="reason"]').value = reason;
                        form.submit();
                    }
                }
            </script>
        @endpush
    @endif
@endcan

@section('content')
    @php($customer = optional($sale_return->sale)->customer)
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center bg-white border-0">
                        <div>
                            <h4 class="mb-0">Retur Penjualan #{{ $sale_return->reference }}</h4>
                            <div class="small text-muted">Dibuat pada {{ \Carbon\Carbon::parse($sale_return->date)->translatedFormat('d F Y') }}</div>
                        </div>
                        <div class="ms-auto d-flex flex-wrap align-items-center">
                            <span class="me-2 mb-1">
                                @include('salesreturn::partials.status', ['data' => $sale_return])
                            </span>
                            <span class="me-2 mb-1">
                                @include('salesreturn::partials.approval-status', ['data' => $sale_return])
                            </span>
                            <span class="me-2 mb-1">
                                @include('salesreturn::partials.settlement-status', ['data' => $sale_return])
                            </span>

                            @can('saleReturns.edit')
                                @if(in_array($approvalStatus, ['pending', 'draft']))
                                    <a class="btn btn-primary btn-sm d-print-none me-2 mb-1" href="{{ route('sale-returns.edit', $sale_return) }}">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                @elseif($approvalStatus === 'approved' && ! $sale_return->settled_at)
                                    <a class="btn btn-success btn-sm d-print-none me-2 mb-1" href="{{ route('sale-returns.settlement', $sale_return) }}">
                                        <i class="bi bi-clipboard-check"></i> Penyelesaian
                                    </a>
                                @endif
                            @endcan

                            @can('saleReturns.approve')
                                @if($approvalStatus === 'pending')
                                    <form method="POST" action="{{ route('sale-returns.approve', $sale_return) }}" class="me-2 mb-1 d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm d-print-none" onclick="return confirm('Setujui retur penjualan ini?')">
                                            <i class="bi bi-check2-circle"></i> Setujui
                                        </button>
                                    </form>
                                    <form id="sale-return-reject-form-{{ $sale_return->id }}" method="POST" action="{{ route('sale-returns.reject', $sale_return) }}" class="d-none">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                    </form>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-print-none me-2 mb-1" onclick="saleReturnReject{{ $sale_return->id }}()">
                                        <i class="bi bi-x-circle"></i> Tolak
                                    </button>
                                @endif
                            @endcan

                            @can('saleReturns.receive')
                                @if($status === 'awaiting receiving')
                                    <form method="POST" action="{{ route('sale-returns.receive', $sale_return) }}" class="me-2 mb-1 d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-primary btn-sm d-print-none" onclick="return confirm('Terima barang retur ini ke stok?')">
                                            <i class="bi bi-box-arrow-in-down"></i> Terima Barang
                                        </button>
                                    </form>
                                @endif
                            @endcan

                            <a target="_blank" class="btn btn-outline-primary btn-sm d-print-none me-2 mb-1" href="{{ route('sale-returns.pdf', $sale_return->id) }}">
                                <i class="bi bi-printer"></i> Cetak
                            </a>
                            <a target="_blank" class="btn btn-outline-secondary btn-sm d-print-none mb-1" href="{{ route('sale-returns.pdf', $sale_return->id) }}">
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
                                    <h6 class="text-uppercase text-muted small mb-3">Pelanggan</h6>
                                    <p class="mb-1 fw-semibold">{{ $sale_return->customer_name }}</p>
                                    <p class="mb-1">{{ $customer->address ?? '-' }}</p>
                                    <p class="mb-1">Email: {{ $customer->customer_email ?? '-' }}</p>
                                    <p class="mb-0">Telepon: {{ $customer->customer_phone ?? '-' }}</p>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Ringkasan Dokumen</h6>
                                    <dl class="row mb-0 small">
                                        <dt class="col-5 text-muted">Invoice</dt>
                                        <dd class="col-7 fw-semibold">INV/{{ $sale_return->reference }}</dd>
                                        <dt class="col-5 text-muted">Referensi Penjualan</dt>
                                        <dd class="col-7 fw-semibold">{{ $sale_return->sale_reference ?? '-' }}</dd>
                                        <dt class="col-5 text-muted">Lokasi</dt>
                                        <dd class="col-7 fw-semibold">{{ optional($sale_return->location)->name ?? '-' }}</dd>
                                        <dt class="col-5 text-muted">Status Pembayaran</dt>
                                        <dd class="col-7 fw-semibold">{{ $sale_return->payment_status }}</dd>
                                        @if($sale_return->received_at)
                                            <dt class="col-5 text-muted">Diterima</dt>
                                            <dd class="col-7 fw-semibold">{{ $sale_return->received_at->translatedFormat('d F Y H:i') }} oleh {{ optional($sale_return->receivedBy)->name ?? '-' }}</dd>
                                        @endif
                                        @if($sale_return->settled_at)
                                            <dt class="col-5 text-muted">Penyelesaian</dt>
                                            <dd class="col-7 fw-semibold">{{ $sale_return->settled_at->translatedFormat('d F Y H:i') }} oleh {{ optional($sale_return->settledBy)->name ?? '-' }}</dd>
                                            <dt class="col-5 text-muted">Metode</dt>
                                            <dd class="col-7">{{ $sale_return->payment_method ?? '-' }} ({{ $sale_return->return_type ?? 'n/a' }})</dd>
                                            @if($sale_return->customerCredit)
                                                <dt class="col-5 text-muted">Kredit Pelanggan</dt>
                                                <dd class="col-7">{{ format_currency($sale_return->customerCredit->remaining_amount) }} tersisa dari {{ format_currency($sale_return->customerCredit->amount) }}</dd>
                                            @endif
                                            @if($sale_return->cash_proof_path)
                                                <dt class="col-5 text-muted">Bukti Pembayaran</dt>
                                                <dd class="col-7"><a href="{{ Storage::url($sale_return->cash_proof_path) }}" target="_blank" class="text-decoration-none"><i class="bi bi-paperclip"></i> Lihat Bukti</a></dd>
                                            @endif
                                        @endif
                                        @if($sale_return->rejection_reason)
                                            <dt class="col-5 text-muted">Alasan Penolakan</dt>
                                            <dd class="col-7">{{ $sale_return->rejection_reason }}</dd>
                                        @endif
                                    </dl>
                                </div>
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
                                @foreach($sale_return->saleReturnDetails as $item)
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
                                        <td class="left"><strong>Discount ({{ $sale_return->discount_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($sale_return->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Tax ({{ $sale_return->tax_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($sale_return->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Shipping)</strong></td>
                                        <td class="right">{{ format_currency($sale_return->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Grand Total</strong></td>
                                        <td class="right"><strong>{{ format_currency($sale_return->total_amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

