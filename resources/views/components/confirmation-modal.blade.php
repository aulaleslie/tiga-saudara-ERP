<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalLabel">{{ $title ?? 'Konfirmasi' }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="confirmationModalBody">
                {{ $message ?? 'Apakah Anda yakin?' }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
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

        $('#confirmationModal').modal('show');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const confirmBtn = document.getElementById('confirmModalSubmit');
        const modalEl = document.getElementById('confirmationModal');

        if (modalEl) {
            $(modalEl).on('hide.bs.modal', () => restoreConfirmationFocus(modalEl));
            $(modalEl).on('hidden.bs.modal', () => {
                confirmationCallback = null;
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (!confirmationCallback) {
                    $('#confirmationModal').modal('hide');
                    return;
                }

                const callback = confirmationCallback;
                confirmationCallback = null;

                restoreConfirmationFocus(modalEl);

                if (typeof callback === 'function') {
                    callback();
                }

                $('#confirmationModal').modal('hide');
            });
        }
    });
</script>
