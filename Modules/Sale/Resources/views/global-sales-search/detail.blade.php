@extends('layouts.app')

@section('title', 'Detail Penjualan - ' . $sale->reference)

@section('content')
<div class="container-fluid">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="{{ route('home') }}">Dashboard</a>
            </li>
            <li class="breadcrumb-item">
                <a href="{{ route('global-sales-search.index') }}">Pencarian Penjualan Global</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                {{ $sale->reference }}
            </li>
        </ol>
    </nav>

    <!-- Sale Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">
                        <i class="bi bi-receipt me-2"></i>
                        Pesanan Penjualan: {{ $sale->reference }}
                    </h4>
                    <div>
                        <span class="badge badge-{{ $sale->status === 'DISPATCHED' ? 'success' : ($sale->status === 'APPROVED' ? 'warning' : 'secondary') }} fs-6">
                            {{ $sale->status }}
                        </span>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informasi Pesanan</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td class="font-weight-bold">Reference:</td>
                                    <td>{{ $sale->reference }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Date:</td>
                                    <td>{{ $sale->created_at->format('M d, Y H:i') }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Status:</td>
                                    <td>
                                        <span class="badge badge-{{ $sale->status === 'DISPATCHED' ? 'success' : ($sale->status === 'APPROVED' ? 'warning' : 'secondary') }}">
                                            {{ $sale->status }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Total Amount:</td>
                                    <td>{{ format_currency($sale->total_amount) }}</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h6>Informasi Pelanggan & Tenant</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td class="font-weight-bold">Customer:</td>
                                    <td>{{ $sale->customer->name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Tenant:</td>
                                    <td>{{ $sale->setting->name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Seller:</td>
                                    <td>{{ $sale->user->name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="font-weight-bold">Location:</td>
                                    <td>{{ $sale->location->name ?? 'N/A' }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Serial Numbers Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-upc-scan me-2"></i>
                        Nomor Seri ({{ $serialNumbers->count() }})
                    </h5>
                </div>

                <div class="card-body">
                    @if($serialNumbers->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Serial Number</th>
                                        <th>Product</th>
                                        <th>Status</th>
                                        <th>Location</th>
                                        <th>Allocated Date</th>
                                        <th>Dispatched Date</th>
                                        <th>Return Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($serialNumbers as $serial)
                                        <tr>
                                            <td>
                                                <code>{{ $serial->serial_number }}</code>
                                            </td>
                                            <td>
                                                {{ $serial->product->product_name ?? 'N/A' }}
                                                <br>
                                                <small class="text-muted">{{ $serial->product->product_code ?? '' }}</small>
                                            </td>
                                            <td>
                                                @php
                                                    $status = $this->getSerialStatus($serial, $sale);
                                                @endphp
                                                <span class="badge badge-{{ $status['class'] }}">
                                                    {{ $status['text'] }}
                                                </span>
                                            </td>
                                            <td>{{ $serial->location->name ?? 'N/A' }}</td>
                                            <td>
                                                @if($allocation = $this->getAllocationInfo($serial, $sale))
                                                    {{ $allocation->created_at->format('M d, Y') }}
                                                @else
                                                    N/A
                                                @endif
                                            </td>
                                            <td>
                                                @if($dispatch = $this->getDispatchInfo($serial, $sale))
                                                    {{ $dispatch->dispatch_date->format('M d, Y') }}
                                                @else
                                                    Not dispatched
                                                @endif
                                            </td>
                                            <td>
                                                @if($return = $this->getReturnInfo($serial, $sale))
                                                    {{ $return->return_date->format('M d, Y') }}
                                                @else
                                                    Not returned
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-4">
                            <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">Tidak Ada Nomor Seri Ditemukan</h5>
                            <p class="text-muted">Pesanan penjualan ini tidak memiliki produk yang diserialkan.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Sale Details Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>
                        Item Pesanan
                    </h5>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                    <th>Serial Numbers</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->details as $detail)
                                    <tr>
                                        <td>
                                            {{ $detail->product->product_name ?? 'N/A' }}
                                            <br>
                                            <small class="text-muted">{{ $detail->product->product_code ?? '' }}</small>
                                        </td>
                                        <td>{{ $detail->quantity }}</td>
                                        <td>{{ format_currency($detail->unit_price) }}</td>
                                        <td>{{ format_currency($detail->total) }}</td>
                                        <td>
                                            @if(is_array($detail->serial_number_ids) && count($detail->serial_number_ids) > 0)
                                                <span class="badge badge-info">{{ count($detail->serial_number_ids) }} serials</span>
                                                <button class="btn btn-sm btn-link p-0 ms-2"
                                                        onclick="showSerialsForDetail({{ $detail->id }})">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            @else
                                                <span class="text-muted">No serials</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    @if($sale->payments && $sale->payments->isNotEmpty())
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-cash me-2"></i>
                        Riwayat Pembayaran
                    </h5>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->payments as $payment)
                                    <tr>
                                        <td>{{ $payment->created_at->format('M d, Y H:i') }}</td>
                                        <td>{{ format_currency($payment->amount) }}</td>
                                        <td>{{ $payment->payment_method ?? 'N/A' }}</td>
                                        <td>{{ $payment->reference ?? 'N/A' }}</td>
                                        <td>{{ $payment->note ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Dispatch History -->
    @if($sale->dispatches && $sale->dispatches->isNotEmpty())
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-truck me-2"></i>
                        Riwayat Pengiriman
                    </h5>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Dispatch Date</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->dispatches as $dispatch)
                                    <tr>
                                        <td>{{ $dispatch->dispatch_date->format('M d, Y') }}</td>
                                        <td>{{ $dispatch->reference ?? 'N/A' }}</td>
                                        <td>
                                            <span class="badge badge-success">Dispatched</span>
                                        </td>
                                        <td>{{ $dispatch->notes ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Return Information -->
    @if($sale->returns && $sale->returns->isNotEmpty())
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-arrow-return-left me-2"></i>
                        Informasi Pengembalian
                    </h5>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return Date</th>
                                    <th>Reference</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->returns as $return)
                                    <tr>
                                        <td>{{ $return->created_at->format('M d, Y') }}</td>
                                        <td>{{ $return->reference ?? 'N/A' }}</td>
                                        <td>{{ $return->reason ?? 'N/A' }}</td>
                                        <td>
                                            <span class="badge badge-warning">Returned</span>
                                        </td>
                                        <td>{{ $return->notes ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@push('scripts')
<script>
function showSerialsForDetail(detailId) {
    // This would show serial numbers for a specific detail line
    console.log('Show serials for detail:', detailId);
    // You could open a modal or expand a section
}
</script>
@endpush
@endsection