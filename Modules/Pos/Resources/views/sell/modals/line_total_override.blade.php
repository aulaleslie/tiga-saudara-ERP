{{--
    Row-total override modal (LINE_TOTAL_OVERRIDE).

    Separate identifiers, state, and handlers from the unit-price modal so the
    two operations can never share mutable client state.
--}}
<div class="modal fade" id="pos-line-total-override-modal" tabindex="-1" role="dialog" aria-labelledby="pos-line-total-override-modal-label" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pos-line-total-override-modal-label">
                    <i class="bi bi-calculator mr-1"></i> Ubah Total Baris
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="small font-weight-bold text-muted mb-1">Produk:</label>
                    <div id="pos-line-total-override-product" class="form-control-plaintext font-weight-bold"></div>
                </div>

                <div class="form-group mb-3">
                    <label class="small font-weight-bold">Total Baris Saat Ini:</label>
                    <div id="pos-line-total-override-current" class="form-control-plaintext font-weight-bold text-info" style="font-size: 1.25rem;"></div>
                </div>

                <div class="form-group mb-3">
                    <label for="pos-line-total-override-new" class="small font-weight-bold">Total Baris Baru:</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">Rp</span>
                        </div>
                        <input id="pos-line-total-override-new" type="number" class="form-control" min="0" step="any" value="0">
                    </div>
                    <small id="pos-line-total-override-error" class="form-text text-danger" style="display: none;"></small>
                    <small class="form-text text-muted">Total akhir baris setelah diskon baris, sebelum diskon nota.</small>
                </div>

                <div class="form-group mb-0">
                    <label for="pos-line-total-override-reason" class="small font-weight-bold">Alasan (Opsional):</label>
                    <textarea id="pos-line-total-override-reason" class="form-control" rows="3" placeholder="Tulis alasan perubahan total baris..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" id="pos-line-total-override-submit" class="btn btn-info" disabled>Terapkan</button>
            </div>
        </div>
    </div>
</div>
