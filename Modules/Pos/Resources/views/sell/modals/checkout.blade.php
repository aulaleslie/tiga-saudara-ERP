    <div class="modal fade" id="pos-checkout-modal" tabindex="-1" role="dialog" aria-labelledby="pos-checkout-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-checkout-modal-label">Pembayaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-0">
                    <div class="row no-gutters">
                        <div class="col-lg-7 border-right bg-light p-4 d-none d-lg-block">
                            <h5 class="mb-3 text-muted">Ringkasan Pesanan</h5>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm">
                                    <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Harga</th>
                                        <th class="text-right">Total</th>
                                    </tr>
                                    </thead>
                                    <tbody id="pos-checkout-receipt-lines"></tbody>
                                </table>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Total Akhir</span>
                                <strong id="pos-checkout-receipt-total">Rp0</strong>
                            </div>
                        </div>

                        <div class="col-lg-5 p-4">
                            <div id="pos-checkout-error" class="alert alert-danger d-none"></div>

                            <!-- Task 4.1: Payment Composer Section -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold d-block mb-1">Metode Pembayaran</label>
                                <small class="text-info d-block mb-2">Catatan: Untuk multi payment, silakan masukkan pembayaran non-tunai (transfer/debit/kredit) terlebih dahulu, dan pembayaran tunai (cash) di akhir.</small>
                                <!-- Payment method search/picker -->
                                <div class="position-relative">
                                    <input type="text" id="pos-checkout-method-search" class="form-control"
                                           placeholder="Cari metode pembayaran..." autocomplete="off">
                                    <div id="pos-checkout-method-results" class="list-group position-absolute w-100"
                                         style="top: 100%; left: 0; right: 0; z-index: 1000; max-height: 200px; overflow-y: auto; display: none;"></div>
                                </div>
                            </div>

                            <!-- Task 4.1: Payment rows list -->
                            <div id="pos-checkout-payments-list" class="mb-3" style="max-height: 250px; overflow-y: auto;">
                                <!-- Payment rows rendered here -->
                            </div>

                            <!-- Task 4.1: Order Summary -->
                            <div class="form-group row mb-2">
                                <label class="col-sm-5 col-form-label font-weight-bold">Total Belanja</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-total-label" class="form-control-plaintext font-weight-bold text-primary" readonly value="Rp0">
                                </div>
                            </div>

                            <div class="form-group row mb-2">
                                <label class="col-sm-5 col-form-label font-weight-bold">Total Dibayar</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-amount-paid-summary" class="form-control-plaintext font-weight-bold text-info" readonly value="Rp0">
                                </div>
                            </div>

                            <div class="form-group row mb-2 bg-light p-2 rounded">
                                <label class="col-sm-5 col-form-label font-weight-bold">Sisa / Kembalian</label>
                                <div class="col-sm-7">
                                    <input type="text" id="pos-checkout-remaining-label" class="form-control-plaintext font-weight-bold h5 mb-0 text-right" readonly value="Rp0">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-lg" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-checkout-submit" class="btn btn-primary btn-lg px-5">Konfirmasi Pembayaran</button>
                </div>
            </div>
        </div>
    </div>
