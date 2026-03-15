<div class="modal fade" id="closeAdminModal" tabindex="-1" aria-labelledby="closeAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title" id="closeAdminModalLabel">
                    <i class="bi bi-lock"></i> Tutup Terminal (Admin)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="closeAdminForm">
                <div class="modal-body">
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <strong>Perhatian!</strong> Tindakan ini akan segera menutup terminal tanpa menunggu pencatatan kas kasir.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Terminal</label>
                            <div id="sessionCode" class="fw-bold"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Kasir</label>
                            <div id="cashierName" class="fw-bold"></div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Dibuka</label>
                            <div id="openedAt" class="fw-bold"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Durasi</label>
                            <div id="sessionDuration" class="fw-bold"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Saldo Awal</label>
                        <div id="openingFloat" class="fw-bold"></div>
                    </div>

                    <div class="mb-3">
                        <label for="closeAdminReason" class="form-label">Alasan Penutupan (Opsional)</label>
                        <textarea class="form-control" id="closeAdminReason" name="reason" rows="3" placeholder="Masukkan alasan penutupan terminal..."></textarea>
                        <small class="text-muted">Alasan akan disimpan untuk audit trail.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-lock"></i> Tutup Terminal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
