@extends('layouts.app')

@section('title', 'POS Sell')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card mb-3 border-primary">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-start">
                <div>
                    <h4 class="mb-1">POS Sell Screen</h4>
                    <p class="mb-1 text-muted">
                        Session #{{ $activeSession->id }}
                        @if($activeSession->terminal)
                            - {{ $activeSession->terminal->code }} ({{ $activeSession->terminal->name }})
                        @endif
                    </p>
                    <p class="mb-0 text-muted small">
                        Location:
                        {{ $activeSession->terminal?->location?->name ?? 'N/A' }}
                    </p>
                </div>
                <div class="text-md-end mt-2 mt-md-0">
                    <span class="badge badge-success">{{ $activeSession->status }}</span>
                    <div class="small text-muted mt-2">
                        Opened: {{ optional($activeSession->opened_at)->format('Y-m-d H:i') ?? '-' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4 mb-3">
                <div class="card h-100">
                    <div class="card-header"><strong>Product Search / Scan</strong></div>
                    <div class="card-body">
                        <div class="form-group mb-2">
                            <label for="pos-shell-search">Search by barcode, SKU, or name</label>
                            <input id="pos-shell-search" type="text" class="form-control"
                                   placeholder="Pindai barcode atau ketik nama/SKU"
                                   autocomplete="off">
                        </div>
                        <button class="btn btn-outline-primary btn-block mb-2" type="button" id="pos-shell-scan-feedback">
                            Siap Pindai
                        </button>
                        <p id="pos-shell-search-status" class="mb-2 small text-muted"></p>
                        <div id="pos-shell-search-results" class="list-group"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5 mb-3">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Cart</strong>
                        <span id="pos-cart-tax-badge" class="badge badge-secondary">Tax: ESTIMATED (INCLUDED)</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Harga</th>
                                    <th>Diskon Line</th>
                                    <th class="text-right">Bill Share</th>
                                    <th class="text-right">Pajak</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-right">Aksi</th>
                                </tr>
                                </thead>
                                <tbody id="pos-shell-cart-body">
                                <tr id="pos-shell-cart-empty-row">
                                    <td colspan="8" class="text-muted text-center py-4">Cart kosong.</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>

                        <hr class="my-3">

                        <div class="row">
                            <div class="col-md-8 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend">
                                        <label class="input-group-text" for="pos-cart-bill-discount-type">Bill Discount</label>
                                    </div>
                                    <select id="pos-cart-bill-discount-type" class="custom-select">
                                        <option value="fixed">Nominal</option>
                                        <option value="percentage">Persentase</option>
                                    </select>
                                    <input id="pos-cart-bill-discount-value" type="number" class="form-control"
                                           min="0" step="0.01" value="0">
                                    <div class="input-group-append">
                                        <button id="pos-cart-bill-discount-apply" type="button" class="btn btn-outline-primary">
                                            Terapkan
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2 text-md-right">
                                <button id="pos-cart-clear" class="btn btn-sm btn-outline-danger" type="button">
                                    Kosongkan Cart
                                </button>
                            </div>
                        </div>

                        <div class="small mt-2">
                            <div class="d-flex justify-content-between">
                                <span>Subtotal</span>
                                <strong id="pos-cart-total-subtotal">Rp0</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Discount</span>
                                <strong id="pos-cart-total-discount">Rp0</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Tax (Estimated)</span>
                                <strong id="pos-cart-total-tax">Rp0</strong>
                            </div>
                            <div class="d-flex justify-content-between border-top pt-2 mt-2">
                                <span>Grand Total</span>
                                <strong id="pos-cart-total-grand">Rp0</strong>
                            </div>
                        </div>

                        <p id="pos-cart-action-status" class="mb-0 mt-2 small text-muted"></p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 mb-3">
                <div class="card h-100">
                    <div class="card-header"><strong>Payment Shortcuts</strong></div>
                    <div class="card-body">
                        <div class="form-group mb-2">
                            <label for="pos-customer-search" class="small font-weight-bold mb-1">Customer (Optional)</label>
                            <input id="pos-customer-search" type="text" class="form-control form-control-sm"
                                   placeholder="Cari nama / telepon pelanggan">
                            <div id="pos-customer-search-results" class="list-group mt-1"></div>
                            <button id="pos-customer-clear" class="btn btn-sm btn-outline-secondary btn-block mt-2" type="button">
                                Gunakan Walk-In Default
                            </button>
                            <p id="pos-customer-resolution" class="small text-muted mt-2 mb-0"></p>
                            <p id="pos-customer-action-status" class="small text-muted mt-1 mb-2"></p>
                        </div>
                        <hr class="my-2">
                        <button id="pos-payment-cash" class="btn btn-success btn-block mb-2" type="button">Cash</button>
                        <button id="pos-payment-transfer" class="btn btn-info btn-block mb-2" type="button">Transfer</button>
                        <button id="pos-payment-qris" class="btn btn-dark btn-block mb-2" type="button">QRIS</button>
                        <button id="pos-checkout-final" class="btn btn-primary btn-block" type="button">Checkout</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body py-2">
                <p id="pos-shell-posting-note" class="mb-0 text-muted small">
                    All transactions are posted to the selected setting's general ledger.
                </p>
            </div>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div class="modal fade" id="pos-checkout-modal" tabindex="-1" role="dialog" aria-labelledby="pos-checkout-modal-label" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-checkout-modal-label">Pembayaran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="pos-checkout-error" class="alert alert-danger d-none"></div>
                    
                    <div class="form-group row mb-2">
                        <label class="col-sm-4 col-form-label font-weight-bold">Metode</label>
                        <div class="col-sm-8">
                            <input type="text" id="pos-checkout-method-label" class="form-control-plaintext font-weight-bold text-uppercase" readonly value="CASH">
                            <input type="hidden" id="pos-checkout-method-code" value="cash">
                        </div>
                    </div>
                    
                    <div class="form-group row mb-2">
                        <label class="col-sm-4 col-form-label font-weight-bold">Grand Total</label>
                        <div class="col-sm-8">
                            <input type="text" id="pos-checkout-total-label" class="form-control-plaintext font-weight-bold h5 mb-0" readonly value="Rp0">
                        </div>
                    </div>

                    <div class="form-group mb-2">
                        <label for="pos-checkout-amount-paid" class="font-weight-bold">Jumlah Bayar</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">Rp</span>
                            </div>
                            <input type="number" id="pos-checkout-amount-paid" class="form-control form-control-lg" step="0.01" min="0">
                        </div>
                    </div>

                    <div id="pos-checkout-change-wrapper" class="form-group row mb-2">
                        <label class="col-sm-4 col-form-label font-weight-bold">Kembalian</label>
                        <div class="col-sm-8">
                            <input type="text" id="pos-checkout-change-label" class="form-control-plaintext font-weight-bold text-success h5 mb-0" readonly value="Rp0">
                        </div>
                    </div>

                    <div id="pos-checkout-reference-wrapper" class="form-group d-none">
                        <label for="pos-checkout-reference" class="font-weight-bold">Referensi / No. Transaksi</label>
                        <input type="text" id="pos-checkout-reference" class="form-control" placeholder="Masukkan referensi pembayaran">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="button" id="pos-checkout-submit" class="btn btn-primary btn-lg">Konfirmasi Checkout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="pos-success-modal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content text-center py-4">
                <div class="modal-body">
                    <div class="mb-3">
                        <i class="fas fa-check-circle text-success fa-5x"></i>
                    </div>
                    <h4 class="mb-2">Checkout Berhasil!</h4>
                    <p id="pos-success-receipt" class="text-muted mb-1"></p>
                    <p id="pos-success-change" class="font-weight-bold text-success mb-1"></p>
                    <hr>
                    <button type="button" class="btn btn-primary btn-block" data-dismiss="modal">Lanjut Jualan</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const searchInput = document.getElementById('pos-shell-search');
            const statusElement = document.getElementById('pos-shell-search-status');
            const resultListElement = document.getElementById('pos-shell-search-results');
            const scanFeedbackButton = document.getElementById('pos-shell-scan-feedback');
            const cartBody = document.getElementById('pos-shell-cart-body');
            const cartEmptyRow = document.getElementById('pos-shell-cart-empty-row');
            const cartStatusElement = document.getElementById('pos-cart-action-status');
            const billDiscountTypeElement = document.getElementById('pos-cart-bill-discount-type');
            const billDiscountValueElement = document.getElementById('pos-cart-bill-discount-value');
            const applyBillDiscountButton = document.getElementById('pos-cart-bill-discount-apply');
            const clearCartButton = document.getElementById('pos-cart-clear');
            const taxBadge = document.getElementById('pos-cart-tax-badge');
            const subtotalElement = document.getElementById('pos-cart-total-subtotal');
            const discountElement = document.getElementById('pos-cart-total-discount');
            const taxElement = document.getElementById('pos-cart-total-tax');
            const grandElement = document.getElementById('pos-cart-total-grand');
            const customerSearchInput = document.getElementById('pos-customer-search');
            const customerResultListElement = document.getElementById('pos-customer-search-results');
            const customerClearButton = document.getElementById('pos-customer-clear');
            const customerResolutionElement = document.getElementById('pos-customer-resolution');
            const customerStatusElement = document.getElementById('pos-customer-action-status');

            const btnCash = document.getElementById('pos-payment-cash');
            const btnTransfer = document.getElementById('pos-payment-transfer');
            const btnQRIS = document.getElementById('pos-payment-qris');
            const btnCheckout = document.getElementById('pos-checkout-final');

            const checkoutModalElement = document.getElementById('pos-checkout-modal');
            const checkoutMethodLabel = document.getElementById('pos-checkout-method-label');
            const checkoutMethodCode = document.getElementById('pos-checkout-method-code');
            const checkoutTotalLabel = document.getElementById('pos-checkout-total-label');
            const checkoutAmountPaid = document.getElementById('pos-checkout-amount-paid');
            const checkoutChangeLabel = document.getElementById('pos-checkout-change-label');
            const checkoutChangeWrapper = document.getElementById('pos-checkout-change-wrapper');
            const checkoutReference = document.getElementById('pos-checkout-reference');
            const checkoutReferenceWrapper = document.getElementById('pos-checkout-reference-wrapper');
            const checkoutSubmit = document.getElementById('pos-checkout-submit');
            const checkoutError = document.getElementById('pos-checkout-error');

            const successReceiptElement = document.getElementById('pos-success-receipt');
            const successChangeElement = document.getElementById('pos-success-change');

            const searchEndpoint = @json(route('pos.sell.products.search'));
            const customerSearchEndpoint = @json(route('pos.sell.customers.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartDiscountEndpoint = @json(route('pos.sell.cart.discount.update'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const cartCustomerEndpoint = @json(route('pos.sell.cart.customer.update'));
            const finalizeEndpoint = @json(route('pos.sell.checkout.finalize'));
            const cartLinesBaseUrl = @json(url('/pos/sell/cart/lines'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (!searchInput || !statusElement || !resultListElement || !cartBody || !searchEndpoint || !cartShowEndpoint) {
                return;
            }

            let debounceHandle = null;
            let latestRequestId = 0;
            let customerDebounceHandle = null;
            let latestCustomerRequestId = 0;
            let currentSnapshot = null;

            const idrFormatter = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                maximumFractionDigits: 0
            });

            function setSearchStatus(message, tone) {
                statusElement.textContent = message || '';
                statusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                statusElement.classList.add(tone || 'text-muted');
            }

            function setCartStatus(message, tone) {
                if (!cartStatusElement) {
                    return;
                }

                cartStatusElement.textContent = message || '';
                cartStatusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                cartStatusElement.classList.add(tone || 'text-muted');
            }

            function setCustomerStatus(message, tone) {
                if (!customerStatusElement) {
                    return;
                }

                customerStatusElement.textContent = message || '';
                customerStatusElement.classList.remove('text-muted', 'text-danger', 'text-success');
                customerStatusElement.classList.add(tone || 'text-muted');
            }

            function clearResults() {
                resultListElement.innerHTML = '';
            }

            function clearCustomerResults() {
                if (customerResultListElement) {
                    customerResultListElement.innerHTML = '';
                }
            }

            function formatPrice(value) {
                const numeric = Number(value || 0);
                return idrFormatter.format(Number.isFinite(numeric) ? numeric : 0);
            }

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            async function jsonRequest(url, method, payload) {
                const options = {
                    method: method || 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                };

                if (options.method !== 'GET') {
                    options.headers['X-CSRF-TOKEN'] = csrfToken;
                    options.headers['Content-Type'] = 'application/json';
                }

                if (payload !== undefined) {
                    options.body = JSON.stringify(payload);
                }

                const response = await fetch(url, options);

                if (response.redirected) {
                    window.location.href = response.url;
                    return null;
                }

                let body = null;
                try {
                    body = await response.json();
                } catch (error) {
                    body = null;
                }

                if (!response.ok) {
                    const errorMessage = body && body.message ? body.message : 'Permintaan gagal diproses.';
                    throw new Error(errorMessage);
                }

                return body;
            }

            function getLineEndpoint(lineId) {
                return cartLinesBaseUrl + '/' + lineId;
            }

            function getPriceOverrideEndpoint(lineId) {
                return cartLinesBaseUrl + '/' + lineId + '/price-override';
            }

            function renderTotals(snapshot) {
                const totals = snapshot && snapshot.totals ? snapshot.totals : {};

                if (subtotalElement) subtotalElement.textContent = formatPrice(totals.subtotal || 0);
                if (discountElement) discountElement.textContent = formatPrice(totals.discount_total || 0);
                if (taxElement) taxElement.textContent = formatPrice(totals.tax_total || 0);
                if (grandElement) grandElement.textContent = formatPrice(totals.grand_total || 0);
            }

            function renderMeta(snapshot) {
                const meta = snapshot && snapshot.meta ? snapshot.meta : {};

                if (taxBadge) {
                    const displayMode = escapeHtml(meta.tax_display_mode || 'ESTIMATED');
                    const taxMode = escapeHtml(meta.tax_mode || 'INCLUDED');
                    taxBadge.textContent = 'Tax: ' + displayMode + ' (' + taxMode + ')';
                }
            }

            function renderBillDiscount(snapshot) {
                if (!billDiscountTypeElement || !billDiscountValueElement) {
                    return;
                }

                const billDiscount = snapshot && snapshot.bill_discount ? snapshot.bill_discount : {};
                const discountType = billDiscount.type || 'fixed';
                const discountValue = Number(billDiscount.value || 0);

                billDiscountTypeElement.value = discountType === 'percentage' ? 'percentage' : 'fixed';
                billDiscountValueElement.value = String(Number.isFinite(discountValue) ? discountValue : 0);
            }

            function renderCustomer(snapshot) {
                if (!customerResolutionElement) {
                    return;
                }

                const customer = snapshot && snapshot.customer ? snapshot.customer : {};
                const selected = customer.selected_customer || null;
                const selectedName = selected && selected.display_name ? selected.display_name : null;
                const selectedPhone = selected && selected.customer_phone ? selected.customer_phone : null;
                const defaultCustomer = customer.default_customer || null;
                const defaultName = defaultCustomer && defaultCustomer.display_name ? defaultCustomer.display_name : null;
                const defaultPhone = defaultCustomer && defaultCustomer.customer_phone ? defaultCustomer.customer_phone : null;
                const resolutionSource = customer.resolution_source || 'unresolved';
                const resolutionError = customer.resolution_error || null;

                if (resolutionSource === 'selected') {
                    customerResolutionElement.textContent = selectedName
                        ? 'Pelanggan terpilih: ' + selectedName + (selectedPhone ? ' (' + selectedPhone + ')' : '')
                        : 'Pelanggan terpilih.';
                    customerResolutionElement.classList.remove('text-danger');
                    customerResolutionElement.classList.add('text-muted');
                    return;
                }

                if (resolutionSource === 'default') {
                    customerResolutionElement.textContent = defaultName
                        ? 'Default walk-in: ' + defaultName + (defaultPhone ? ' (' + defaultPhone + ')' : '')
                        : 'Default walk-in digunakan.';
                    customerResolutionElement.classList.remove('text-danger');
                    customerResolutionElement.classList.add('text-muted');
                    return;
                }

                const errorMessage = resolutionError && resolutionError.message
                    ? String(resolutionError.message)
                    : 'Default walk-in customer belum dikonfigurasi.';
                customerResolutionElement.textContent = errorMessage;
                customerResolutionElement.classList.remove('text-muted');
                customerResolutionElement.classList.add('text-danger');
            }

            async function updateCustomerSelection(customerId) {
                const payload = customerId === null ? { customer_id: null } : { customer_id: Number(customerId) };

                const response = await jsonRequest(cartCustomerEndpoint, 'PATCH', payload);
                if (!response) {
                    return null;
                }

                renderCart(response.cart_snapshot || null);
                return response;
            }

            function renderCustomerSearchResults(data) {
                clearCustomerResults();

                if (!customerResultListElement) {
                    return;
                }

                const results = Array.isArray(data.results) ? data.results : [];

                if (results.length === 0) {
                    setCustomerStatus('Pelanggan tidak ditemukan.', 'text-muted');
                    return;
                }

                results.forEach((customer) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action list-group-item-light py-1 px-2';

                    const displayName = escapeHtml(customer.display_name || customer.customer_name || '-');
                    const phone = escapeHtml(customer.customer_phone || '-');

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small">${displayName}</span>
                            <span class="small text-muted">${phone}</span>
                        </div>
                    `;

                    button.addEventListener('click', async function () {
                        try {
                            await updateCustomerSelection(customer.id);
                            clearCustomerResults();
                            if (customerSearchInput) {
                                customerSearchInput.value = '';
                            }
                            setCustomerStatus('Pelanggan berhasil dipilih.', 'text-success');
                        } catch (error) {
                            setCustomerStatus(error.message || 'Gagal memilih pelanggan.', 'text-danger');
                        }
                    });

                    customerResultListElement.appendChild(button);
                });
            }

            async function executeCustomerSearch(query) {
                latestCustomerRequestId += 1;
                const requestId = latestCustomerRequestId;
                setCustomerStatus('Mencari pelanggan...', 'text-muted');

                const url = new URL(customerSearchEndpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '8');

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Customer search failed.');
                    }

                    const data = await response.json();

                    if (requestId !== latestCustomerRequestId) {
                        return;
                    }

                    renderCustomerSearchResults(data);
                } catch (error) {
                    if (requestId !== latestCustomerRequestId) {
                        return;
                    }

                    clearCustomerResults();
                    setCustomerStatus('Pencarian pelanggan gagal.', 'text-danger');
                }
            }

            function buildLineRow(line) {
                const serialBadge = line.serial_number_required
                    ? '<span class="badge badge-warning ml-1">Perlu Serial</span>'
                    : '';

                const productName = escapeHtml(line.product_name || '-');
                const productCode = escapeHtml(line.product_code || '-');
                const barcode = escapeHtml(line.barcode || '-');
                const taxName = line.tax_name ? escapeHtml(line.tax_name) : '-';
                const discountType = line.line_discount_type === 'percentage' ? 'percentage' : 'fixed';
                const discountValue = Number(line.line_discount_value || 0);
                const qty = Number(line.qty || 0);
                const availableQty = Number(line.available_qty || 0);
                const lineId = Number(line.line_id || 0);

                return `
                    <tr data-line-id="${lineId}">
                        <td>
                            <div class="font-weight-bold">${productName}${serialBadge}</div>
                            <div class="small text-muted">${productCode} | ${barcode}</div>
                            <div class="small text-muted">Stok: ${availableQty}</div>
                        </td>
                        <td class="text-right align-middle">
                            <input class="form-control form-control-sm text-right js-line-qty" type="number" min="1" value="${qty}">
                        </td>
                        <td class="text-right align-middle">
                            <div class="small">${formatPrice(line.unit_price || 0)}</div>
                        </td>
                        <td class="align-middle">
                            <div class="input-group input-group-sm">
                                <select class="custom-select js-line-discount-type">
                                    <option value="fixed" ${discountType === 'fixed' ? 'selected' : ''}>Nominal</option>
                                    <option value="percentage" ${discountType === 'percentage' ? 'selected' : ''}>%</option>
                                </select>
                                <input class="form-control js-line-discount-value text-right" type="number" min="0" step="0.01" value="${discountValue}">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-primary js-line-discount-save">Simpan</button>
                                </div>
                            </div>
                        </td>
                        <td class="text-right align-middle">${formatPrice(line.bill_discount_amount || 0)}</td>
                        <td class="text-right align-middle">
                            <div>${formatPrice(line.line_tax_total || 0)}</div>
                            <div class="small text-muted">${taxName}</div>
                        </td>
                        <td class="text-right align-middle">${formatPrice(line.line_total || 0)}</td>
                        <td class="text-right align-middle">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary js-line-qty-save">Qty</button>
                                <button type="button" class="btn btn-outline-warning js-line-price-override">Harga</button>
                                <button type="button" class="btn btn-outline-danger js-line-remove">Hapus</button>
                            </div>
                        </td>
                    </tr>
                `;
            }

            function renderCart(snapshot) {
                currentSnapshot = snapshot || null;
                const lines = snapshot && Array.isArray(snapshot.lines) ? snapshot.lines : [];

                if (lines.length === 0) {
                    cartBody.innerHTML = `
                        <tr id="pos-shell-cart-empty-row">
                            <td colspan="8" class="text-muted text-center py-4">Cart kosong.</td>
                        </tr>
                    `;
                } else {
                    cartBody.innerHTML = lines.map(buildLineRow).join('');
                }

                renderTotals(snapshot);
                renderMeta(snapshot);
                renderBillDiscount(snapshot);
                renderCustomer(snapshot);

                const grandTotal = snapshot && snapshot.totals ? Number(snapshot.totals.grand_total || 0) : 0;
                const hasItems = snapshot && Array.isArray(snapshot.lines) && snapshot.lines.length > 0;
                const canCheckout = hasItems && grandTotal > 0;

                [btnCash, btnTransfer, btnQRIS, btnCheckout].forEach(btn => {
                    if (btn) btn.disabled = !canCheckout;
                });
            }

            async function refreshCart() {
                try {
                    const response = await jsonRequest(cartShowEndpoint, 'GET');
                    if (!response) {
                        return;
                    }

                    renderCart(response.cart_snapshot || null);
                } catch (error) {
                    setCartStatus(error.message || 'Gagal memuat cart.', 'text-danger');
                }
            }

            async function addProductToCart(product, source) {
                try {
                    const response = await jsonRequest(cartStoreLineEndpoint, 'POST', {
                        product_id: Number(product.id),
                        qty: 1,
                    });

                    if (!response) {
                        return;
                    }

                    renderCart(response.cart_snapshot || null);
                    clearResults();

                    if (source === 'auto') {
                        setSearchStatus('Produk ditambahkan otomatis dari barcode.', 'text-success');
                    } else {
                        setSearchStatus('Produk ditambahkan ke cart.', 'text-success');
                    }

                    setCartStatus('Cart berhasil diperbarui.', 'text-success');
                } catch (error) {
                    setCartStatus(error.message || 'Gagal menambahkan produk ke cart.', 'text-danger');
                }
            }

            function renderSearchResults(data) {
                clearResults();

                const results = Array.isArray(data.results) ? data.results : [];
                const autoSelectId = data.meta && data.meta.auto_select_product_id ? Number(data.meta.auto_select_product_id) : null;

                if (autoSelectId) {
                    const autoSelected = results.find((item) => Number(item.id) === autoSelectId);

                    if (autoSelected) {
                        addProductToCart(autoSelected, 'auto');
                        return;
                    }
                }

                if (results.length === 0) {
                    setSearchStatus('Produk tidak ditemukan.', 'text-muted');
                    return;
                }

                setSearchStatus('Pilih produk dari daftar.', 'text-muted');

                results.forEach((product) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action';

                    const productName = escapeHtml(product.product_name);
                    const productCode = escapeHtml(product.product_code || '-');
                    const barcode = escapeHtml(product.barcode || '-');
                    const availableQty = escapeHtml(product.available_qty);

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="font-weight-bold">${productName}</div>
                                <div class="small text-muted">${productCode} | ${barcode}</div>
                            </div>
                            <div class="text-right">
                                <div class="small text-muted">Stok: ${availableQty}</div>
                                <div class="small">${formatPrice(product.sale_price)}</div>
                            </div>
                        </div>
                    `;
                    button.addEventListener('click', function () {
                        addProductToCart(product, 'manual');
                    });

                    resultListElement.appendChild(button);
                });
            }

            async function executeSearch(query) {
                latestRequestId += 1;
                const requestId = latestRequestId;

                setSearchStatus('Mencari produk...', 'text-muted');

                const url = new URL(searchEndpoint, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('limit', '10');

                try {
                    const response = await fetch(url.toString(), {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Search request failed.');
                    }

                    const data = await response.json();

                    if (requestId !== latestRequestId) {
                        return;
                    }

                    renderSearchResults(data);
                } catch (error) {
                    if (requestId !== latestRequestId) {
                        return;
                    }

                    clearResults();
                    setSearchStatus('Pencarian gagal. Coba lagi.', 'text-danger');
                }
            }

            searchInput.addEventListener('input', function (event) {
                const query = (event.target.value || '').trim();

                if (debounceHandle) {
                    clearTimeout(debounceHandle);
                }

                if (query.length === 0) {
                    latestRequestId += 1;
                    clearResults();
                    setSearchStatus('', 'text-muted');
                    return;
                }

                debounceHandle = setTimeout(function () {
                    executeSearch(query);
                }, 250);
            });

            if (customerSearchInput) {
                customerSearchInput.addEventListener('input', function (event) {
                    const query = (event.target.value || '').trim();

                    if (customerDebounceHandle) {
                        clearTimeout(customerDebounceHandle);
                    }

                    if (query.length === 0) {
                        latestCustomerRequestId += 1;
                        clearCustomerResults();
                        setCustomerStatus('', 'text-muted');
                        return;
                    }

                    customerDebounceHandle = setTimeout(function () {
                        executeCustomerSearch(query);
                    }, 250);
                });
            }

            if (customerClearButton) {
                customerClearButton.addEventListener('click', async function () {
                    try {
                        await updateCustomerSelection(null);
                        clearCustomerResults();
                        if (customerSearchInput) {
                            customerSearchInput.value = '';
                        }
                        setCustomerStatus('Menggunakan customer walk-in default.', 'text-success');
                    } catch (error) {
                        setCustomerStatus(error.message || 'Gagal mengubah pelanggan.', 'text-danger');
                    }
                });
            }

            if (scanFeedbackButton) {
                scanFeedbackButton.addEventListener('click', function () {
                    searchInput.focus();
                    setSearchStatus('Mode pindai aktif. Arahkan scanner ke kolom pencarian.', 'text-success');
                });
            }

            if (applyBillDiscountButton && billDiscountTypeElement && billDiscountValueElement) {
                applyBillDiscountButton.addEventListener('click', async function () {
                    const discountType = billDiscountTypeElement.value === 'percentage' ? 'percentage' : 'fixed';
                    const discountValue = Number(billDiscountValueElement.value || 0);

                    if (!Number.isFinite(discountValue) || discountValue < 0) {
                        setCartStatus('Nilai bill discount tidak valid.', 'text-danger');
                        return;
                    }

                    try {
                        const response = await jsonRequest(cartDiscountEndpoint, 'PATCH', {
                            bill_discount_type: discountType,
                            bill_discount_value: discountValue,
                        });

                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Bill discount berhasil diterapkan.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal menerapkan bill discount.', 'text-danger');
                    }
                });
            }

            if (clearCartButton) {
                clearCartButton.addEventListener('click', async function () {
                    try {
                        const response = await jsonRequest(cartClearEndpoint, 'DELETE');

                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Cart dikosongkan.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal mengosongkan cart.', 'text-danger');
                    }
                });
            }

            cartBody.addEventListener('click', async function (event) {
                const button = event.target.closest('button');
                const row = event.target.closest('tr[data-line-id]');

                if (!button || !row) {
                    return;
                }

                const lineId = Number(row.getAttribute('data-line-id'));
                if (!Number.isFinite(lineId) || lineId <= 0) {
                    setCartStatus('Line cart tidak valid.', 'text-danger');
                    return;
                }

                if (button.classList.contains('js-line-qty-save')) {
                    const qtyInput = row.querySelector('.js-line-qty');
                    const qty = Number(qtyInput ? qtyInput.value : 0);

                    if (!Number.isFinite(qty) || qty < 1) {
                        setCartStatus('Qty harus minimal 1.', 'text-danger');
                        return;
                    }

                    try {
                        const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', { qty: qty });
                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Qty berhasil diperbarui.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memperbarui qty.', 'text-danger');
                    }

                    return;
                }

                if (button.classList.contains('js-line-discount-save')) {
                    const discountTypeElement = row.querySelector('.js-line-discount-type');
                    const discountValueElement = row.querySelector('.js-line-discount-value');
                    const discountType = discountTypeElement && discountTypeElement.value === 'percentage' ? 'percentage' : 'fixed';
                    const discountValue = Number(discountValueElement ? discountValueElement.value : 0);

                    if (!Number.isFinite(discountValue) || discountValue < 0) {
                        setCartStatus('Nilai diskon line tidak valid.', 'text-danger');
                        return;
                    }

                    try {
                        const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', {
                            line_discount_type: discountType,
                            line_discount_value: discountValue,
                        });
                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Diskon line berhasil diperbarui.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memperbarui diskon line.', 'text-danger');
                    }

                    return;
                }

                if (button.classList.contains('js-line-price-override')) {
                    const selectedLine = currentSnapshot && Array.isArray(currentSnapshot.lines)
                        ? currentSnapshot.lines.find((item) => Number(item.line_id) === lineId)
                        : null;
                    const currentPrice = selectedLine ? Number(selectedLine.unit_price || 0) : 0;
                    const pricePrompt = window.prompt('Harga baru untuk line ini:', String(currentPrice));

                    if (pricePrompt === null) {
                        return;
                    }

                    const newPrice = Number(pricePrompt);
                    if (!Number.isFinite(newPrice) || newPrice <= 0) {
                        setCartStatus('Harga override tidak valid.', 'text-danger');
                        return;
                    }

                    const supervisorIdentifier = window.prompt('Email supervisor:');
                    if (!supervisorIdentifier) {
                        setCartStatus('Email supervisor wajib diisi.', 'text-danger');
                        return;
                    }

                    const supervisorPin = window.prompt('PIN supervisor:');
                    if (!supervisorPin) {
                        setCartStatus('PIN supervisor wajib diisi.', 'text-danger');
                        return;
                    }

                    try {
                        const response = await jsonRequest(getPriceOverrideEndpoint(lineId), 'POST', {
                            unit_price: newPrice,
                            supervisor_identifier: supervisorIdentifier,
                            supervisor_pin: supervisorPin,
                        });

                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Price override berhasil diterapkan.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Price override gagal.', 'text-danger');
                    }

                    return;
                }

                if (button.classList.contains('js-line-remove')) {
                    try {
                        const response = await jsonRequest(getLineEndpoint(lineId), 'DELETE');
                        if (!response) {
                            return;
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Line cart dihapus.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal menghapus line cart.', 'text-danger');
                    }
                }
            });

            // Payment Logic
            function generateIdempotencyKey() {
                return 'pos-' + Date.now() + '-' + Math.random().toString(36).substring(2, 15);
            }

            function openPaymentModal(method) {
                if (!currentSnapshot || !currentSnapshot.totals) return;
                
                const grandTotal = Number(currentSnapshot.totals.grand_total || 0);
                if (grandTotal <= 0) return;

                checkoutMethodCode.value = method;
                checkoutMethodLabel.value = method;
                checkoutTotalLabel.value = formatPrice(grandTotal);
                checkoutAmountPaid.value = grandTotal.toFixed(2);
                checkoutReference.value = '';
                checkoutError.classList.add('d-none');
                checkoutError.textContent = '';
                
                if (method === 'cash') {
                    checkoutAmountPaid.readOnly = false;
                    checkoutChangeWrapper.classList.remove('d-none');
                    checkoutReferenceWrapper.classList.add('d-none');
                    updateChange(grandTotal, grandTotal);
                } else {
                    checkoutAmountPaid.readOnly = true;
                    checkoutChangeWrapper.classList.add('d-none');
                    checkoutReferenceWrapper.classList.remove('d-none');
                }

                $(checkoutModalElement).modal('show');
                
                setTimeout(() => checkoutAmountPaid.focus(), 500);
            }

            function updateChange(amountPaid, grandTotal) {
                const change = Math.max(0, amountPaid - grandTotal);
                checkoutChangeLabel.value = formatPrice(change);
            }

            if (checkoutAmountPaid) {
                checkoutAmountPaid.addEventListener('input', function() {
                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                    const amountPaid = Number(this.value || 0);
                    updateChange(amountPaid, grandTotal);
                });
            }

            [btnCash, btnTransfer, btnQRIS, btnCheckout].forEach(btn => {
                if (!btn) return;
                btn.addEventListener('click', function() {
                    const method = this.id.replace('pos-payment-', '').replace('pos-checkout-final', 'cash');
                    openPaymentModal(method);
                });
            });

            if (checkoutSubmit) {
                checkoutSubmit.addEventListener('click', async function() {
                    const method = checkoutMethodCode.value;
                    const amountPaid = Number(checkoutAmountPaid.value || 0);
                    const reference = checkoutReference.value.trim();
                    const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                    
                    if (amountPaid < grandTotal && method === 'cash') {
                        checkoutError.textContent = 'Pembayaran tunai harus mencukupi total belanja.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    if (!reference && (method === 'transfer' || method === 'qris')) {
                        checkoutError.textContent = 'Referensi pembayaran wajib diisi untuk non-tunai.';
                        checkoutError.classList.remove('d-none');
                        return;
                    }

                    checkoutSubmit.disabled = true;
                    checkoutSubmit.textContent = 'Memproses...';
                    checkoutError.classList.add('d-none');

                    try {
                        const payload = {
                            idempotency_key: generateIdempotencyKey(),
                            payment: {
                                method_code: method,
                                amount_paid: amountPaid,
                                reference: reference || null
                            }
                        };

                        const response = await jsonRequest(finalizeEndpoint, 'POST', payload);
                        if (!response) {
                            checkoutSubmit.disabled = false;
                            checkoutSubmit.textContent = 'Konfirmasi Checkout';
                            return;
                        }

                        $(checkoutModalElement).modal('hide');
                        
                        // Show success
                        if (successReceiptElement) successReceiptElement.textContent = 'No. Struk: ' + (response.receipt_number || '-');
                        if (successChangeElement) {
                            const change = Number(response.change_total || 0);
                            successChangeElement.textContent = change > 0 ? 'Kembalian: ' + formatPrice(change) : '';
                        }
                        
                        $('#pos-success-modal').modal('show');
                        
                        // Reset everything
                        renderCart(null);
                        setCartStatus('Transaksi berhasil diselesaikan.', 'text-success');

                    } catch (error) {
                        checkoutError.textContent = error.message || 'Gagal memproses checkout.';
                        checkoutError.classList.remove('d-none');
                    } finally {
                        checkoutSubmit.disabled = false;
                        checkoutSubmit.textContent = 'Konfirmasi Checkout';
                    }
                });
            }

            refreshCart();
        })();
    </script>
@endsection
