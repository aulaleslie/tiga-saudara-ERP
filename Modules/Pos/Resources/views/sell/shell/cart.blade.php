                <div class="pos-area pos-area-cart">
                    <div class="card pos-card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="pos-section-title">Keranjang</h5>
                            <button id="pos-cart-clear" class="btn btn-sm btn-outline-danger" type="button">
                                Kosongkan Keranjang
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="pos-cart-shell">
                                <div class="pos-cart-table-wrap">
                                    <table class="table table-sm pos-cart-table">
                                        <thead>
                                        <tr>
                                            <th>Produk</th>
                                            <th class="text-right">Harga</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-right">Sub Total</th>
                                            <th class="text-center">Aksi</th>
                                        </tr>
                                        </thead>
                                        <tbody id="pos-shell-cart-body">
                                        <tr id="pos-shell-cart-empty-row">
                                            <td colspan="5" class="text-muted text-center py-4">Keranjang kosong.</td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div id="pos-cart-action-alert" class="alert alert-danger p-2 mb-0 mt-2 small d-none font-weight-bold" role="alert">
                                    <!-- Error message goes here -->
                                </div>
                                <p id="pos-cart-action-status" class="mb-0 mt-1 small text-muted"></p>
                            </div>
                        </div>
                    </div>
                </div>
