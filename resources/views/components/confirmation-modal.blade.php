<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
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
    let confirmationFormId = null;
    let confirmationCallback = null;

    function showConfirmationModal(callback, message = "Apakah Anda yakin?") {
        document.getElementById('confirmationModalBody').textContent = message;
        confirmationCallback = callback;

        const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        confirmationModal.show();
    }

    document.getElementById('confirmModalSubmit').addEventListener('click', function () {
        if (confirmationCallback) {
            confirmationCallback();
        }
    });
</script>
