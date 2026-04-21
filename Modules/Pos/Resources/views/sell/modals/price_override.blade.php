<div class="modal fade" id="pos-price-override-modal" tabindex="-1" role="dialog" aria-labelledby="pos-price-override-modal-label" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pos-price-override-modal-label">Ubah Harga</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Harga Saat Ini:</label>
                    <div id="pos-price-override-current" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.25rem;"></div>
                </div>

                <div class="form-group mb-3">
                    <label for="pos-price-override-new" class="small font-weight-bold">Harga Baru:</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">Rp</span>
                        </div>
                        <input id="pos-price-override-new" type="number" class="form-control" min="0" value="0">
                    </div>
                    <small id="pos-price-override-error" class="form-text text-danger" style="display: none;"></small>
                </div>

                <div class="form-group mb-0">
                    <label for="pos-price-override-reason" class="small font-weight-bold">Alasan (Opsional):</label>
                    <textarea id="pos-price-override-reason" class="form-control" rows="3" placeholder="Tulis alasan perubahan harga..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" id="pos-price-override-submit" class="btn btn-primary" disabled>Terapkan</button>
            </div>
        </div>
    </div>
</div>
