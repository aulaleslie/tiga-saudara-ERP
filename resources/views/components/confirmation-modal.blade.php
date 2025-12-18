<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalLabel">{{ $title ?? 'Konfirmasi' }}</h5>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="modal-body" id="confirmationModalBody">
                {{ $message ?? 'Apakah Anda yakin?' }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="confirmModalSubmit">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<script>
    let confirmationCallback = null;
    let lastFocusedElement = null;
    let confirmationModalInstance = null;
    let confirmationModalElement = null;

    function clearModalFocus(modalEl) {
        if (!modalEl) {
            return;
        }

        const activeElement = document.activeElement;
        if (activeElement && modalEl.contains(activeElement) && typeof activeElement.blur === 'function') {
            activeElement.blur();
        }
    }

    function restoreConfirmationFocus(modalEl = null) {
        const modal = modalEl || confirmationModalElement;
        const isAriaDisabled = lastFocusedElement
            && lastFocusedElement.getAttribute
            && lastFocusedElement.getAttribute('aria-disabled') === 'true';
        const isDisabled = lastFocusedElement
            && (lastFocusedElement.hasAttribute('disabled') || lastFocusedElement.disabled === true || isAriaDisabled);
        const canFocusTarget = lastFocusedElement
            && lastFocusedElement.isConnected
            && typeof lastFocusedElement.focus === 'function'
            && !isDisabled;

        if (canFocusTarget) {
            lastFocusedElement.focus();
        } else {
            clearModalFocus(modal);
        }

        if (modal && modal.contains(document.activeElement)) {
            clearModalFocus(modal);
        }

        lastFocusedElement = null;
    }

    function showConfirmationModal(callback, message = "Apakah Anda yakin?") {
        const modalEl = document.getElementById('confirmationModal');
        const bodyEl = document.getElementById('confirmationModalBody');

        if (!modalEl) {
            console.warn('Confirmation modal element not found.');
            return;
        }

        confirmationModalElement = modalEl;

        if (bodyEl) {
            bodyEl.textContent = message;
        }

        confirmationCallback = callback;
        lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            console.warn('Bootstrap modal is not available.');
            return;
        }

        confirmationModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        confirmationModalInstance.show();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const confirmBtn = document.getElementById('confirmModalSubmit');
        const modalEl = document.getElementById('confirmationModal');

        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            confirmationModalElement = modalEl;
            confirmationModalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalEl.addEventListener('hide.bs.modal', () => restoreConfirmationFocus(modalEl));
            modalEl.addEventListener('hidden.bs.modal', () => {
                confirmationCallback = null;
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (!confirmationCallback) {
                    if (confirmationModalInstance) {
                        confirmationModalInstance.hide();
                    }
                    return;
                }

                const callback = confirmationCallback;
                confirmationCallback = null;

                restoreConfirmationFocus(modalEl);

                if (typeof callback === 'function') {
                    callback();
                }

                if (confirmationModalInstance) {
                    confirmationModalInstance.hide();
                } else if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }
            });
        }
    });
</script>
