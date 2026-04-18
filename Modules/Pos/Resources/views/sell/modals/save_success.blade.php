    <div class="modal fade" id="pos-save-success-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success-light text-success rounded-circle" style="width: 80px; height: 80px; background-color: rgba(40, 167, 69, 0.1);">
                            <i class="fas fa-check" style="font-size: 2.5rem;"></i>
                        </div>
                    </div>
                    <h3 class="font-weight-bold mb-2">Simpan Berhasil</h3>
                    <p class="text-muted mb-4">Transaksi draft telah diamankan dengan nomor:</p>
                    <div class="bg-light py-2 px-4 rounded mb-4 d-inline-block border">
                        <span class="h2 font-weight-bold mb-0 text-dark" id="pos-save-success-trx-code">-</span>
                    </div>
                    <div class="px-4">
                        <button type="button" class="btn btn-primary btn-lg py-3 font-weight-bold btn-block mb-3" id="pos-save-success-continue-btn" style="border-radius: 0.75rem;">
                            Buka Keranjang Baru
                        </button>
                        <button type="button" class="btn btn-outline-info btn-lg py-3 font-weight-bold btn-block" id="pos-save-success-print-btn" style="border-radius: 0.75rem;">
                            <i class="fas fa-print mr-2"></i> Cetak Struk Draft
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
