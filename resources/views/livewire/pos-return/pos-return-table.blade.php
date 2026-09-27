<div>
    @php
        $canEdit = auth()->user()?->can('pos.returns.edit');
        $canDelete = auth()->user()?->can('pos.returns.delete');
        $canSubmitDraft = auth()->user()?->can('pos.returns.create') || auth()->user()?->can('pos.returns.edit');
        $canApprove = auth()->user()?->can('pos.returns.approve');
    @endphp

    @once
        @push('page_scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.posReturnListModalsInitialized) {
                        return;
                    }

                    window.posReturnListModalsInitialized = true;

                    const actionModalEl = document.getElementById('posReturnListActionModal');
                    const actionTitleEl = document.getElementById('posReturnListActionModalLabel');
                    const actionMessageEl = document.getElementById('pos-return-list-action-message');
                    const reasonGroupEl = document.getElementById('pos-return-list-action-reason-group');
                    const reasonLabelEl = document.getElementById('pos-return-list-action-reason-label');
                    const reasonInputEl = document.getElementById('pos-return-list-action-reason-input');
                    const confirmButtonEl = document.getElementById('pos-return-list-action-confirm-button');

                    if (! actionModalEl || ! actionTitleEl || ! actionMessageEl || ! reasonGroupEl || ! reasonLabelEl || ! reasonInputEl || ! confirmButtonEl) {
                        return;
                    }

                    let actionModalInstance = null;

                    const hasJQueryModal = () => window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function';

                    const getModalInstance = (element, currentInstance) => {
                        if (! window.bootstrap || ! window.bootstrap.Modal) {
                            return currentInstance;
                        }

                        if (! currentInstance) {
                            currentInstance = typeof window.bootstrap.Modal.getOrCreateInstance === 'function'
                                ? window.bootstrap.Modal.getOrCreateInstance(element)
                                : new window.bootstrap.Modal(element);
                        }

                        return currentInstance;
                    };

                    const showModal = (element, currentInstance, backdropId) => {
                        currentInstance = getModalInstance(element, currentInstance);

                        if (currentInstance) {
                            currentInstance.show();
                            return currentInstance;
                        }

                        if (hasJQueryModal()) {
                            window.jQuery(element).modal('show');
                            return currentInstance;
                        }

                        element.classList.add('show');
                        element.style.display = 'block';
                        element.removeAttribute('aria-hidden');
                        document.body.classList.add('modal-open');

                        if (! document.getElementById(backdropId)) {
                            const backdrop = document.createElement('div');
                            backdrop.className = 'modal-backdrop fade show';
                            backdrop.id = backdropId;
                            document.body.appendChild(backdrop);
                        }

                        return currentInstance;
                    };

                    const hideModal = (element, currentInstance, backdropId, resetCallback) => {
                        currentInstance = getModalInstance(element, currentInstance);

                        if (currentInstance) {
                            currentInstance.hide();
                            return currentInstance;
                        }

                        if (hasJQueryModal()) {
                            window.jQuery(element).modal('hide');
                            return currentInstance;
                        }

                        element.classList.remove('show');
                        element.style.display = 'none';
                        element.setAttribute('aria-hidden', 'true');
                        document.body.classList.remove('modal-open');

                        const backdrop = document.getElementById(backdropId);

                        if (backdrop) {
                            backdrop.remove();
                        }

                        if (typeof resetCallback === 'function') {
                            resetCallback();
                        }

                        return currentInstance;
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

                    document.addEventListener('click', function (event) {
                        const actionTrigger = event.target.closest('[data-pos-return-list-modal-trigger]');

                        if (actionTrigger) {
                            actionModalEl.dataset.activeFormId = actionTrigger.dataset.formId || '';
                            actionModalEl.dataset.activeReasonInputName = actionTrigger.dataset.reasonInputName || '';
                            actionTitleEl.textContent = actionTrigger.dataset.modalTitle || 'Konfirmasi Aksi';
                            actionMessageEl.textContent = actionTrigger.dataset.modalMessage || '';
                            reasonLabelEl.textContent = actionTrigger.dataset.reasonLabel || 'Alasan';
                            reasonInputEl.value = '';
                            reasonInputEl.placeholder = actionTrigger.dataset.reasonPlaceholder || '';
                            reasonInputEl.required = actionTrigger.dataset.reasonRequired === 'true';
                            reasonGroupEl.classList.toggle('d-none', ! actionModalEl.dataset.activeReasonInputName);
                            confirmButtonEl.textContent = actionTrigger.dataset.submitLabel || 'Lanjutkan';
                            actionModalInstance = showModal(actionModalEl, actionModalInstance, 'pos-return-list-action-modal-backdrop');
                            return;
                        }

                        const dismissButton = event.target.closest('[data-dismiss="modal"], [data-bs-dismiss="modal"]');

                        if (! dismissButton) {
                            return;
                        }

                        const modalEl = dismissButton.closest('.modal');

                        if (modalEl === actionModalEl && ! getModalInstance(actionModalEl, actionModalInstance) && ! hasJQueryModal()) {
                            actionModalInstance = hideModal(actionModalEl, actionModalInstance, 'pos-return-list-action-modal-backdrop', resetActionModal);
                        }
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

                    resetActionModal();
                });
            </script>
        @endpush
    @endonce

    <div class="row mb-3">
        <div class="col-md-3">
            <input wire:model.live="search" type="text" class="form-control" placeholder="Cari Ref, Transaksi, Struk, Pelanggan...">
        </div>
        <div class="col-md-2">
            <select wire:model.live="statusFilter" class="form-control">
                <option value="">Semua Status</option>
                @foreach(\Modules\Pos\Entities\PosReturn::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th wire:click="sortBy('reference')" style="cursor: pointer;">
                        Reference {!! $sortField === 'reference' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                    </th>
                    <th wire:click="sortBy('transaction_code')" style="cursor: pointer;">
                        Transaksi {!! $sortField === 'transaction_code' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                    </th>
                    <th>Customer</th>
                    <th>Opsi</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($returns as $return)
                    @php
                        $requiresManualCorrection = ! empty($return->manual_correction_required_at);
                        $approvalStatus = strtolower((string) $return->approval_status);
                    @endphp
                    <tr>
                        <td>{{ $return->reference }}</td>
                        <td>
                            <div>{{ $return->transaction_code }}</div>
                            <small class="text-muted">{{ $return->receipt_number }}</small>
                        </td>
                        <td>{{ $return->customer_name }}</td>
                        <td>
                            <span class="badge badge-info">
                                {{ \Modules\Pos\Entities\PosReturn::OPTION_LABELS[$return->return_option] ?? $return->return_option }}
                            </span>
                        </td>
                        <td>{{ format_currency($return->total_amount) }}</td>
                        <td>
                            <span class="badge badge-{{ $return->status_color }}">
                                {{ $return->status_label }}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="{{ route('pos.returns.show', $return) }}" class="btn btn-sm btn-primary" title="Lihat Detail">
                                    <i class="bi bi-eye"></i>
                                </a>

                                @if($return->isRevisionEditable() && ! $requiresManualCorrection)
                                    @if($canEdit)
                                        <a href="{{ route('pos.returns.edit', $return) }}" class="btn btn-sm btn-outline-primary" title="{{ $return->isRejectedEditable() ? 'Edit Retur Ditolak' : 'Edit Draft' }}">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    @endif

                                    @if($canDelete)
                                        @if($return->isHardDeletable())
                                            <form id="pos-return-list-delete-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.destroy', $return) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Hapus Draft"
                                                    data-pos-return-list-modal-trigger
                                                    data-form-id="pos-return-list-delete-form-{{ $return->id }}"
                                                    data-modal-title="Hapus Draft Retur POS"
                                                    data-modal-message="Hapus permanen retur POS draft ini? Tindakan ini tidak dapat dibatalkan."
                                                    data-submit-label="Hapus Draft"
                                                >
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        @elseif($return->isRejectedSoftDeletable())
                                            <form id="pos-return-list-delete-rejected-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.destroy', $return) }}" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="delete_reason" value="">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Hapus Retur Ditolak"
                                                    data-pos-return-list-modal-trigger
                                                    data-form-id="pos-return-list-delete-rejected-form-{{ $return->id }}"
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
                                        @endif
                                    @endif

                                    @if($return->isDraftSubmittable() && $canSubmitDraft)
                                        <form id="pos-return-list-submit-draft-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.submit-draft', $return) }}" class="d-inline">
                                            @csrf
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-success"
                                                title="Ajukan Persetujuan"
                                                data-pos-return-list-modal-trigger
                                                data-form-id="pos-return-list-submit-draft-form-{{ $return->id }}"
                                                data-modal-title="Ajukan Persetujuan"
                                                data-modal-message="Ajukan retur POS draft ini untuk persetujuan?"
                                                data-submit-label="Ajukan Persetujuan"
                                            >
                                                <i class="bi bi-send"></i> Ajukan Persetujuan
                                            </button>
                                        </form>
                                    @endif
                                @endif

                                @if($approvalStatus === 'pending' && ! $requiresManualCorrection && $canApprove)
                                    <a
                                        href="{{ route('pos.returns.approval-preview', $return) }}"
                                        class="btn btn-sm btn-success"
                                        title="Preview Persetujuan Retur"
                                    >
                                        <i class="bi bi-check2-circle"></i> Preview Persetujuan
                                    </a>

                                    <form id="pos-return-list-reject-form-{{ $return->id }}" method="POST" action="{{ route('pos.returns.reject', $return) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Tolak Retur"
                                            data-pos-return-list-modal-trigger
                                            data-form-id="pos-return-list-reject-form-{{ $return->id }}"
                                            data-modal-title="Tolak Retur POS"
                                            data-modal-message="Masukkan alasan penolakan jika diperlukan, lalu lanjutkan untuk menolak retur POS ini."
                                            data-submit-label="Tolak Retur"
                                            data-reason-input-name="reason"
                                            data-reason-label="Alasan Penolakan"
                                            data-reason-placeholder="Masukkan alasan penolakan (opsional)"
                                        >
                                            <i class="bi bi-x-circle"></i> Tolak
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">Tidak ada data retur.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        {{ $returns->links() }}
    </div>

    <div class="modal fade" id="posReturnListActionModal" tabindex="-1" aria-labelledby="posReturnListActionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posReturnListActionModalLabel">Konfirmasi Aksi</h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-3" id="pos-return-list-action-message"></p>
                    <div class="form-group d-none" id="pos-return-list-action-reason-group">
                        <label for="pos-return-list-action-reason-input" class="form-label" id="pos-return-list-action-reason-label">Alasan</label>
                        <textarea class="form-control" id="pos-return-list-action-reason-input" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" id="pos-return-list-action-confirm-button">Lanjutkan</button>
                </div>
            </div>
        </div>
    </div>
</div>
