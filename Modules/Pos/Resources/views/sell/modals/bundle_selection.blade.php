    <div class="modal fade" id="pos-bundle-selection-modal" tabindex="-1" role="dialog" aria-labelledby="pos-bundle-selection-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title font-weight-bold" id="pos-bundle-selection-modal-label">Pilih Paket Penjualan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-bundle-modal-product-info" class="mb-4">
                        <h4 id="pos-bundle-parent-name" class="font-weight-bold mb-1 text-primary"></h4>
                        <p class="text-muted">Pilih paket kombinasi untuk mendapatkan harga khusus, atau lanjut dengan harga normal.</p>
                    </div>
                    
                    <div id="pos-bundle-loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Memuat...</span>
                        </div>
                        <p class="mt-2 text-muted">Memuat pilihan paket...</p>
                    </div>

                    <div id="pos-bundle-error" class="alert alert-danger d-none"></div>

                    <div id="pos-bundle-options" class="row g-3">
                        <!-- Bundle options will be rendered here -->
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-bundle-continue-normal" class="btn btn-primary px-4 font-weight-bold">
                        Lanjut Harga Normal
                    </button>
                </div>
            </div>
        </div>
    </div>
