                <div class="pos-area pos-area-search">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Pindai Produk</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-search-grid">
                                <div class="pos-search-input-row">
                                    <label for="pos-shell-search" class="small font-weight-bold">Pindai Barcode / Serial</label>
                                    <div class="pos-search-input-shell">
                                        <input id="pos-shell-search" type="text" class="form-control"
                                               placeholder="Pindai barcode atau ketik nomor serial"
                                               autocomplete="off">
                                        <button id="pos-shell-search-clear" type="button" class="d-none"
                                                aria-label="Bersihkan input pencarian">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="pos-scan-action-rail">
                                    <button id="pos-btn-scan-helper" type="button" class="btn btn-primary pos-scan-action-primary">
                                        <i class="bi bi-upc-scan" aria-hidden="true"></i> Pindai
                                    </button>
                                    <button id="pos-btn-cari-produk" type="button" class="btn btn-outline-secondary pos-scan-action-secondary">
                                        Cari Produk
                                    </button>
                                    <button id="pos-btn-scan-camera" type="button" class="btn btn-outline-secondary pos-scan-action-camera"
                                            title="Pindai kamera"
                                            aria-label="Pindai kamera">
                                        <i class="bi bi-camera" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <p id="pos-shell-search-status" class="small text-muted"></p>
                            </div>
                        </div>
                    </div>
                </div>
