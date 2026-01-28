@php use Illuminate\Support\Facades\Storage; @endphp
@php $approvalStatus = strtolower($purchase_return->approval_status ?? ''); @endphp
@php $dispatchStatus = strtolower($purchase_return->return_dispatch_status ?? ''); @endphp
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
        @include('utils.alerts')
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center">
                        <div>
                            <h4 class="mb-0">Retur Pembelian #{{ $purchase_return->reference }}</h4>
                            <div class="small text-muted">Dibuat pada {{ \Carbon\Carbon::parse($purchase_return->date)->translatedFormat('d F Y') }}</div>
                        </div>
                        <div class="ml-auto d-flex flex-wrap align-items-center">
                            <span class="badge bg-secondary text-uppercase mr-2 mb-1">{{ $purchase_return->status }}</span>
                            <span class="badge {{ $approvalStatus === 'approved' ? 'bg-success' : ($approvalStatus === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') }} text-uppercase mr-2 mb-1">{{ $purchase_return->approval_status }}</span>
                            @if($dispatchStatus !== 'dispatched')
                                @can('purchaseReturns.edit')
                                    <a class="btn btn-primary btn-sm d-print-none mr-2 mb-1" href="{{ route('purchase-returns.edit', $purchase_return) }}">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                @endcan
                            @endif
                            @if($approvalStatus === 'pending')
                                @can('purchaseReturns.approval')
                                    <button type="button" class="btn btn-success btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#approvePurchaseReturnModal" data-bs-toggle="modal" data-bs-target="#approvePurchaseReturnModal">
                                        <i class="bi bi-check2-circle"></i> Setujui
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#rejectPurchaseReturnModal" data-bs-toggle="modal" data-bs-target="#rejectPurchaseReturnModal">
                                        <i class="bi bi-x-circle"></i> Tolak
                                    </button>
                                @endcan
                            @endif
                            @if($approvalStatus === 'approved')
                                @if(!$purchase_return->return_dispatched_at)
                                    @if($dispatchStatus === '' || $dispatchStatus === 'rejected')
                                        @can('purchaseReturns.dispatchRequest')
                                            <button type="button" class="btn btn-warning btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#dispatchRequestModal" data-bs-toggle="modal" data-bs-target="#dispatchRequestModal">
                                                <i class="bi bi-truck"></i> Ajukan Pengiriman Retur
                                            </button>
                                        @endcan
                                        @if($dispatchStatus === 'rejected')
                                            <span class="badge bg-danger mr-2 mb-1">Pengiriman Retur Ditolak</span>
                                        @endif
                                    @elseif($dispatchStatus === 'pending_approval')
                                        <span class="badge bg-warning text-dark mr-2 mb-1">Sedang Menunggu Persetujuan</span>
                                        @can('purchaseReturns.dispatchApproval')
                                            <button type="button" class="btn btn-success btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#approveDispatchModal" data-bs-toggle="modal" data-bs-target="#approveDispatchModal">
                                                <i class="bi bi-check-circle"></i> Setujui Pengiriman Retur
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#rejectDispatchModal" data-bs-toggle="modal" data-bs-target="#rejectDispatchModal">
                                                <i class="bi bi-x-circle"></i> Tolak Pengiriman Retur
                                            </button>
                                        @endcan
                                    @elseif($dispatchStatus === 'dispatched')
                                        <span class="badge bg-success mr-2 mb-1">Pengiriman Retur Disetujui</span>
                                    @endif
                                @else
                                    <span class="badge bg-info text-dark mr-2 mb-1">Telah Dikirim: {{ $purchase_return->return_dispatched_at->format('d M Y') }}</span>
                                @endif

                                @if($dispatchStatus === 'dispatched')
                                    @php
                                        $settlementItems = $purchase_return->settlementItems ?? collect();
                                        $hasUnapprovedItems = $settlementItems->filter(function($item) {
                                            return !in_array(strtoupper($item->status), ['APPROVED', 'RECEIVED']);
                                        })->isNotEmpty();
                                    @endphp
                                    
                                    {{-- Always show Kelola Penyelesaian if there are unapproved items or no items yet --}}
                                    @if($hasUnapprovedItems || $settlementItems->isEmpty())
                                        @can('purchaseReturnSettlements.submit')
                                            <a class="btn btn-primary btn-sm d-print-none mr-2 mb-1" href="{{ route('purchase-returns.settlement', $purchase_return->id) }}">
                                                <i class="bi bi-arrow-repeat"></i> Kelola Penyelesaian
                                            </a>
                                        @endcan
                                    @endif

                                    @canany(['purchaseReturnSettlements.approve', 'purchaseReturnSettlements.execute', 'purchaseReturnSettlements.receive'])
                                        @if($purchase_return->settlement)
                                            @if($purchase_return->settlement->status === 'pending')
                                                <span class="badge bg-warning text-dark mr-2 mb-1">Settlement Pending</span>
                                                @can('purchaseReturnSettlements.approve')
                                                    <form method="POST" action="{{ route('purchase-return-settlements.approve', $purchase_return->settlement->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success btn-sm d-print-none mr-2 mb-1" onclick="return confirm('Setujui penyelesaian ini?')">
                                                            <i class="bi bi-check-circle"></i> Setuju
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-outline-danger btn-sm d-print-none mr-2 mb-1" onclick="$('#rejectSettlementModal').modal('show')">
                                                        <i class="bi bi-x-circle"></i> Tolak
                                                    </button>
                                                @endcan
                                            @elseif($purchase_return->settlement->status === 'approved')
                                                <span class="badge bg-success mr-2 mb-1">Settlement Approved</span>
                                                @can('purchaseReturnSettlements.execute')
                                                    <form method="POST" action="{{ route('purchase-return-settlements.execute', $purchase_return->settlement->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-primary btn-sm d-print-none mr-2 mb-1" onclick="return confirm('Eksekusi penyelesaian ini? Tindakan ini tidak dapat dibatalkan.')">
                                                            <i class="bi bi-play-circle"></i> Eksekusi Penyelesaian
                                                        </button>
                                                    </form>
                                                @endcan
                                            @elseif($purchase_return->settlement->status === 'executing')
                                                <span class="badge bg-info text-dark mr-2 mb-1">Settlement Executing (Awaiting Replacement)</span>
                                                
                                                {{-- Batch 7: Receive Replacement --}}
                                                @if($purchase_return->return_dispatched_at && $purchase_return->goods->where('received_quantity', '<', 'quantity')->isNotEmpty())
                                                    @can('purchaseReturnSettlements.receive')
                                                        <button type="button" class="btn btn-success btn-sm d-print-none mr-2 mb-1" data-toggle="modal" data-target="#receiveReplacementModal" data-bs-toggle="modal" data-bs-target="#receiveReplacementModal">
                                                            <i class="bi bi-box-seam"></i> Terima Barang Pengganti
                                                        </button>
                                                    @endcan
                                                @endif
                                            @elseif($purchase_return->settlement->status === 'rejected')
                                                <span class="badge bg-danger mr-2 mb-1">Settlement Rejected</span>
                                            @endif
                                        @endif
                                    @endcanany
                                @endif
                            @endif
                            <a target="_blank" class="btn btn-outline-primary btn-sm d-print-none mr-2 mb-1" href="{{ route('purchase-returns.pdf', $purchase_return->id) }}">
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
                                    <p class="mb-1 font-weight-bold">{{ settings()->company_name }}</p>
                                    <p class="mb-1">{{ settings()->company_address }}</p>
                                    <p class="mb-1">Email: {{ settings()->company_email }}</p>
                                    <p class="mb-0">Telepon: {{ settings()->company_phone }}</p>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Pemasok</h6>
                                    <p class="mb-1 font-weight-bold">{{ $supplier->supplier_name }}</p>
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
                                        <dd class="col-7 font-weight-bold">INV/{{ $purchase_return->reference }}</dd>
                                        @php 
                                            $headerLocation = $purchase_return->location ?? $purchase_return->purchaseReturnDetails->first()?->location;
                                        @endphp
                                        @if($headerLocation)
                                            <dt class="col-5 text-muted">Lokasi</dt>
                                            <dd class="col-7 font-weight-bold">{{ $headerLocation->name }}</dd>
                                        @endif

                                        @if($purchase_return->settled_at)
                                            <dt class="col-5 text-muted">Tanggal Selesai</dt>
                                            <dd class="col-7 font-weight-bold">{{ $purchase_return->settled_at->translatedFormat('d F Y H:i') }}</dd>
                                        @endif
                                    </dl>
                                </div>
                            </div>
                        </div>

                        @include('purchasesreturn::partials.dispatch-info')

                        @include('purchasesreturn::partials.settlement-items-table')

                        <div class="table-responsive">
                            @can('purchaseReturns.viewPrice')
                                <table class="table table-sm table-striped table-hover align-middle" style="min-width: 1200px;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Produk</th>
                                            <th>Lokasi</th>
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
                                                    <div class="font-weight-bold">{{ $item->product_name }}</div>
                                                    <small class="badge bg-success">{{ $item->product_code }}</small>
                                                    @if($item->getSerialNumbers()->isNotEmpty())
                                                        <div class="mt-1">
                                                            @foreach($item->getSerialNumbers() as $serial)
                                                                <span class="badge bg-secondary">{{ $serial }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">{{ $item->location?->setting?->company_name ?? 'N/A' }}</div>
                                                    <small class="text-muted">{{ $item->location?->name ?? '-' }}</small>
                                                </td>
                                                <td class="text-center">{{ format_currency($item->unit_price) }}</td>
                                                <td class="text-center">{{ $item->quantity }}</td>
                                                <td class="text-end">{{ format_currency($item->product_discount_amount) }}</td>
                                                <td class="text-end">{{ format_currency($item->product_tax_amount) }}</td>
                                                <td class="text-end font-weight-bold">{{ format_currency($item->sub_total) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <table class="table table-sm table-striped table-hover align-middle" style="min-width: 900px;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Produk</th>
                                            <th>Lokasi</th>
                                            <th class="text-center">Jumlah</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($purchase_return->purchaseReturnDetails as $item)
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">{{ $item->product_name }}</div>
                                                    <small class="badge bg-success">{{ $item->product_code }}</small>
                                                    @if($item->getSerialNumbers()->isNotEmpty())
                                                        <div class="mt-1">
                                                            @foreach($item->getSerialNumbers() as $serial)
                                                                <span class="badge bg-secondary">{{ $serial }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">{{ $item->location?->setting?->company_name ?? 'N/A' }}</div>
                                                    <small class="text-muted">{{ $item->location?->name ?? '-' }}</small>
                                                </td>
                                                <td class="text-center">{{ $item->quantity }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endcan
                        </div>

                        @can('purchaseReturns.viewPrice')
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
                                            <li class="d-flex justify-content-between py-2 border-top mt-2 font-weight-bold">
                                                <span>Total</span>
                                                <span>{{ format_currency($purchase_return->total_amount) }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @endcan



                        @if($purchase_return->return_type === 'exchange' && $purchase_return->goods->isNotEmpty())
                            <div class="mt-4">
                                <h5 class="mb-3">Detail Penggantian Produk</h5>
                                <div class="table-responsive">
                                    @can('purchaseReturns.viewPrice')
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
                                                            <div class="font-weight-bold">{{ $good->product_name }}</div>
                                                            <small class="text-muted">{{ $good->product_code }}</small>
                                                        </td>
                                                        <td class="text-center">{{ $good->quantity }}</td>
                                                        <td class="text-end">{{ format_currency($good->unit_value) }}</td>
                                                        <td class="text-end">{{ format_currency($good->sub_total) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @else
                                        <table class="table table-sm table-bordered align-middle">
                                            <thead class="table-light">
                                                <tr class="text-center">
                                                    <th>Produk</th>
                                                    <th>Jumlah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($purchase_return->goods as $good)
                                                    <tr>
                                                        <td>
                                                            <div class="font-weight-bold">{{ $good->product_name }}</div>
                                                            <small class="text-muted">{{ $good->product_code }}</small>
                                                        </td>
                                                        <td class="text-center">{{ $good->quantity }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endcan
                                </div>
                            </div>
                        @endif

                        @can('purchaseReturns.viewPrice')
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
                                </div>
                            @endif
                        @else
                            @if($purchase_return->return_type === 'deposit' && $purchase_return->supplierCredit)
                                <div class="alert alert-info mt-4" role="alert">
                                    Kredit pemasok telah dibuat (Status: {{ ucfirst($purchase_return->supplierCredit->status) }}).
                                </div>
                            @endif

                            @if($purchase_return->return_type === 'cash')
                                <div class="mt-4">
                                    <h5 class="mb-3">Pengembalian Tunai</h5>
                                    <p class="mb-2">Pengembalian tunai telah diproses.</p>
                                </div>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('purchasesreturn::partials.approve-modal')
    @include('purchasesreturn::partials.reject-modal')
    @include('purchasesreturn::partials.dispatch-request-modal')
    @include('purchasesreturn::partials.dispatch-request-confirm-modal')
    @include('purchasesreturn::partials.dispatch-approve-modal')
    @include('purchasesreturn::partials.dispatch-reject-modal')
    @include('purchasesreturn::partials.settlement-reject-modal')

    <!-- Receiver Replacement Modal -->
    <div class="modal fade" id="receiveReplacementModal" tabindex="-1" aria-labelledby="receiveReplacementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('purchase-return-settlements.receive', $purchase_return->settlement->id ?? 0) }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="receiveReplacementModalLabel">Terima Barang Pengganti</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label font-weight-bold">Lokasi Tujuan Penerimaan</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </span>
                                @php 
                                    $prLocation = $purchase_return->location ?? $purchase_return->purchaseReturnDetails->first()?->location;
                                @endphp
                                <input type="text" class="form-control bg-light border-start-0" value="{{ $prLocation->name ?? 'N/A' }}" readonly>
                            </div>
                            <small class="text-muted"><i class="bi bi-lock-fill me-1"></i>Stok akan otomatis dikembalikan ke lokasi pengiriman asal.</small>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center" style="width: 100px;">Sisa</th>
                                        <th class="text-center" style="width: 120px;">Diterima</th>
                                        <th>Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($purchase_return->goods as $index => $good)
                                        @php
                                            $remaining = $good->quantity - $good->received_quantity;
                                        @endphp
                                        @if($remaining > 0)
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">{{ $good->product_name }}</div>
                                                    <small class="text-muted">{{ $good->product_code }}</small>
                                                    <input type="hidden" name="items[{{ $index }}][id]" value="{{ $good->id }}">
                                                    
                                                    @if($good->product && $good->product->serial_number_required)
                                                        <div class="mt-2">
                                                            <label class="small text-muted mb-1">Serial Number (Baru/Repaired)</label>
                                                            <select class="form-select form-select-sm" name="items[{{ $index }}][serial_numbers][]" multiple data-placeholder="Ketik SN lalu enter" id="serial_select_{{ $good->id }}">
                                                                {{-- Serials will be entered as tags --}}
                                                            </select>
                                                            <small class="text-xs text-muted">Ketik nomor seri lalu tekan Enter.</small>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-warning text-dark">{{ $remaining }}</span>
                                                </td>
                                                <td>
                                                    <input type="number" name="items[{{ $index }}][received_quantity]" class="form-control form-control-sm text-center {{ !$good->product->serial_number_required ? 'bg-light' : '' }}" min="0" max="{{ $remaining }}" value="{{ $remaining }}" {{ !$good->product->serial_number_required ? 'readonly' : '' }}>
                                                </td>
                                                <td>
                                                    <input type="text" name="items[{{ $index }}][note]" class="form-control form-control-sm" placeholder="Catatan penerimaan...">
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Pastikan data yang dimasukkan sudah benar.')">Simpan Penerimaan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
@endsection

@section('after-content')
    @include('purchasesreturn::partials.reject-item-modal')
@endsection

    @include('purchasesreturn::partials.dispatch-request-scripts')

    @push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 for serial numbers if available, or simple tagging
            // Assuming no select2 for now to keep it simple, or use standard input if select2 not available.
            // But since the requirement mentioned serial management, let's try to make it usable.
            // If Select2 is available globally:
            if (typeof $ !== 'undefined' && $.fn.select2) {
                // Initialize existing serial number select2
                $('select[name*="[serial_numbers]"]').length && $('select[name*="[serial_numbers]"]').select2({
                    tags: true,
                    tokenSeparators: [',', ' '],
                    theme: 'bootstrap-5',
                    width: '100%'
                });





                // Prevent Enter key from submitting form on specific inputs
                $(document).on('keydown', '.prevent-enter-submit', function(e) {
                    if (e.key === 'Enter' || e.keyCode === 13) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });
    </script>
    @endpush
