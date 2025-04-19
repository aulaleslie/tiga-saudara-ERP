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

    function showConfirmationModal(callback, message = "Apakah Anda yakin?") {
        document.getElementById('confirmationModalBody').textContent = message;
        confirmationCallback = callback;

        const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();
    }

    // ✅ Wait until the DOM is ready before binding to the button
    document.addEventListener('DOMContentLoaded', function () {
        const confirmBtn = document.getElementById('confirmModalSubmit');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (confirmationCallback) {
                    confirmationCallback();

                    // Optional: Close the modal after confirming
                    const confirmationModal = bootstrap.Modal.getInstance(document.getElementById('confirmationModal'));
                    confirmationModal.hide();
                }
            });
        }
    });
</script>
