    <div class="modal fade" id="pos-serial-modal" tabindex="-1" role="dialog" aria-labelledby="pos-serial-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-serial-modal-label">Input Nomor Serial</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-serial-modal-info" class="alert alert-info py-2 mb-3">
                        <small id="pos-serial-modal-product-name" class="font-weight-bold d-block"></small>
                        <small id="pos-serial-modal-qty-info"></small>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label for="pos-serial-modal-input" class="small font-weight-bold">Scan atau ketik serial:</label>
                        <div class="input-group">
                            <input id="pos-serial-modal-input" type="text" class="form-control" autocomplete="off" placeholder="Nomor Serial...">
                            <div class="input-group-append">
                                <button id="pos-serial-modal-submit" type="button" class="btn btn-primary">Masukkan</button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Tekan Enter atau klik Masukkan untuk menambah serial.</small>
                    </div>
                    
                    <div id="pos-serial-modal-status" class="small mb-3" style="min-height: 1.25rem;"></div>
                    
                    <div class="border-top pt-3">
                        <label class="small font-weight-bold mb-2">Serial Terinput:</label>
                        <div id="pos-serial-modal-list" class="d-flex flex-wrap" style="max-height: 150px; overflow-y: auto; gap: 4px;">
                            <!-- Serials will be listed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
