@php
    $status = strtolower((string) $return->status);
    $approvalStatus = strtolower((string) $return->approval_status);
    $isCashReturn = $return->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN;
    $requiresManualCorrection = ! empty($return->manual_correction_required_at);
    $canSubmitDraft = auth()->user()?->can('pos.returns.create') || auth()->user()?->can('pos.returns.edit');
@endphp

@extends('layouts.app')

@section('title', 'Detail Retur POS')

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const actionModalEl = document.getElementById('posReturnActionModal');
            const actionTitleEl = document.getElementById('posReturnActionModalLabel');
            const actionMessageEl = document.getElementById('pos-return-action-message');
            const reasonGroupEl = document.getElementById('pos-return-action-reason-group');
            const reasonLabelEl = document.getElementById('pos-return-action-reason-label');
            const reasonInputEl = document.getElementById('pos-return-action-reason-input');
            const confirmButtonEl = document.getElementById('pos-return-action-confirm-button');

            if (! actionModalEl || ! actionTitleEl || ! actionMessageEl || ! reasonGroupEl || ! reasonLabelEl || ! reasonInputEl || ! confirmButtonEl) {
                return;
            }

            let modalInstance = null;

            const hasJQueryModal = () => window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function';

            const getModalInstance = () => {
                if (! window.bootstrap || ! window.bootstrap.Modal) {
                    return null;
                }

                if (! modalInstance) {
                    modalInstance = typeof window.bootstrap.Modal.getOrCreateInstance === 'function'
                        ? window.bootstrap.Modal.getOrCreateInstance(actionModalEl)
                        : new window.bootstrap.Modal(actionModalEl);
                }

                return modalInstance;
            };

            const showActionModal = () => {
                const modal = getModalInstance();

                if (modal) {
                    modal.show();
                    return;
                }

                if (hasJQueryModal()) {
                    window.jQuery(actionModalEl).modal('show');
                    return;
                }

                actionModalEl.classList.add('show');
                actionModalEl.style.display = 'block';
                actionModalEl.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');

                if (! document.getElementById('pos-return-action-modal-backdrop')) {
                    const backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    backdrop.id = 'pos-return-action-modal-backdrop';
                    document.body.appendChild(backdrop);
                }
            };

            const hideActionModal = () => {
                const modal = getModalInstance();

                if (modal) {
                    modal.hide();
                    return;
                }

                if (hasJQueryModal()) {
                    window.jQuery(actionModalEl).modal('hide');
                    return;
                }

                actionModalEl.classList.remove('show');
                actionModalEl.style.display = 'none';
                actionModalEl.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');

                const backdrop = document.getElementById('pos-return-action-modal-backdrop');

                if (backdrop) {
                    backdrop.remove();
                }

                resetActionModal();
            };

            const resetActionModal = () => {
                actionModalEl.dataset.activeFormId = '';
                actionModalEl.dataset.activeReasonInputName = '';
                actionTitleEl.textContent = 'Konfirmasi Aksi';
                actionMessageEl.textContent = '';
                reasonLabelEl.textContent = 'Alasan';
                reasonInputEl.value = '';
                reasonInputEl.placeholder = '';
                reasonInputEl.required = false;
                reasonGroupEl.classList.add('d-none');
                confirmButtonEl.textContent = 'Lanjutkan';
            };

            document.querySelectorAll('[data-pos-return-modal-trigger]').forEach(function (button) {
                button.addEventListener('click', function () {
                    actionModalEl.dataset.activeFormId = button.dataset.formId || '';
                    actionModalEl.dataset.activeReasonInputName = button.dataset.reasonInputName || '';
                    actionTitleEl.textContent = button.dataset.modalTitle || 'Konfirmasi Aksi';
                    actionMessageEl.textContent = button.dataset.modalMessage || '';
                    reasonLabelEl.textContent = button.dataset.reasonLabel || 'Alasan';
                    reasonInputEl.value = '';
                    reasonInputEl.placeholder = button.dataset.reasonPlaceholder || '';
                    reasonInputEl.required = button.dataset.reasonRequired === 'true';
                    reasonGroupEl.classList.toggle('d-none', ! actionModalEl.dataset.activeReasonInputName);
                    confirmButtonEl.textContent = button.dataset.submitLabel || 'Lanjutkan';
                    showActionModal();
                });
            });

            confirmButtonEl.addEventListener('click', function () {
                const formId = actionModalEl.dataset.activeFormId || '';

                if (! formId) {
                    return;
                }

                const targetForm = document.getElementById(formId);

                if (! targetForm) {
                    return;
                }

                const reasonInputName = actionModalEl.dataset.activeReasonInputName || '';

                if (reasonInputName) {
                    const hiddenInput = targetForm.querySelector('[name="' + reasonInputName + '"]');

                    if (hiddenInput) {
                        hiddenInput.value = reasonInputEl.value.trim();
                    }
                }

                targetForm.submit();
            });

            actionModalEl.addEventListener('hidden.bs.modal', resetActionModal);

            if (hasJQueryModal()) {
                window.jQuery(actionModalEl).on('hidden.bs.modal', resetActionModal);
            }

            actionModalEl.querySelectorAll('[data-dismiss="modal"], [data-bs-dismiss="modal"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (! getModalInstance() && ! hasJQueryModal()) {
                        hideActionModal();
                    }
                });
            });

            resetActionModal();
        });
    </script>
@endpush

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
                                        @if($return->isRevisionEditable() && ! $requiresManualCorrection)
                                            <a href="{{ route('pos.returns.edit', $return) }}" class="btn btn-primary btn-sm me-2 mb-1">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        @endif
                                    @endcan

                                    @if($canSubmitDraft)
                                        @if($return->isDraftSubmittable() && ! $requiresManualCorrection)
                                            <form id="pos-return-submit-draft-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.submit-draft', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-submit-draft-form-{{ $return->id }}"
                                                    data-modal-title="Ajukan Persetujuan"
                                                    data-modal-message="Ajukan retur POS draft ini untuk persetujuan?"
                                                    data-submit-label="Ajukan Persetujuan"
                                                >
                                                    <i class="bi bi-send"></i> Ajukan Persetujuan
                                                </button>
                                            </form>
                                        @endif
                                    @endif

                                    @can('pos.returns.approve')
                                        @if($approvalStatus === 'pending' && ! $requiresManualCorrection)
                                            <button type="button" class="btn btn-success btn-sm me-2 mb-1" data-toggle="modal" data-target="#approveModal" data-bs-toggle="modal" data-bs-target="#approveModal">
                                                <i class="bi bi-check2-circle"></i> Setujui
                                            </button>

                                            <form id="pos-return-reject-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.reject', $return) }}" class="d-none">
                                                @csrf
                                                <input type="hidden" name="reason" value="">
                                            </form>
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger btn-sm me-2 mb-1"
                                                data-pos-return-modal-trigger
                                                data-form-id="pos-return-reject-form-{{ $return->id }}"
                                                data-modal-title="Tolak Retur POS"
                                                data-modal-message="Masukkan alasan penolakan jika diperlukan, lalu lanjutkan untuk menolak retur POS ini."
                                                data-submit-label="Tolak Retur"
                                                data-reason-input-name="reason"
                                                data-reason-label="Alasan Penolakan"
                                                data-reason-placeholder="Masukkan alasan penolakan (opsional)"
                                            >
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
                                            <form id="pos-return-receive-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.receive', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-receive-form-{{ $return->id }}"
                                                    data-modal-title="Terima Barang Retur"
                                                    data-modal-message="Terima barang untuk retur POS ini?"
                                                    data-submit-label="Terima Barang"
                                                >
                                                    <i class="bi bi-box-arrow-in-down"></i> Terima Barang
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.settle')
                                        @if($status === 'awaiting_settlement' && $isCashReturn && ! $requiresManualCorrection)
                                            <form id="pos-return-settle-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.settle', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-settle-form-{{ $return->id }}"
                                                    data-modal-title="Selesaikan Retur Tunai"
                                                    data-modal-message="Proses pengembalian tunai untuk retur POS ini?"
                                                    data-submit-label="Selesaikan Tunai"
                                                >
                                                    <i class="bi bi-cash-stack"></i> Selesaikan Tunai
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.dispatch')
                                        @if($status === 'awaiting_dispatch' && ! $isCashReturn && ! $requiresManualCorrection)
                                            <form id="pos-return-dispatch-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.dispatch', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-warning btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-dispatch-form-{{ $return->id }}"
                                                    data-modal-title="Kirim Produk Pengganti"
                                                    data-modal-message="Proses pengiriman produk pengganti untuk retur POS ini?"
                                                    data-submit-label="Kirim Pengganti"
                                                >
                                                    <i class="bi bi-truck"></i> Kirim Pengganti
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @can('pos.returns.delete')
                                        @if($return->isHardDeletable() && ! $requiresManualCorrection)
                                            <form id="pos-return-delete-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.destroy', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-delete-form-{{ $return->id }}"
                                                    data-modal-title="Hapus Draft Retur POS"
                                                    data-modal-message="Hapus permanen retur POS draft ini? Tindakan ini tidak dapat dibatalkan."
                                                    data-submit-label="Hapus Draft"
                                                >
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        @elseif($return->isRejectedSoftDeletable() && ! $requiresManualCorrection)
                                            <form id="pos-return-delete-rejected-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.destroy', $return) }}" class="d-inline me-2 mb-1">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="delete_reason" value="">
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm"
                                                    data-pos-return-modal-trigger
                                                    data-form-id="pos-return-delete-rejected-form-{{ $return->id }}"
                                                    data-modal-title="Hapus Retur POS Ditolak"
                                                    data-modal-message="Hapus retur POS yang ditolak ini dari daftar aktif? Riwayat audit tetap disimpan."
                                                    data-submit-label="Hapus Retur"
                                                    data-reason-input-name="delete_reason"
                                                    data-reason-label="Alasan Hapus"
                                                    data-reason-placeholder="Masukkan alasan hapus retur POS ditolak (opsional)"
                                                >
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
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm me-2 mb-1"
                                                data-pos-return-modal-trigger
                                                data-form-id="pos-return-archive-form-{{ $return->id }}"
                                                data-modal-title="Arsipkan Retur POS"
                                                data-modal-message="Arsipkan retur POS ini dari daftar aktif?"
                                                data-submit-label="Arsipkan"
                                                data-reason-input-name="reason"
                                                data-reason-label="Alasan Arsip"
                                                data-reason-placeholder="Masukkan alasan arsip retur POS (opsional)"
                                            >
                                                <i class="bi bi-archive"></i> Arsipkan
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger btn-sm me-2 mb-1"
                                                data-pos-return-modal-trigger
                                                data-form-id="pos-return-cancel-form-{{ $return->id }}"
                                                data-modal-title="Batalkan Retur POS"
                                                data-modal-message="Batalkan retur POS ini? Riwayat audit akan tetap tersimpan."
                                                data-submit-label="Batalkan Retur"
                                                data-reason-input-name="reason"
                                                data-reason-label="Alasan Pembatalan"
                                                data-reason-placeholder="Masukkan alasan pembatalan retur POS (opsional)"
                                            >
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

    <div class="modal fade" id="posReturnActionModal" tabindex="-1" aria-labelledby="posReturnActionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posReturnActionModalLabel">Konfirmasi Aksi</h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-3" id="pos-return-action-message"></p>
                    <div class="form-group d-none" id="pos-return-action-reason-group">
                        <label for="pos-return-action-reason-input" class="form-label" id="pos-return-action-reason-label">Alasan</label>
                        <textarea class="form-control" id="pos-return-action-reason-input" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" id="pos-return-action-confirm-button">Lanjutkan</button>
                </div>
            </div>
        </div>
    </div>
@endsection
