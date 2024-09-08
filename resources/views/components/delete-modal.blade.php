<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">{{ $title ?? 'Konfirmasi Penghapusan' }}</h5>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="modal-body" id="deleteModalBody">
                {{ $message ?? 'Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!' }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Hapus</button>
            </div>
        </div>
    </div>
</div>

<script>
    let deleteFormId;

    function showDeleteModal(id, message = "{{ $message ?? 'Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!' }}") {
        deleteFormId = id;
        document.getElementById('deleteModalBody').textContent = message;
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        document.getElementById('destroy' + deleteFormId).submit();
    });
</script>
