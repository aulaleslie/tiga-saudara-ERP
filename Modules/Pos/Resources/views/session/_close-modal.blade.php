<div class="modal fade" id="closeModal" tabindex="-1" aria-labelledby="closeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header bg-danger text-white py-3" style="border-radius: 15px 15px 0 0;">
                <h5 class="modal-title fw-bold" id="closeModalLabel">
                    <i class="bi bi-power me-2"></i>Tutup Sesi POS
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="closeSessionForm">
                <div class="modal-body p-4">
                    <p class="text-muted">Apakah Anda yakin ingin menutup sesi POS ini? Tindakan ini akan menghentikan semua aktivitas penjualan pada terminal ini.</p>
                    
                    <div class="mb-3">
                        <label for="close_reason" class="form-label fw-bold">Alasan Penutupan (Opsional)</label>
                        <textarea class="form-control" id="close_reason" name="reason" rows="3" placeholder="Contoh: Akhir shift, pergantian kasir..." style="border-radius: 10px;"></textarea>
                    </div>

                    <div class="alert alert-info border-0 shadow-sm mb-0" style="border-radius: 10px;">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Setelah ditutup, sesi akan masuk ke tahap rekonsiliasi sebelum difinalisasi oleh supervisor.
                    </div>
                </div>
                <div class="modal-footer border-0 p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4" id="confirmCloseBtn">
                        <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
                        Ya, Tutup Sesi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
