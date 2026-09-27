<div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success bg-opacity-10">
                <h5 class="modal-title" id="finalizeModalLabel">
                    <i class="bi bi-check-circle"></i> Finalisasi Sesi POS
                </h5>
                <button type="button" class="close btn-close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="finalizeForm">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Terminal</label>
                            <div id="finalizeSessionCode" class="fw-bold"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Kasir</label>
                            <div id="finalizeCashierName" class="fw-bold"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Status Sesi</label>
                            <div><span class="badge bg-secondary">SELESAI</span></div>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <h6 class="mb-3"><i class="bi bi-currency-dollar"></i> Ringkasan Penjualan</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Total Penjualan</label>
                                <div id="finalizeTotalSales" class="fw-bold"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Penjualan Kas</label>
                                <div id="finalizeCashSales" class="fw-bold"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Penjualan Non-Kas</label>
                                <div id="finalizeNonCashSales" class="fw-bold"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <h6 class="mb-3"><i class="bi bi-wallet2"></i> Perhitungan Ekspektasi Kas</h6>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <td class="text-muted">Saldo Awal</td>
                                        <td class="text-end fw-bold" id="finalizeOpeningFloat"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">+ Tunai Diterima</td>
                                        <td class="text-end fw-bold" id="finalizeCashTendered"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-4 text-secondary"><small>Penjualan Kas (net)</small></td>
                                        <td class="text-end text-secondary" id="finalizeCashSalesAmount"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-4 text-secondary"><small>- Kembalian</small></td>
                                        <td class="text-end text-secondary" id="finalizeChangeReturned"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">- Pengambilan Kas</td>
                                        <td class="text-end fw-bold" id="finalizeSafeDrops"></td>
                                    </tr>
                                    <tr class="border-top-2">
                                        <td class="text-muted"><strong>Kas Ekspektasi</strong></td>
                                        <td class="text-end fw-bold" id="finalizeExpectedCash"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="actualCashReceived" class="form-label">
                            <strong>Kas Aktual Diterima</strong>
                        </label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text">Rp</span>
                            <input type="number"
                                   class="form-control form-control-lg"
                                   id="actualCashReceived"
                                   name="actual_cash_received"
                                   step="0.01"
                                   min="0"
                                   placeholder="0.00"
                                   required>
                        </div>
                        <small class="text-muted d-block mt-2">Masukkan jumlah kas yang diterima dari kasir</small>
                    </div>

                    <div class="mb-3">
                        <h6 class="mb-2"><i class="bi bi-calculator"></i> Perhitungan Varians</h6>
                        <div class="alert alert-info alert-sm mb-0">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Varians</label>
                                    <div id="finalizeVarianceAmount" class="fw-bold display-6">Rp 0.00</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted">Batas Varians</label>
                                    <div id="finalizeVarianceThreshold" class="fw-bold display-6">Rp 0.00</div>
                                </div>
                            </div>
                            <div class="alert alert-warning alert-sm mt-2 mb-0" id="varianceWarning" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Varians melebihi batas!</strong> Persetujuan diperlukan untuk melanjutkan.
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="finalizeNotes" class="form-label">Catatan Finalisasi (Opsional)</label>
                        <textarea class="form-control" id="finalizeNotes" name="notes" rows="2" placeholder="Masukkan catatan atau memo..."></textarea>
                    </div>

                    {{-- Supervisor Override Section (Hidden by default) --}}
                    <div id="supervisorOverrideSection" class="p-3 bg-warning bg-opacity-10 border border-warning rounded" style="display: none;">
                        <h6 class="text-warning mb-3">
                            <i class="bi bi-shield-lock-fill"></i> Otorisasi Supervisor Diperlukan
                        </h6>
                        <div id="overrideErrorAlert" class="alert alert-danger alert-sm mb-3" style="display: none;"></div>
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="supervisorIdentifier" class="form-label small">Email Supervisor</label>
                                <input type="email" class="form-control form-control-sm" id="supervisorIdentifier" placeholder="supervisor@example.com">
                            </div>
                            <div class="col-md-6">
                                <label for="supervisorPassword" class="form-label small">Password/PIN</label>
                                <input type="password" class="form-control form-control-sm" id="supervisorPassword" placeholder="••••••••">
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">
                            Masukkan kredensial supervisor dengan izin <code>pos.sessions.approve-variance</code> untuk melanjutkan.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success" id="finalizeSubmitBtn">
                        <i class="bi bi-check-circle"></i> Finalisasi Sesi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
