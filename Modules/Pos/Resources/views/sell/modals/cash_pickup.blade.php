
    <!-- Cash Pickup Modal -->
    <div class="modal fade" id="pos-cash-pickup-modal" tabindex="-1" role="dialog" aria-labelledby="pos-cash-pickup-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-cash-pickup-modal-label">Pengambilan Kas</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Step 1: Amount Input -->
                    <div id="pos-pickup-step-1">
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Terminal:</label>
                            <div id="pos-pickup-terminal-info" class="form-control-plaintext text-muted small"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Kasir:</label>
                            <div id="pos-pickup-cashier-info" class="form-control-plaintext text-muted small"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small font-weight-bold">Ekspektasi Kas:</label>
                            <div id="pos-pickup-expected-cash" class="form-control-plaintext font-weight-bold text-primary" style="font-size: 1.1rem;"></div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="pos-pickup-amount" class="form-label small font-weight-bold">Jumlah Pengambilan:</label>
                            <input type="text" id="pos-pickup-amount" class="form-control" inputmode="numeric" placeholder="0">
                            <small id="pos-pickup-amount-error" class="form-text text-danger d-none"></small>
                        </div>
                    </div>

                    <!-- Step 2: Supervisor OTP Verification -->
                    <div id="pos-pickup-step-2" class="d-none">
                        <div class="alert alert-info mb-3 small">
                            <strong>Konfirmasi Pengambilan:</strong>
                            <div id="pos-pickup-confirmation-amount" class="font-weight-bold mt-2"></div>
                        </div>
                        <!-- Supervisor Search -->
                        <div class="form-group mb-3">
                            <label for="pos-pickup-supervisor-search" class="form-label small font-weight-bold">Cari Supervisor:</label>
                            <input type="text" id="pos-pickup-supervisor-search" class="form-control" placeholder="Nama atau email supervisor...">
                            <div id="pos-pickup-supervisor-results" class="list-group mt-2" style="max-height: 200px; overflow-y: auto; display: none;"></div>
                        </div>
                        <!-- Selected Supervisor Display -->
                        <div id="pos-pickup-supervisor-selected" class="alert alert-light d-none mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="form-text text-muted">Supervisor Terpilih:</small>
                                    <div id="pos-pickup-supervisor-name" class="font-weight-bold"></div>
                                </div>
                                <button type="button" id="pos-pickup-supervisor-clear" class="btn btn-sm btn-secondary">Ubah</button>
                            </div>
                        </div>
                        <!-- OTP Code Input -->
                        <div class="form-group mb-3">
                            <label for="pos-pickup-otp-code" class="form-label small font-weight-bold">Kode OTP (6 digit):</label>
                            <input type="text" id="pos-pickup-otp-code" class="form-control text-center font-monospace" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
                        </div>
                        <small id="pos-pickup-step2-error" class="form-text text-danger d-none d-block mb-3"></small>
                        <div id="pos-pickup-spinner" class="spinner-border spinner-border-sm text-primary d-none" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- Step 1 Footer -->
                    <div id="pos-pickup-step-1-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="button" id="pos-pickup-next-btn" class="btn btn-primary" disabled>Lanjut</button>
                    </div>
                    <!-- Step 2 Footer -->
                    <div id="pos-pickup-step-2-footer" class="d-none w-100 d-flex justify-content-between">
                        <button type="button" id="pos-pickup-back-btn" class="btn btn-secondary">Kembali</button>
                        <button type="button" id="pos-pickup-confirm-btn" class="btn btn-primary" disabled>Konfirmasi Pengambilan</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
