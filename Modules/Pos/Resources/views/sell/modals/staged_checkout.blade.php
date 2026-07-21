    <div class="modal fade" id="pos-staged-checkout-modal" tabindex="-1" role="dialog" aria-labelledby="pos-staged-checkout-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-staged-checkout-modal-label">Pembayaran Bertahap</h5>
                    <button type="button" id="staged-payment-close-btn" class="close" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <!-- Error Alert -->
                    <div id="staged-payment-error" class="alert alert-danger d-none"></div>

                    <!-- Task 3.3: Payment Chain Display -->
                    <div class="mb-4">
                        <label class="small font-weight-bold text-muted d-block mb-2">Pembayaran Sudah Diproses</label>
                        <div id="staged-payment-chain" class="d-flex flex-wrap gap-2">
                            <p class="text-muted small mb-0">Belum ada pembayaran</p>
                        </div>
                    </div>

                    <!-- Remainder Display -->
                    <div class="alert alert-info mb-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold">Sisa Pembayaran:</span>
                            <span id="staged-remainder-amount" class="h4 mb-0 text-danger">Rp0</span>
                        </div>
                    </div>

                    <!-- Task 3.2: Payment Method Selection -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold d-block mb-1">Metode Pembayaran</label>
                        <small class="text-info d-block mb-2">Catatan: Untuk multi payment, silakan masukkan pembayaran non-tunai (transfer/debit/kredit) terlebih dahulu, dan pembayaran tunai (cash) di akhir.</small>
                        <div class="position-relative">
                            <!-- Task 1.1: Ensure payment method input has opaque white background with visible border
                                 Uses !important to override Bootstrap modal CSS that may render background as transparent.
                                 This ensures cashiers can clearly see and interact with the payment method dropdown. -->
                            <input type="text" id="staged-method-search" class="form-control"
                                   style="background-color: #fff !important; border: 1px solid #dee2e6 !important; color: #212529 !important;"
                                   placeholder="Pilih atau cari metode pembayaran..." autocomplete="off">
                            <div id="staged-method-results" class="list-group position-absolute w-100"
                                 style="top: 100%; left: 0; right: 0; z-index: 1000; max-height: 250px; overflow-y: auto; display: none; background-color: #fff !important; border: 1px solid #dee2e6 !important;"></div>
                        </div>
                    </div>

                    <!-- Amount Input -->
                    <div class="form-group mb-3">
                        <label for="staged-amount-input" class="font-weight-bold">Jumlah Pembayaran (Rp)</label>
                        <input type="text" id="staged-amount-input" class="form-control form-control-lg"
                               placeholder="0" inputmode="numeric">
                        <small id="staged-amount-hint" class="form-text mt-1 font-weight-bold" style="display: none;"></small>
                    </div>

                    <!-- Quick-Add Buttons -->
                    <div class="mb-3 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="1000">+1.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="2000">+2.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="5000">+5.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="10000">+10.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="20000">+20.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="50000">+50.000</button>
                        <button type="button" class="btn btn-sm btn-outline-primary js-quick-add" data-amount="100000">+100.000</button>
                        <button type="button" class="btn btn-sm btn-warning js-quick-add-remainder">Sisa</button>
                    </div>

                    <!-- Task 3.5 & 3.6: EDC Reference Input (Conditional) -->
                    <div id="staged-edc-reference-container" class="form-group mb-3" style="display: none;">
                        <label for="staged-edc-reference" class="font-weight-bold">Nomor Referensi EDC</label>
                        <input type="text" id="staged-edc-reference" class="form-control"
                               placeholder="Masukkan nomor referensi (alphanumeric, max 20 karakter)"
                               maxlength="20">
                        <small class="form-text text-muted">Format: Alphanumeric, maksimal 20 karakter</small>
                    </div>

                    <!-- Processing Spinner -->
                    <div id="staged-payment-spinner" class="text-center" style="display: none;">
                        <div class="spinner-border mb-3" role="status">
                            <span class="sr-only">Memproses...</span>
                        </div>
                        <p class="text-muted">Memproses pembayaran... Jangan tutup atau muat ulang halaman.</p>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-lg" data-dismiss="modal">Batal</button>
                    <button type="button" id="staged-payment-submit" class="btn btn-primary btn-lg px-5" disabled>
                        Lanjut Pembayaran
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal for Staged Checkout (Task 1.1, 1.2, 1.3) -->
    <div class="modal fade" id="pos-payment-confirmation-modal" tabindex="-1" role="dialog" aria-labelledby="pos-payment-confirmation-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-payment-confirmation-modal-label">Konfirmasi Pembayaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">Silakan periksa kembali detail pembayaran berikut sebelum melanjutkan:</p>
                    
                    <ul class="list-group mb-4">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>Metode Pembayaran</strong>
                            <span id="confirm-payment-method">-</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>Sisa Tagihan Sebelumnya</strong>
                            <span id="confirm-remaining-balance" class="text-danger font-weight-bold">Rp0</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <strong>Jumlah yang Dimasukkan</strong>
                            <span id="confirm-entered-amount" class="text-primary font-weight-bold">Rp0</span>
                        </li>
                    </ul>

                    <!-- Dynamic Alert Container -->
                    <div id="confirm-payment-alert" class="alert d-none" role="alert"></div>
                </div>
                <div class="modal-footer bg-light">
                    <!-- Task 1.3 -->
                    <button type="button" id="confirm-payment-cancel-btn" class="btn btn-secondary btn-lg" data-dismiss="modal">Batal</button>
                    <button type="button" id="confirm-payment-proceed-btn" class="btn btn-primary btn-lg px-5">Lanjutkan</button>
                </div>
            </div>
        </div>
    </div>
