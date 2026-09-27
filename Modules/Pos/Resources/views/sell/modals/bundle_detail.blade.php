<div class="modal fade" id="pos-bundle-detail-modal" tabindex="-1" role="dialog" aria-labelledby="pos-bundle-detail-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title font-weight-bold" id="pos-bundle-detail-modal-label">Detail Paket</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Bundle Header -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1 pr-3">
                            <h4 id="pos-bundle-detail-name" class="font-weight-bold mb-1 text-primary"></h4>
                            <p id="pos-bundle-detail-parent" class="text-muted mb-0"></p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="badge badge-primary px-3 py-2" style="border-radius: 0.5rem; font-size: 0.9rem;">
                                <span id="pos-bundle-detail-qty">0</span> Unit
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Price Composition Card -->
                <div class="card bg-light border-0 mb-4" style="border-radius: 0.75rem;">
                    <div class="card-body p-3">
                        <h6 class="font-weight-bold mb-3">Komposisi Harga (Per Unit)</h6>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Harga Produk</span>
                            <span id="pos-bundle-detail-base-price" class="font-weight-bold">Rp 0</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Tambahan Paket</span>
                            <span id="pos-bundle-detail-addon-price" class="text-success font-weight-bold">+ Rp 0</span>
                        </div>
                        
                        <hr class="my-2 border-top" style="border-style: dashed;">
                        
                        <div class="d-flex justify-content-between mb-2 font-weight-bold">
                            <span>Harga Satuan</span>
                            <span id="pos-bundle-detail-unit-price">Rp 0</span>
                        </div>

                        <div class="mt-3 p-3 bg-primary text-white" style="border-radius: 0.5rem;">
                            <div class="d-flex justify-content-between align-items-center mb-0">
                                <span class="font-weight-bold" id="pos-bundle-detail-subtotal-label">Subtotal Baris (0 Unit)</span>
                                <h4 class="font-weight-bold mb-0" id="pos-bundle-detail-line-total">Rp 0</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bundle Items List -->
                <div>
                    <h6 class="font-weight-bold mb-3">Isi Paket & Serial</h6>
                    <div id="pos-bundle-detail-items-container" style="max-height: 400px; overflow-y: auto;">
                        <ul id="pos-bundle-detail-items" class="list-group list-group-flush">
                            <!-- Items will be rendered here via JS -->
                        </ul>
                    </div>
                    <!-- Empty State -->
                    <div id="pos-bundle-detail-empty-items" class="text-center py-4 d-none">
                        <i class="fas fa-box-open fa-3x text-light mb-3"></i>
                        <p class="text-muted mb-0 font-italic" style="font-size: 0.9rem;">Tidak ada detail item paket tersedia di snapshot cart.</p>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer bg-light border-top-0" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                <button type="button" class="btn btn-secondary font-weight-bold btn-block shadow-sm" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom styles for the bundle detail modal */
#pos-bundle-detail-items .list-group-item {
    background-color: transparent;
    padding: 1rem 0.5rem;
    border-color: rgba(0,0,0,0.05);
}

#pos-bundle-detail-items .list-group-item:last-child {
    border-bottom: 0;
}

#pos-bundle-detail-items-container::-webkit-scrollbar {
    width: 6px;
}

#pos-bundle-detail-items-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

#pos-bundle-detail-items-container::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 10px;
}

#pos-bundle-detail-items-container::-webkit-scrollbar-thumb:hover {
    background: #999;
}
</style>
