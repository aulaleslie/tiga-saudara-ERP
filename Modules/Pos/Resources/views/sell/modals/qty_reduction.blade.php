    <div class="modal fade" id="pos-reduce-quantity-modal" tabindex="-1" role="dialog" aria-labelledby="pos-reduce-quantity-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-reduce-quantity-modal-label">Kurangi Jumlah</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="small font-weight-bold">Jumlah Saat Ini:</label>
                        <div id="pos-reduce-qty-current" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.25rem;"></div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="pos-reduce-qty-new" class="small font-weight-bold">Jumlah Baru:</label>
                        <input id="pos-reduce-qty-new" type="number" class="form-control" min="1" max="1" value="1">
                        <small id="pos-reduce-qty-error" class="form-text text-danger" style="display: none;"></small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="pos-reduce-qty-reason" class="small font-weight-bold">Alasan (Opsional):</label>
                        <textarea id="pos-reduce-qty-reason" class="form-control" rows="3" placeholder="Tulis alasan pengurangan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-reduce-qty-submit" class="btn btn-primary" disabled>Minta Persetujuan</button>
                </div>
            </div>
        </div>
    </div>
