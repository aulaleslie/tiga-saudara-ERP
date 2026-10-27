                <div class="pos-area pos-area-payment">
                    <div class="card pos-card">
                        <div class="card-header bg-white">
                            <h5 class="pos-section-title">Pembayaran</h5>
                        </div>
                        <div class="card-body">
                            <div class="pos-payment-shell">
                                <div class="pos-payment-summary">
                                    <div class="small text-muted pos-payment-summary-label">Total Akhir</div>
                                    <div id="pos-payment-summary-total" class="pos-total-value">Rp0</div>
                                </div>
                                <div class="pos-payment-actions">
                                    @if($hasCheckoutAuthority)
                                        <div class="pos-payment-primary-col">
                                            <button id="pos-checkout-final"
                                                    class="btn btn-primary btn-lg"
                                                    type="button"
                                                    disabled
                                                    data-flow="full_payment"
                                                    @if(! $canCheckoutFlow)
                                                        data-permission-locked="1"
                                                        title="{{ $checkoutDisabledTitle }}"
                                                    @endif>
                                                <div class="pos-checkout-title font-weight-bold">Bayar Lunas</div>
                                                <small class="pos-checkout-subtitle text-white-50 d-block font-weight-normal">Tunai / Transfer / Multi Pembayaran</small>
                                            </button>
                                        </div>
                                    @endif

                                    @php
                                        $hasSaveDraft = $posTransactionsEnabled && auth()->user()->can('pos.transactions.save');
                                        $hasSavePrint = $posTransactionsEnabled
                                            && auth()->user()->can('pos.transactions.save')
                                            && auth()->user()->can('pos.transactions.load')
                                            && auth()->user()->can('pos.transactions.print-current');
                                        $hasKasbon = $hasCheckoutAuthority && auth()->user()->can('pos.checkout.debt');
                                        $hasAnySecondary = $hasSaveDraft || $hasSavePrint || $hasKasbon;
                                    @endphp

                                    @if($hasAnySecondary)
                                        <div class="pos-payment-secondary-stack">
                                            @if($hasSaveDraft)
                                                <button id="pos-save-draft" class="btn btn-outline-primary" type="button" disabled>
                                                    Simpan dan Buka Baru
                                                </button>
                                            @endif

                                            @if($hasSavePrint)
                                                <button id="pos-save-print" class="btn pos-save-print-btn" type="button" disabled>
                                                    Simpan dan Cetak
                                                </button>
                                            @endif

                                            @if($hasKasbon)
                                                <button id="pos-checkout-kasbon"
                                                        class="btn btn-outline-danger"
                                                        type="button"
                                                        disabled
                                                        data-flow="kasbon"
                                                        @if(! $canCheckoutFlow)
                                                            data-permission-locked="1"
                                                            title="{{ $checkoutDisabledTitle }}"
                                                        @endif>
                                                    Kasbon
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
