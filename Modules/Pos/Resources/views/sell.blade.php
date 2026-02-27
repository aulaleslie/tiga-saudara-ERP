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
                        <span id="pos-cart-tax-badge" class="badge badge-secondary">Tax: ESTIMATED (EXCLUDED)</span>
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
                        <button class="btn btn-success btn-block mb-2" type="button" disabled>Cash</button>
                        <button class="btn btn-info btn-block mb-2" type="button" disabled>Transfer</button>
                        <button class="btn btn-dark btn-block mb-2" type="button" disabled>QRIS</button>
                        <button class="btn btn-primary btn-block" type="button" disabled>Checkout</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body py-2">
                <p class="mb-0 text-muted small">
                    No transaction is posted from this shell.
                </p>
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

            const searchEndpoint = @json(route('pos.sell.products.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartDiscountEndpoint = @json(route('pos.sell.cart.discount.update'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const cartLinesBaseUrl = @json(url('/pos/sell/cart/lines'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (!searchInput || !statusElement || !resultListElement || !cartBody || !searchEndpoint || !cartShowEndpoint) {
                return;
            }

            let debounceHandle = null;
            let latestRequestId = 0;
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

            function clearResults() {
                resultListElement.innerHTML = '';
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
                    const taxMode = escapeHtml(meta.tax_mode || 'EXCLUDED');
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

            refreshCart();
        })();
    </script>
@endsection
