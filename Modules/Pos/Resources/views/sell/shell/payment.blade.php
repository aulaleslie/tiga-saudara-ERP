                <div class="pos-area pos-area-payment">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Pembayaran</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-payment-shell">
                                <div>
                                    <div class="small text-muted">Total Akhir</div>
                                    <div id="pos-payment-summary-total" class="pos-total-value">Rp0</div>
                                </div>
                                <div class="pos-payment-actions">
                                    <div class="pos-payment-secondary-actions">
                                        @if($posTransactionsEnabled && auth()->user()->can('pos.transactions.save'))
                                            <button id="pos-save-draft" class="btn btn-outline-primary btn-lg" type="button">
                                                Simpan dan Buka Baru
                                            </button>
                                        @else
                                            <button class="btn btn-outline-primary btn-lg" type="button" disabled
                                                    title="Membutuhkan izin simpan transaksi POS.">
                                                Simpan dan Buka Baru
                                            </button>
                                        @endif
                                        @if($posTransactionsEnabled
                                            && auth()->user()->can('pos.transactions.save')
                                            && auth()->user()->can('pos.transactions.load')
                                            && auth()->user()->can('pos.transactions.print-current'))
                                            <button id="pos-save-print" class="btn pos-save-print-btn btn-lg" type="button" disabled>
                                                Simpan dan Cetak
                                            </button>
                                        @endif
                                    </div>
                                    <button id="pos-checkout-final"
                                            class="btn btn-primary btn-lg btn-block"
                                            type="button"
                                            disabled
                                            @if(! $canCheckoutFlow)
                                                data-permission-locked="1"
                                                title="{{ $checkoutDisabledTitle }}"
                                            @endif>
                                        Pilih Pembayaran
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
