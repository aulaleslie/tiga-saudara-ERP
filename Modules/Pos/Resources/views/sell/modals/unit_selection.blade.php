    <div class="modal fade" id="pos-unit-selection-modal" tabindex="-1" role="dialog" aria-labelledby="pos-unit-selection-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title font-weight-bold" id="pos-unit-selection-modal-label">Pilih Satuan Penjualan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-unit-modal-product-info" class="mb-4">
                        <h4 id="pos-unit-product-name" class="font-weight-bold mb-1 text-primary"></h4>
                        <p class="text-muted">Pilih satuan unit untuk menambahkan produk ke keranjang dalam jumlah kelipatan satuan terkait.</p>
                    </div>

                    <div id="pos-unit-error" class="alert alert-danger d-none"></div>

                    <div id="pos-unit-options" class="row g-3">
                        <!-- Unit options rendered dynamically -->
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Batal</button>
                </div>
            </div>
        </div>
    </div>
