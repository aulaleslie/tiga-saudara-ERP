@php
    $status = strtolower((string) $return->status);
    $approvalStatus = strtolower((string) $return->approval_status);
    $isCashReturn = $return->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN;
@endphp

@extends('layouts.app')

@section('title', 'Detail Retur POS')

@can('pos.returns.approve')
    @if($approvalStatus === 'pending')
        @push('page_scripts')
            <script>
                function posReturnReject{{ $return->id }}() {
                    const reason = prompt('Masukkan alasan penolakan (opsional):');
                    if (reason !== null) {
                        const form = document.getElementById('pos-return-reject-form-{{ $return->id }}');
                        form.querySelector('input[name="reason"]').value = reason;
                        form.submit();
                    }
                }
            </script>
        @endpush
    @endif
@endcan

@can('pos.returns.delete')
    @if(in_array($status, ['approved', 'awaiting_receiving'], true) && ! $return->received_at)
        @push('page_scripts')
            <script>
                function posReturnArchive{{ $return->id }}() {
                    const reason = prompt('Masukkan alasan arsip retur POS (opsional):');
                    if (reason !== null) {
                        const form = document.getElementById('pos-return-archive-form-{{ $return->id }}');
                        form.querySelector('input[name="reason"]').value = reason;
                        form.submit();
                    }
                }

                function posReturnCancel{{ $return->id }}() {
                    const reason = prompt('Masukkan alasan pembatalan retur POS (opsional):');
                    if (reason !== null) {
                        const form = document.getElementById('pos-return-cancel-form-{{ $return->id }}');
                        form.querySelector('input[name="reason"]').value = reason;
                        form.submit();
                    }
                }
            </script>
        @endpush
    @endif
@endcan

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.returns.index') }}">Retur POS</a></li>
        <li class="breadcrumb-item active">Detail Retur</li>
    </ol>
@endsection

@section('content')
    @include('utils.alerts')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center bg-white border-0">
                        <div>
                            <h4 class="mb-0">Retur POS #{{ $return->reference }}</h4>
                            <div class="small text-muted">Dibuat pada {{ optional($return->created_at)->translatedFormat('d F Y H:i') }}</div>
                        </div>
                        <div class="ms-auto d-flex flex-wrap align-items-center">
                            <span class="me-2 mb-1">@include('pos::returns.partials.status', ['data' => $return])</span>
                            <span class="badge bg-light text-dark border text-uppercase me-2 mb-1">{{ str_replace('_', ' ', $return->approval_status) }}</span>

                            @can('pos.returns.edit')
                                @if($status === 'pending_approval')
                                    <a href="{{ route('pos.returns.edit', $return) }}" class="btn btn-primary btn-sm me-2 mb-1">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                @endif
                            @endcan

                            @can('pos.returns.approve')
                                @if($approvalStatus === 'pending')
                                    <form method="POST" action="{{ route('pos.returns.approve', $return) }}" class="d-inline me-2 mb-1">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Setujui retur POS ini?')">
                                            <i class="bi bi-check2-circle"></i> Setujui
                                        </button>
                                    </form>
                                    <form id="pos-return-reject-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.reject', $return) }}" class="d-none">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                    </form>
                                    <button type="button" class="btn btn-outline-danger btn-sm me-2 mb-1" onclick="posReturnReject{{ $return->id }}()">
                                        <i class="bi bi-x-circle"></i> Tolak
                                    </button>
                                @endif
                            @endcan

                            @can('pos.returns.receive')
                                @if($status === 'approved')
                                    <form method="POST" action="{{ route('pos.returns.receive', $return) }}" class="d-inline me-2 mb-1">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Terima barang retur ini?')">
                                            <i class="bi bi-box-arrow-in-down"></i> Terima Barang
                                        </button>
                                    </form>
                                @endif
                            @endcan

                            @can('pos.returns.settle')
                                @if($status === 'awaiting_settlement' && $isCashReturn)
                                    <form method="POST" action="{{ route('pos.returns.settle', $return) }}" class="d-inline me-2 mb-1">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Proses pengembalian tunai untuk retur ini?')">
                                            <i class="bi bi-cash-stack"></i> Selesaikan Tunai
                                        </button>
                                    </form>
                                @endif
                            @endcan

                            @can('pos.returns.dispatch')
                                @if($status === 'awaiting_dispatch' && ! $isCashReturn)
                                    <form method="POST" action="{{ route('pos.returns.dispatch', $return) }}" class="d-inline me-2 mb-1">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('Proses pengiriman pengganti untuk retur ini?')">
                                            <i class="bi bi-truck"></i> Kirim Pengganti
                                        </button>
                                    </form>
                                @endif
                            @endcan

                            @can('pos.returns.delete')
                                @if(in_array($status, ['approved', 'awaiting_receiving'], true) && ! $return->received_at)
                                    <form id="pos-return-archive-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.archive', $return) }}" class="d-none">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                    </form>
                                    <form id="pos-return-cancel-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.cancel', $return) }}" class="d-none">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                    </form>
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-2 mb-1" onclick="posReturnArchive{{ $return->id }}()">
                                        <i class="bi bi-archive"></i> Arsipkan
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm me-2 mb-1" onclick="posReturnCancel{{ $return->id }}()">
                                        <i class="bi bi-slash-circle"></i> Batalkan
                                    </button>
                                @endif
                            @endcan
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-4 mb-4">
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Ringkasan Retur</h6>
                                    <dl class="row mb-0 small">
                                        <dt class="col-5 text-muted">Referensi</dt>
                                        <dd class="col-7 fw-semibold">{{ $return->reference }}</dd>
                                        <dt class="col-5 text-muted">Opsi</dt>
                                        <dd class="col-7 fw-semibold">{{ $isCashReturn ? 'Kembali Uang' : 'Ganti Produk' }}</dd>
                                        <dt class="col-5 text-muted">Total</dt>
                                        <dd class="col-7 fw-semibold">{{ format_currency($return->total_amount) }}</dd>
                                        <dt class="col-5 text-muted">Disetujui</dt>
                                        <dd class="col-7">{{ $return->approved_at ? $return->approved_at->translatedFormat('d F Y H:i') . ' / ' . (optional($return->approvedBy)->name ?? '-') : '-' }}</dd>
                                        <dt class="col-5 text-muted">Diterima</dt>
                                        <dd class="col-7">{{ $return->received_at ? $return->received_at->translatedFormat('d F Y H:i') . ' / ' . (optional($return->receivedBy)->name ?? '-') : '-' }}</dd>
                                        <dt class="col-5 text-muted">Selesai</dt>
                                        <dd class="col-7">{{ $return->settled_at ? $return->settled_at->translatedFormat('d F Y H:i') . ' / ' . (optional($return->settledBy)->name ?? '-') : '-' }}</dd>
                                        @if($return->rejection_reason)
                                            <dt class="col-5 text-muted">Alasan Tolak</dt>
                                            <dd class="col-7">{{ $return->rejection_reason }}</dd>
                                        @endif
                                    </dl>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Sumber POS</h6>
                                    <dl class="row mb-0 small">
                                        <dt class="col-5 text-muted">Receipt</dt>
                                        <dd class="col-7 fw-semibold">{{ $return->receipt_number ?: '-' }}</dd>
                                        <dt class="col-5 text-muted">Transaksi</dt>
                                        <dd class="col-7 fw-semibold">{{ $return->transaction_code ?: '-' }}</dd>
                                        <dt class="col-5 text-muted">Pelanggan</dt>
                                        <dd class="col-7 fw-semibold">{{ $return->customer_name ?: '-' }}</dd>
                                        <dt class="col-5 text-muted">Checkout</dt>
                                        <dd class="col-7">#{{ $return->pos_checkout_id ?: '-' }}</dd>
                                        <dt class="col-5 text-muted">POS Tx</dt>
                                        <dd class="col-7">#{{ $return->pos_transaction_id ?: '-' }}</dd>
                                    </dl>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="h-100 border rounded p-3">
                                    <h6 class="text-uppercase text-muted small mb-3">Linked Sales Returns</h6>
                                    @forelse($return->saleReturns as $saleReturn)
                                        <div class="border rounded p-2 mb-2">
                                            <div class="fw-semibold">{{ $saleReturn->reference }}</div>
                                            <div class="small text-muted">{{ $saleReturn->sale_reference ?: '-' }}</div>
                                            <div class="small">Status: {{ $saleReturn->status }}</div>
                                            <div class="small">Lokasi: {{ optional($saleReturn->location)->name ?? '-' }}</div>
                                        </div>
                                    @empty
                                        <div class="text-muted small">Belum ada Sales Return terhubung.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mb-4">
                            <table class="table table-striped table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th>Owner / Sale</th>
                                        <th>Dispatch / Lokasi</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Harga</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($return->lines as $line)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $line->product_name }}</div>
                                                <div class="small text-muted">{{ $line->product_code }}</div>
                                                @if($line->bundle_group_key)
                                                    <div class="small text-muted">Bundle: {{ $line->bundle_group_key }}</div>
                                                @endif
                                                @if(! empty($line->serial_number_ids))
                                                    <div class="mt-1">
                                                        @foreach($line->serial_number_ids as $serialId)
                                                            <span class="badge bg-light text-dark border">SN-{{ $serialId }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="small">Sale ID: {{ $line->sale_id ?: '-' }}</div>
                                                <div class="small">Sale Detail ID: {{ $line->sale_detail_id ?: '-' }}</div>
                                                <div class="small">Source Setting: {{ $line->source_setting_id ?: '-' }}</div>
                                            </td>
                                            <td>
                                                <div class="small">Dispatch Detail: {{ $line->dispatch_detail_id ?: '-' }}</div>
                                                <div class="small">Lokasi: {{ $line->source_location_id ?: '-' }}</div>
                                                <div class="small">Tax: {{ $line->tax_id ?: '-' }}</div>
                                            </td>
                                            <td class="text-center">{{ (float) $line->quantity }}</td>
                                            <td class="text-end">{{ format_currency($line->unit_price) }}</td>
                                            <td class="text-end">{{ format_currency($line->line_total) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Tidak ada baris retur.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if(is_array($return->source_snapshot) && $return->source_snapshot !== [])
                            <div class="border rounded p-3 bg-light">
                                <h6 class="text-uppercase text-muted small mb-3">Snapshot Hash</h6>
                                <div class="small text-break">{{ $return->source_snapshot_hash }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
