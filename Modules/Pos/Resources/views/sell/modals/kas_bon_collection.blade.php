<!-- Kas Bon Collection Modal -->
<div class="modal fade" id="pos-kas-bon-modal" tabindex="-1" role="dialog" aria-labelledby="pos-kas-bon-modal-label" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold" id="pos-kas-bon-modal-label">
                    <i class="bi bi-wallet2 text-warning mr-2"></i> Pembayaran Kas Bon
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup" id="pos-kb-modal-close-x">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <!-- Top Alert -->
                <div id="pos-kb-global-alert" class="alert alert-danger d-none"></div>

                <!-- 1. CANDIDATES VIEW -->
                <div id="pos-kb-candidates-view">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-6">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                </div>
                                <input type="text" id="pos-kb-search-input" class="form-control" placeholder="Cari No. Transaksi, Pelanggan, Catatan, atau Nilai...">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" id="pos-kb-search-clear">Reset</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-right">
                            <span id="pos-kb-candidate-count" class="text-muted small mr-2">Memuat data...</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="pos-kb-refresh-candidates">
                                <i class="bi bi-arrow-clockwise"></i> Segarkan
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive border rounded" style="max-height: 450px; overflow-y: auto;">
                        <table class="table table-hover table-striped table-sm mb-0">
                            <thead class="thead-light sticky-top">
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Pelanggan</th>
                                    <th>Catatan</th>
                                    <th class="text-right">Total Awal</th>
                                    <th class="text-right">Tagihan Kini</th>
                                    <th class="text-right">Sudah Dibayar</th>
                                    <th class="text-right">Sisa Hutang</th>
                                    <th class="text-center" style="width: 110px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="pos-kb-candidates-body">
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <div class="spinner-border spinner-border-sm text-primary mr-1" role="status"></div> Memuat daftar Kas Bon...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div id="pos-kb-pagination-info" class="small text-muted">
                            Halaman 1 dari 1
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="pos-kb-prev-page" disabled>
                                <i class="bi bi-chevron-left"></i> Sebelumnya
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="pos-kb-next-page" disabled>
                                Selanjutnya <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2. REVIEW & PAYMENT VIEW -->
                <div id="pos-kb-review-view" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="pos-kb-back-to-candidates">
                            <i class="bi bi-arrow-left"></i> Kembali ke Daftar
                        </button>
                        <div>
                            <span class="badge badge-info px-2 py-1 mr-2" id="pos-kb-review-trx-code">TRX-...</span>
                            <button type="button" class="btn btn-sm btn-outline-info" id="pos-kb-reload-review" title="Segarkan rincian tagihan">
                                <i class="bi bi-arrow-clockwise"></i> Periksa Ulang
                            </button>
                        </div>
                    </div>

                    <div id="pos-kb-review-error" class="alert alert-warning d-none mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Pemberitahuan:</strong> <span id="pos-kb-review-error-msg"></span>
                            </div>
                            <button type="button" class="btn btn-sm btn-warning" id="pos-kb-review-error-refresh">
                                Segarkan Tagihan
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Left Column: Current Authoritative Bill Review -->
                        <div class="col-md-6 border-right">
                            <h6 class="font-weight-bold text-dark mb-2">Rincian Tagihan Terkini</h6>
                            
                            <div class="card bg-light mb-3">
                                <div class="card-body p-2 small">
                                    <div class="row">
                                        <div class="col-6"><strong>Pelanggan:</strong> <span id="pos-kb-detail-customer">-</span></div>
                                        <div class="col-6"><strong>No. Struk Asli:</strong> <span id="pos-kb-detail-receipt">-</span></div>
                                    </div>
                                    <div class="row mt-1">
                                        <div class="col-12 text-muted"><strong>Catatan:</strong> <span id="pos-kb-detail-note">-</span></div>
                                    </div>
                                </div>
                            </div>

                            <label class="small font-weight-bold text-muted mb-1">Daftar Item Saat Ini</label>
                            <div class="table-responsive border rounded mb-3" style="max-height: 220px; overflow-y: auto;">
                                <table class="table table-sm table-striped mb-0 small">
                                    <thead class="thead-light sticky-top">
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-right">Qty</th>
                                            <th class="text-right">Harga</th>
                                            <th class="text-right">Diskon</th>
                                            <th class="text-right">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pos-kb-items-body">
                                        <!-- populated dynamically -->
                                    </tbody>
                                </table>
                            </div>

                            <!-- Monetary Summary -->
                            <div class="card border-primary mb-3">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Total Tagihan Kini:</span>
                                        <span class="font-weight-bold" id="pos-kb-summary-total">Rp0</span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Sudah Dibayar Efektif:</span>
                                        <span class="font-weight-bold text-success" id="pos-kb-summary-paid">Rp0</span>
                                    </div>
                                    <div class="dropdown-divider"></div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="font-weight-bold text-dark">Sisa Hutang (Live Due):</span>
                                        <span class="h5 mb-0 font-weight-bold text-danger" id="pos-kb-summary-due">Rp0</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Settlement & Payment Methods Form -->
                        <div class="col-md-6">
                            <h6 class="font-weight-bold text-dark mb-2">Form Pembayaran</h6>

                            <!-- Payment Method Picker -->
                            <div class="form-group mb-2">
                                <label class="font-weight-bold small mb-1">Metode Pembayaran</label>
                                <div class="input-group">
                                    <select class="form-control" id="pos-kb-method-select">
                                        <option value="">-- Pilih Metode Pembayaran --</option>
                                    </select>
                                    <div class="input-group-append">
                                        <button class="btn btn-secondary" type="button" id="pos-kb-add-method-btn">
                                            <i class="bi bi-plus-lg"></i> Tambah
                                        </button>
                                    </div>
                                </div>
                                <small class="form-text text-info">Metode Kas Bon / Piutang tidak tersedia untuk pembayaran hutang.</small>
                            </div>

                            <!-- Active Methods Table -->
                            <div class="table-responsive border rounded mb-3" style="max-height: 240px; overflow-y: auto;">
                                <table class="table table-sm mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Metode</th>
                                            <th style="width: 140px;">Jumlah</th>
                                            <th style="width: 175px;">Keterangan</th>
                                            <th style="width: 40px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="pos-kb-methods-body">
                                        <!-- populated dynamically -->
                                    </tbody>
                                </table>
                            </div>

                            <!-- Real-time Payment Summary -->
                            <div class="alert alert-secondary p-2 mb-3 small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Total Pembayaran:</span>
                                    <strong id="pos-kb-calc-total-payment" class="text-primary font-weight-bold">Rp0</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Sisa Hutang Setelah Pembayaran:</span>
                                    <strong id="pos-kb-calc-remaining-debt" class="font-weight-bold">Rp0</strong>
                                </div>
                            </div>

                            <!-- Optional Notes -->
                            <div class="form-group mb-3">
                                <label for="pos-kb-input-note" class="small font-weight-bold">Catatan Pembayaran (Opsional)</label>
                                <input type="text" id="pos-kb-input-note" class="form-control form-control-sm" placeholder="Contoh: Titipan transfer pelanggan">
                            </div>

                            <!-- Process Button -->
                            <button type="button" class="btn btn-success btn-lg btn-block font-weight-bold" id="pos-kb-submit-payment" disabled>
                                <i class="bi bi-check-circle mr-1"></i> Proses Pembayaran Kas Bon
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 3. SUCCESS & RECEIPT VIEW -->
                <div id="pos-kb-success-view" class="d-none text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
                    </div>
                    <h4 class="font-weight-bold text-success mb-2">Pembayaran Kas Bon Berhasil!</h4>
                    <p class="text-muted mb-4">Dana telah dicatat secara sah ke sesi kasir Anda.</p>

                    <div class="card mx-auto mb-4 text-left shadow-sm" style="max-width: 450px;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">No. Koleksi:</span>
                                <strong id="pos-kb-res-ref">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Jumlah Dibayar:</span>
                                <strong class="text-primary" id="pos-kb-res-amount">Rp0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2" id="pos-kb-res-change-row">
                                <span class="text-muted">Kembalian:</span>
                                <strong class="text-success" id="pos-kb-res-change">Rp0</strong>
                            </div>
                            <div class="dropdown-divider"></div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Sisa Hutang:</span>
                                <strong id="pos-kb-res-remaining">Rp0</strong>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-primary px-4 py-2 font-weight-bold mr-2" id="pos-kb-btn-print-receipt">
                            <i class="bi bi-printer mr-1"></i> Cetak Struk
                        </button>
                        <button type="button" class="btn btn-secondary px-4 py-2" id="pos-kb-btn-finish">
                            Selesai & Tutup
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light" id="pos-kb-modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" id="pos-kb-modal-close-btn">Tutup</button>
            </div>
        </div>
    </div>
</div>
