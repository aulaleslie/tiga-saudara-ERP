<div class="modal fade" id="pos-total-override-modal" tabindex="-1" role="dialog" aria-labelledby="pos-total-override-modal-label" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pos-total-override-modal-label">Ubah Total Keranjang</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Total Saat Ini:</label>
                    <div id="pos-total-override-current" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.25rem;"></div>
                </div>

                <div class="form-group mb-3">
                    <label for="pos-total-override-new" class="small font-weight-bold">Total Baru:</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">Rp</span>
                        </div>
                        <input id="pos-total-override-new" type="number" class="form-control" min="0" value="0">
                    </div>
                    <small id="pos-total-override-error" class="form-text text-danger" style="display: none;"></small>
                </div>

                <div class="form-group mb-0">
                    <label for="pos-total-override-reason" class="small font-weight-bold">Alasan (Opsional):</label>
                    <textarea id="pos-total-override-reason" class="form-control" rows="3" placeholder="Tulis alasan perubahan total..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" id="pos-total-override-submit" class="btn btn-primary" disabled>Terapkan</button>
            </div>
        </div>
    </div>
</div>
