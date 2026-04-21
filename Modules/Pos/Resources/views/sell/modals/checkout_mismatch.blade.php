<div class="modal fade" id="pos-checkout-mismatch-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title font-weight-bold">
                    <i class="bi bi-exclamation-triangle-fill mr-2"></i>
                    Kesalahan Validasi Checkout
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="pos-mismatch-error-message" class="alert alert-danger mb-4">
                    Beberapa item di keranjang Anda tidak dapat diproses.
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover border">
                        <thead class="bg-light">
                            <tr>
                                <th>Produk</th>
                                <th class="text-center">Diminta</th>
                                <th class="text-center">Tersedia</th>
                                <th class="text-center text-danger">Selisih</th>
                            </tr>
                        </thead>
                        <tbody id="pos-mismatch-lines-body">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 text-muted small">
                    <p><strong>Kenapa ini terjadi?</strong> Stok produk mungkin telah diambil oleh transaksi lain atau baru saja diperbarui di sistem.</p>
                    <p>Silakan sesuaikan kuantitas di keranjang Anda sebelum melanjutkan pembayaran.</p>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup dan Perbaiki Keranjang</button>
            </div>
        </div>
    </div>
</div>

<style>
    #pos-checkout-mismatch-modal .table th {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    #pos-checkout-mismatch-modal .product-name {
        font-weight: 600;
        display: block;
    }
    #pos-checkout-mismatch-modal .product-meta {
        font-size: 0.75rem;
        color: #6c757d;
    }
</style>
