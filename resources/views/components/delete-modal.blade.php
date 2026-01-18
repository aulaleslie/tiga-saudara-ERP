<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">{{ $title ?? 'Konfirmasi Penghapusan' }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="deleteModalBody">
                {{ $message ?? 'Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!' }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Hapus</button>
            </div>
        </div>
    </div>
</div>

<script>
    let deleteFormId;

    function showDeleteModal(id, message = "{{ $message ?? 'Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!' }}") {
        deleteFormId = id;
        console.log(deleteFormId)
        document.getElementById('deleteModalBody').textContent = message;
        $('#deleteModal').modal('show');
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        document.getElementById('destroy' + deleteFormId).submit();
    });
</script>
