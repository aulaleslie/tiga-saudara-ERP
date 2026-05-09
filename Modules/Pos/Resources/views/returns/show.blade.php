@php
    $status = strtolower((string) $return->status);
    $approvalStatus = strtolower((string) $return->approval_status);
    $isCashReturn = $return->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN;
    $requiresManualCorrection = ! empty($return->manual_correction_required_at);
@endphp

@extends('layouts.app')

@section('title', 'Detail Retur POS')

@can('pos.returns.approve')
    @if($approvalStatus === 'pending' && ! $requiresManualCorrection)
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
    @if(in_array($status, ['approved', 'awaiting_receiving'], true) && ! $return->received_at && ! $requiresManualCorrection)
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
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start">
                            <div class="mb-3 mb-xl-0">
                                <div class="small text-muted text-uppercase">Retur POS</div>
                                <h4 class="mb-1">#{{ $return->reference }}</h4>
                                <div class="small text-muted">Dibuat pada {{ optional($return->created_at)->translatedFormat('d F Y H:i') }}</div>
                            </div>
                            <div class="d-flex flex-column align-items-xl-end">
                                <div class="d-flex flex-wrap align-items-center justify-content-xl-end mb-2">
                                    <span class="me-2 mb-1">@include('pos::returns.partials.status', ['data' => $return])</span>
                                    <span class="badge bg-light text-dark border text-uppercase me-2 mb-1">{{ str_replace('_', ' ', $return->approval_status) }}</span>
                                </div>
                                <div class="d-flex flex-wrap align-items-center justify-content-xl-end">
                                    @can('pos.returns.edit')
                                        @if($return->isDraftEditable() && ! $requiresManualCorrection)
                                            <a href="{{ route('pos.returns.edit', $return) }}" class="btn btn-primary btn-sm me-2 mb-1">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        @endif
                                    @endcan

                                    @can('pos.returns.approve')
                                        @if($return->isDraftSubmittable() && ! $requiresManualCorrection)
                                            <form method="POST" action="{{ route('pos.returns.submit-draft', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Ajukan retur POS draft ini untuk persetujuan?')">
                                                    <i class="bi bi-send"></i> Ajukan Persetujuan
                                                </button>
                                            </form>
                                        @elseif($approvalStatus === 'pending' && ! $requiresManualCorrection)
                                            <button type="button" class="btn btn-success btn-sm me-2 mb-1" data-toggle="modal" data-target="#approveModal">
                                                <i class="bi bi-check2-circle"></i> Setujui
                                            </button>

                                            <form id="pos-return-reject-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.reject', $return) }}" class="d-none">
                                                @csrf
                                                <input type="hidden" name="reason" value="">
                                            </form>
                                            <button type="button" class="btn btn-outline-danger btn-sm me-2 mb-1" onclick="posReturnReject{{ $return->id }}()">
                                                <i class="bi bi-x-circle"></i> Tolak
                                            </button>

                                            <div class="modal fade" id="approveModal" tabindex="-1" role="dialog" aria-labelledby="approveModalLabel" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <form method="POST" action="{{ route('pos.returns.approve', $return) }}">
                                                            @csrf
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="approveModalLabel">Persetujuan Retur POS</h5>
                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                    <span aria-hidden="true">&times;</span>
                                                                </button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>Silakan tentukan jenis penyelesaian untuk retur ini:</p>
                                                                <div class="form-group">
                                                                    <div class="form-check mb-2">
                                                                        <input class="form-check-input" type="radio" name="return_option" id="opt_cash" value="cash_return" checked>
                                                                        <label class="form-check-label" for="opt_cash">
                                                                            <strong>Retur Tunai (Cash Return)</strong>
                                                                            <div class="small text-muted">Pelanggan akan menerima pengembalian uang tunai setelah barang diterima.</div>
                                                                        </label>
                                                                    </div>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="radio" name="return_option" id="opt_replacement" value="product_replacement">
                                                                        <label class="form-check-label" for="opt_replacement">
                                                                            <strong>Ganti Produk (Product Replacement)</strong>
                                                                            <div class="small text-muted">Pelanggan akan menerima produk pengganti dengan SKU yang sama setelah barang diterima.</div>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                                                <button type="submit" class="btn btn-success">Setujui Retur</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endcan

                                    @can('pos.returns.receive')
                                        @if($status === 'approved' && ! $requiresManualCorrection)
                                            <form method="POST" action="{{ route('pos.returns.receive', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-primary btn-sm" onclick="return confirm('Terima barang retur ini?')">
                                                    <i class="bi bi-box-arrow-in-down"></i> Terima Barang
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.settle')
                                        @if($status === 'awaiting_settlement' && $isCashReturn && ! $requiresManualCorrection)
                                            <form method="POST" action="{{ route('pos.returns.settle', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Proses pengembalian tunai untuk retur ini?')">
                                                    <i class="bi bi-cash-stack"></i> Selesaikan Tunai
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.dispatch')
                                        @if($status === 'awaiting_dispatch' && ! $isCashReturn && ! $requiresManualCorrection)
                                            <form method="POST" action="{{ route('pos.returns.dispatch', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('Proses pengiriman pengganti untuk retur ini?')">
                                                    <i class="bi bi-truck"></i> Kirim Pengganti
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.delete')
                                        @if($return->isHardDeletable() && ! $requiresManualCorrection)
                                            <form method="POST" action="{{ route('pos.returns.destroy', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Hapus permanen retur POS draft ini?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        @elseif(in_array($status, ['approved', 'awaiting_receiving'], true) && ! $return->received_at && ! $requiresManualCorrection)
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
                        </div>
                    </div>
                    <div class="card-body">
                        @if($requiresManualCorrection)
                            <div class="alert alert-danger mb-4">
                                <div class="fw-semibold mb-1">Retur POS diblokir untuk koreksi manual teraudit.</div>
                                <div class="small">Aksi gagal: {{ str_replace('_', ' ', (string) $return->manual_correction_action) ?: '-' }}</div>
                                <div class="small">Waktu: {{ optional($return->manual_correction_required_at)->translatedFormat('d F Y H:i') ?: '-' }}</div>
                                <div class="small mb-0">Alasan: {{ $return->manual_correction_reason ?: '-' }}</div>
                            </div>
                        @endif

                        @include('pos::returns.partials.readonly-detail', ['detailView' => $detailView])
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
