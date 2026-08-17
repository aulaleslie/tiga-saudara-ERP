@extends('layouts.pos')

@section('title', 'Kasir POS')

@push('page_css')
    @include('pos::sell.css.styles')
@endpush

@section('content')
    @php
        $terminalLabelFull = $activeSession->terminal
            ? ($activeSession->terminal->code . ' (' . $activeSession->terminal->name . ')')
            : '-';
        $terminalLabelShort = \Illuminate\Support\Str::limit($terminalLabelFull, 30);
        $posTransactionsEnabled = (bool) (settings()->pos_transactions_enabled ?? false);
        $hasCheckoutAuthority = (bool) (($roleCapabilities['has_checkout_authority'] ?? $roleCapabilities['can_checkout'] ?? false) === true);
        $canCheckoutFlow = (bool) (($roleCapabilities['can_use_payment_flow'] ?? false) === true);
        $checkoutDisabledTitle = ! $hasCheckoutAuthority
            ? 'Membutuhkan izin pos.checkout.payment untuk membuka pembayaran.'
            : 'Kasir harus membuka sesi dengan terminal untuk mengakses pembayaran.';
    @endphp

    @include('pos::sell.shell.lock_screen')

    <div class="pos-shell">
        @include('utils.alerts')

        <div class="container-fluid px-0 h-100">
            <div class="pos-viewport">
                @include('pos::sell.shell.info')

                @include('pos::sell.shell.nav')

                @include('pos::sell.shell.search')

                @include('pos::sell.shell.cart')

                @include('pos::sell.shell.customer')

                @include('pos::sell.shell.note')

                @include('pos::sell.shell.payment')
            </div>
        </div>
    </div>

    @include('pos::sell.modals.checkout')

    @include('pos::sell.modals.staged_checkout')

    @include('pos::sell.modals.gratitude')

    @include('pos::sell.modals.success')

    @include('pos::sell.modals.save_success')

    @include('pos::sell.modals.customer_create')

    @include('pos::sell.modals.search_results')

    @include('pos::sell.modals.serial_input')

    @include('pos::sell.modals.qty_reduction')

    @include('pos::sell.modals.cash_pickup')

    @include('pos::sell.modals.camera_scanner')

    @include('pos::sell.modals.bundle_selection')

    @include('pos::sell.modals.bundle_detail')
    @include('pos::sell.modals.line_unit_price_override')
    @include('pos::sell.modals.line_total_override')
    @include('pos::sell.modals.checkout_mismatch')

@push('page_scripts')
    <!-- Task 3.1: Include staged payment module -->
    <script src="{{ asset('js/pos-staged-payment.js') }}"></script>
    <!-- html5-qrcode barcode decoder library (fallback for browsers without native BarcodeDetector) -->
    <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
    <!-- Camera scanner module (must load after html5-qrcode library) -->
    <script src="{{ asset('js/pos-camera-scanner.js') }}"></script>
    <script>
        (function () {
            const searchInput = document.getElementById('pos-shell-search');
            const searchClearButton = document.getElementById('pos-shell-search-clear');
            const statusElement = document.getElementById('pos-shell-search-status');

            const cariProdukButton = document.getElementById('pos-btn-cari-produk');
            const scanFeedbackButton = document.getElementById('pos-shell-scan-feedback');
            const cartBody = document.getElementById('pos-shell-cart-body');
            const cartStatusElement = document.getElementById('pos-cart-action-status');
            const cartActionAlert = document.getElementById('pos-cart-action-alert');
            const clearCartButton = document.getElementById('pos-cart-clear');
            const subtotalElement = document.getElementById('pos-cart-total-subtotal');
            const grandElement = document.getElementById('pos-cart-total-grand');
            const paymentSummaryTotal = document.getElementById('pos-payment-summary-total');

            const customerSearchInput = document.getElementById('pos-customer-search');
            const customerResultListElement = document.getElementById('pos-customer-search-results');
            const customerClearButton = document.getElementById('pos-customer-clear');
            const customerCreateButton = document.getElementById('pos-customer-create-btn');
            const customerResolutionElement = document.getElementById('pos-customer-resolution');
            const customerStatusElement = document.getElementById('pos-customer-action-status');

            const customerCreateModal = document.getElementById('pos-customer-create-modal');
            const customerCreateForm = document.getElementById('pos-customer-create-form');
            const customerCreateError = document.getElementById('pos-customer-create-error');
            const customerCreateSubmit = document.getElementById('pos-customer-create-submit');
            const customerCreateSpinner = document.getElementById('pos-customer-create-spinner');
            const newCustomerName = document.getElementById('pos-new-customer-name');
            const newCustomerPhone = document.getElementById('pos-new-customer-phone');
            const newCustomerTier = document.getElementById('pos-new-customer-tier');
            const saveDraftButton = document.getElementById('pos-save-draft');

            const transactionNote = document.getElementById('pos-transaction-note');
            const transactionNoteStatus = document.getElementById('pos-transaction-note-status');
            const transactionNoteCount = document.getElementById('pos-transaction-note-count');

            const btnCheckout = document.getElementById('pos-checkout-final');

            const checkoutModalElement = document.getElementById('pos-checkout-modal');
            const checkoutMethodLabel = document.getElementById('pos-checkout-method-label');
            const checkoutMethodId = document.getElementById('pos-checkout-method-id');
            const checkoutMethodSearch = document.getElementById('pos-checkout-method-search');
            const checkoutMethodResults = document.getElementById('pos-checkout-method-results');
            const checkoutTotalLabel = document.getElementById('pos-checkout-total-label');
            const checkoutAmountPaidSummary = document.getElementById('pos-checkout-amount-paid-summary');
            const checkoutRemainingLabel = document.getElementById('pos-checkout-remaining-label');
            const checkoutSubmit = document.getElementById('pos-checkout-submit');
            const checkoutError = document.getElementById('pos-checkout-error');
            const checkoutPaymentsList = document.getElementById('pos-checkout-payments-list');

            const checkoutReceiptLines = document.getElementById('pos-checkout-receipt-lines');
            const checkoutReceiptTotal = document.getElementById('pos-checkout-receipt-total');

            const successReceiptElement = document.getElementById('pos-success-receipt');
            const successChangeElement = document.getElementById('pos-success-change');
            const shortcutReprintBtn = document.getElementById('pos-shortcut-reprint');

            // Phase 1-3: Search results modal elements
            const searchResultsModalElement = document.getElementById('pos-search-results-modal');
            const searchResultsModalContainer = document.getElementById('pos-search-modal-results');
            const modalSearchInput = document.getElementById('pos-modal-search-input');
            const modalSearchBtn = document.getElementById('pos-modal-search-btn');

            // Phase 4: Serial modal elements
            const serialModalElement = document.getElementById('pos-serial-modal');
            const serialModalProductName = document.getElementById('pos-serial-modal-product-name');
            const serialModalQtyInfo = document.getElementById('pos-serial-modal-qty-info');
            const serialModalInput = document.getElementById('pos-serial-modal-input');
            const serialModalSubmitButton = document.getElementById('pos-serial-modal-submit');
            const serialModalStatus = document.getElementById('pos-serial-modal-status');
            const serialModalList = document.getElementById('pos-serial-modal-list');

            // Quantity Reduction Modal elements
            const reduceQuantityModal = document.getElementById('pos-reduce-quantity-modal');
            const reduceQtyCurrent = document.getElementById('pos-reduce-qty-current');
            const reduceQtyNewInput = document.getElementById('pos-reduce-qty-new');
            const reduceQtyError = document.getElementById('pos-reduce-qty-error');
            const reduceQtyReason = document.getElementById('pos-reduce-qty-reason');
            const reduceQtySubmit = document.getElementById('pos-reduce-qty-submit');
            let pendingReduceLineId = null;
            let pendingReduceCurrentQty = null;
            let pendingReduceButton = null;

            // Bundle Selection Modal elements
            const bundleSelectionModal = document.getElementById('pos-bundle-selection-modal');
            const bundleParentName = document.getElementById('pos-bundle-parent-name');
            const bundleLoading = document.getElementById('pos-bundle-loading');
            const bundleError = document.getElementById('pos-bundle-error');
            const bundleOptions = document.getElementById('pos-bundle-options');
            const bundleContinueNormal = document.getElementById('pos-bundle-continue-normal');
            let pendingBundleProduct = null;
            let pendingBundleSerial = null;

            // Price Override Modal elements
            // Two row monetary overrides, each with its own DOM nodes, form
            // state, endpoint, validation, and approval state. Nothing is
            // shared between them: the retired `price_override` markup drove
            // both operations from one set of nodes, which is precisely how the
            // unit-price action ended up mislabelled as a row-total action.
            const unitPriceOverrideEls = {
                modal: document.getElementById('pos-line-unit-price-override-modal'),
                product: document.getElementById('pos-line-unit-price-override-product'),
                current: document.getElementById('pos-line-unit-price-override-current'),
                input: document.getElementById('pos-line-unit-price-override-new'),
                error: document.getElementById('pos-line-unit-price-override-error'),
                reason: document.getElementById('pos-line-unit-price-override-reason'),
                submit: document.getElementById('pos-line-unit-price-override-submit'),
            };

            const rowTotalOverrideEls = {
                modal: document.getElementById('pos-line-total-override-modal'),
                product: document.getElementById('pos-line-total-override-product'),
                current: document.getElementById('pos-line-total-override-current'),
                input: document.getElementById('pos-line-total-override-new'),
                error: document.getElementById('pos-line-total-override-error'),
                reason: document.getElementById('pos-line-total-override-reason'),
                submit: document.getElementById('pos-line-total-override-submit'),
            };

            // Pending edit state, kept per action.
            const overrideEditState = {
                LINE_UNIT_PRICE_OVERRIDE: { lineId: null, currentValue: null, button: null },
                LINE_TOTAL_OVERRIDE: { lineId: null, currentValue: null, button: null },
            };

            const ROW_OVERRIDE_CONTROLS = [
                {
                    actionType: 'LINE_UNIT_PRICE_OVERRIDE',
                    jsClass: 'js-unit-price-edit',
                    els: unitPriceOverrideEls,
                    endpointSuffix: '/unit-price-override',
                    valueField: 'unit_price',
                    requestedField: 'requested_unit_price',
                    label: 'Harga satuan',
                    successMessage: 'Harga satuan berhasil diperbarui.',
                    failureMessage: 'Gagal memperbarui harga satuan.',
                    negativeMessage: 'Harga satuan tidak boleh negatif.',
                    unchangedMessage: 'Harga satuan tidak berubah.',
                    currentValueOf: (line) => Number(line.unit_price || 0),
                },
                {
                    actionType: 'LINE_TOTAL_OVERRIDE',
                    jsClass: 'js-row-total-edit',
                    els: rowTotalOverrideEls,
                    endpointSuffix: '/line-total-override',
                    valueField: 'line_total',
                    requestedField: 'requested_total',
                    label: 'Total baris',
                    successMessage: 'Total baris berhasil diperbarui.',
                    failureMessage: 'Gagal memperbarui total baris.',
                    negativeMessage: 'Total baris tidak boleh negatif.',
                    unchangedMessage: 'Total baris tidak berubah.',
                    currentValueOf: (line) => Number(
                        line.line_net_before_bill !== undefined
                            ? line.line_net_before_bill
                            : (line.line_total || 0)
                    ),
                },
            ];

            ROW_OVERRIDE_CONTROLS.forEach((control) => {
                control.openModal = (line, lineId, button) => {
                    const currentValue = control.currentValueOf(line);

                    overrideEditState[control.actionType] = { lineId, currentValue, button };

                    if (control.els.product) {
                        control.els.product.textContent = line.product_name || '';
                    }
                    if (control.els.current) {
                        control.els.current.textContent = formatPrice(currentValue);
                    }
                    if (control.els.input) {
                        control.els.input.value = currentValue;
                    }
                    if (control.els.reason) {
                        control.els.reason.value = '';
                    }
                    if (control.els.error) {
                        control.els.error.textContent = '';
                        control.els.error.style.display = 'none';
                    }
                    if (control.els.submit) {
                        // Starts disabled: the field equals the current value.
                        control.els.submit.disabled = true;
                    }
                    if (control.els.modal) {
                        $(control.els.modal).modal('show');
                    }
                };
            });

            // Bundle Detail Modal elements
            const bundleDetailModal = document.getElementById('pos-bundle-detail-modal');
            const bundleDetailName = document.getElementById('pos-bundle-detail-name');
            const bundleDetailParent = document.getElementById('pos-bundle-detail-parent');
            const bundleDetailQty = document.getElementById('pos-bundle-detail-qty');
            const bundleDetailBasePrice = document.getElementById('pos-bundle-detail-base-price');
            const bundleDetailAddonPrice = document.getElementById('pos-bundle-detail-addon-price');
            const bundleDetailUnitPrice = document.getElementById('pos-bundle-detail-unit-price');
            const bundleDetailSubtotalLabel = document.getElementById('pos-bundle-detail-subtotal-label');
            const bundleDetailLineTotal = document.getElementById('pos-bundle-detail-line-total');
            const bundleDetailItems = document.getElementById('pos-bundle-detail-items');
            const bundleDetailEmptyItems = document.getElementById('pos-bundle-detail-empty-items');

            // Track pending approval requests on the client side, scoped by action type per line.
            // Shape: { [lineId]: { QTY_REDUCE: { requestId, requestedQty, status }, LINE_TOTAL_OVERRIDE: { requestId, requestedTotal, status } } }
            // Only the matching action key is written/read so actions cannot bleed into each other.
            const clientPendingApprovals = {};

            const searchEndpoint = @json(route('pos.sell.products.search'));
            const scanResolveEndpoint = @json(url('/pos/sell/search/resolve'));
            const customerSearchEndpoint = @json(route('pos.sell.customers.search'));
            const cartShowEndpoint = @json(route('pos.sell.cart.show'));
            const cartStoreLineEndpoint = @json(route('pos.sell.cart.lines.store'));
            const cartClearEndpoint = @json(route('pos.sell.cart.clear'));
            const saveAndNewEndpoint = @json(route('pos.sell.transactions.save-and-new'));
            const cartCustomerEndpoint = @json(route('pos.sell.cart.customer.update'));
            const cartNoteEndpoint = @json(route('pos.sell.cart.note.update'));
            const customerStoreEndpoint = @json(route('pos.sell.customers.store'));
            const paymentMethodSearchEndpoint = @json(url('/pos/sell/payment-methods/search'));
            const finalizeEndpoint = @json(route('pos.sell.checkout.finalize'));
            const checkoutPreflightEndpoint = @json(route('pos.sell.checkout.preflight'));
            const cartLinesBaseUrl = @json(url('/pos/sell/cart/lines'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            // Global session context for approval flows
            window.posSessionId = {{ $activeSession->id }};
            window.posSettingId = {{ session("setting_id") ?? 0 }};

            const roleCapabilities = @json($roleCapabilities ?? []);
            console.log('[INIT] roleCapabilities: ' + JSON.stringify(roleCapabilities));
            const hasCheckoutAuthority = Boolean(roleCapabilities && (roleCapabilities.has_checkout_authority === true || roleCapabilities.can_checkout === true));
            const canCheckoutByRole = Boolean(roleCapabilities && roleCapabilities.can_use_payment_flow === true);
            const requiresTerminalForCheckout = hasCheckoutAuthority && !canCheckoutByRole;
            const canReduceQuantity = Boolean(
              typeof roleCapabilities?.can_reduce_quantity === 'boolean' ? roleCapabilities.can_reduce_quantity :
              typeof roleCapabilities?.direct_permissions?.qty_reduce === 'boolean' ? roleCapabilities.direct_permissions.qty_reduce :
              false
            );
            const canOverridePrice = Boolean(
              typeof roleCapabilities?.direct_permissions?.price_override === 'boolean' ? roleCapabilities.direct_permissions.price_override :
              false
            );
            console.log('[INIT] canReduceQuantity: ' + canReduceQuantity + ', canOverridePrice: ' + canOverridePrice);

            if (!searchInput || !statusElement || !cartBody || !searchEndpoint || !cartShowEndpoint) {
                return;
            }

            let latestRequestId = 0;
            let customerDebounceHandle = null;
            let latestCustomerRequestId = 0;

            let noteDebounceHandle = null;
            let latestNoteRequestId = 0;
            let currentSnapshot = null;
            let cachedPaymentMethods = [];

            // Task 4.1: Payment composer state (multiple payment rows)
            let checkoutPayments = []; // Array of {id, method, amount, reference, errors}

            const idrFormatter = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                maximumFractionDigits: 0
            });

            function setSearchStatus(message, tone) {
                statusElement.textContent = message || '';
                statusElement.classList.remove('text-muted', 'text-danger', 'text-success', 'text-warning');
                statusElement.classList.add(tone || 'text-muted');
            }

            function syncSearchClearButtonVisibility() {
                if (!searchInput || !searchClearButton) {
                    return;
                }

                const hasValue = (searchInput.value || '').trim().length > 0;
                searchClearButton.classList.toggle('d-none', !hasValue);
            }

            function clearSearchInput(options = {}) {
                if (!searchInput) {
                    return;
                }

                const keepFocus = options.keepFocus !== false;
                searchInput.value = '';
                syncSearchClearButtonVisibility();
                setSearchStatus('', 'text-muted');

                if (keepFocus) {
                    searchInput.focus();
                }
            }

            function setCartStatus(message, tone, showAsAlert = false) {
                if (cartActionAlert) {
                    if (showAsAlert && tone === 'text-danger') {
                        cartActionAlert.textContent = message || '';
                        cartActionAlert.classList.remove('d-none');
                        // Optional: auto-hide the alert after 4 seconds
                        setTimeout(() => {
                            if (cartActionAlert.textContent === message) {
                                cartActionAlert.classList.add('d-none');
                            }
                        }, 4000);
                        
                        // Clear the muted status if showing alert
                        if (cartStatusElement) cartStatusElement.textContent = '';
                        return;
                    } else {
                        cartActionAlert.classList.add('d-none');
                    }
                }

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
                // Inline results removed in Phase 1; this function is kept as a no-op for backward compatibility
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

            // Serial duplicate detection helper
            function serialAlreadyInCart(serialNumber) {
                if (!currentSnapshot || !Array.isArray(currentSnapshot.lines)) {
                    return false;
                }
                const normalizedSerial = String(serialNumber ?? '').trim();
                for (const line of currentSnapshot.lines) {
                    const assignedSerials = Array.isArray(line.assigned_serials) ? line.assigned_serials : [];
                    if (assignedSerials.includes(normalizedSerial)) {
                        return true;
                    }
                }
                return false;
            }

            function findCartLine(snapshot, productId, bundleId = null) {
                if (!snapshot || !Array.isArray(snapshot.lines)) return null;

                const normalizedProductId = Number(productId);
                const normalizedBundleId = bundleId ? Number(bundleId) : null;

                return snapshot.lines.find(line => {
                    const lineProductId = Number(line.product_id);
                    const lineBundleId = line.bundle_id ? Number(line.bundle_id) : null;

                    return lineProductId === normalizedProductId && lineBundleId === normalizedBundleId;
                });
            }

            // Task 4.1: Payment composer - add a payment row
            function addPaymentRow(method) {
                const paymentId = 'payment-' + Date.now();
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                const newPayment = {
                    id: paymentId,
                    method: method,
                    amount: 0,
                    reference: '',
                    errors: []
                };

                checkoutPayments.push(newPayment);
                checkoutMethodSearch.value = '';
                if (checkoutMethodResults) {
                    checkoutMethodResults.style.display = 'none';
                }

                renderPaymentsList();
                updatePaymentSummary();
            }

            // Task 4.1: Payment composer - render all payment rows
            function renderPaymentsList() {
                if (!checkoutPaymentsList) return;

                checkoutPaymentsList.innerHTML = '';
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;

                checkoutPayments.forEach((payment, index) => {
                    const methodName = payment.method ? escapeHtml(payment.method.name || 'Unknown') : 'Unknown';
                    const isCash = payment.method && payment.method.is_cash === true;
                    const requiresReference = payment.method && payment.method.requires_reference === true;

                    const row = document.createElement('div');
                    row.className = 'card mb-2 p-3';
                    row.style.backgroundColor = '#f8f9fa';

                    let referenceHtml = '';
                    if (requiresReference) {
                        const refValue = escapeHtml(payment.reference || '');
                        referenceHtml = `
                            <div class="form-group mb-2">
                                <label class="small font-weight-bold mb-1">Referensi</label>
                                <input type="text" class="form-control form-control-sm js-payment-reference"
                                       data-payment-id="${payment.id}" placeholder="Masukkan referensi"
                                       value="${refValue}">
                            </div>
                        `;
                    }

                    row.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong>${methodName}</strong>
                                ${payment.errors.length > 0 ? '<div class="small text-danger mt-1">' + payment.errors.join('; ') + '</div>' : ''}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger js-remove-payment"
                                    data-payment-id="${payment.id}" aria-label="Hapus">×</button>
                        </div>
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1">Jumlah (Rp)</label>
                            <input type="number" class="form-control form-control-sm js-payment-amount"
                                   data-payment-id="${payment.id}" step="1" min="0"
                                   value="${payment.amount}" placeholder="0">
                        </div>
                        ${referenceHtml}
                    `;

                    checkoutPaymentsList.appendChild(row);
                });

                // Add event listeners for payment rows
                document.querySelectorAll('.js-payment-amount').forEach(el => {
                    el.addEventListener('change', function () {
                        const paymentId = this.dataset.paymentId;
                        const amount = Number(this.value || 0);
                        const payment = checkoutPayments.find(p => p.id === paymentId);
                        if (payment) {
                            payment.amount = amount;
                            updatePaymentSummary();
                        }
                    });
                });

                document.querySelectorAll('.js-payment-reference').forEach(el => {
                    el.addEventListener('change', function () {
                        const paymentId = this.dataset.paymentId;
                        const reference = this.value.trim();
                        const payment = checkoutPayments.find(p => p.id === paymentId);
                        if (payment) {
                            payment.reference = reference;
                        }
                    });
                });

                document.querySelectorAll('.js-remove-payment').forEach(el => {
                    el.addEventListener('click', function () {
                        const paymentId = this.dataset.paymentId;
                        checkoutPayments = checkoutPayments.filter(p => p.id !== paymentId);
                        renderPaymentsList();
                        updatePaymentSummary();
                    });
                });
            }

            // Task 4.1: Update summary display
            function updatePaymentSummary() {
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                const totalPaid = checkoutPayments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                const remaining = grandTotal - totalPaid;

                if (checkoutAmountPaidSummary) {
                    checkoutAmountPaidSummary.value = formatPrice(totalPaid);
                }

                if (checkoutRemainingLabel) {
                    checkoutRemainingLabel.value = formatPrice(remaining);
                    checkoutRemainingLabel.classList.remove('text-success', 'text-danger', 'text-warning');
                    if (remaining > 0) {
                        checkoutRemainingLabel.classList.add('text-danger');
                    } else if (remaining < 0) {
                        checkoutRemainingLabel.classList.add('text-success');
                    } else {
                        checkoutRemainingLabel.classList.add('text-warning');
                    }
                }

                // Enable/disable submit button based on validation
                validatePaymentComposer();
            }

            // Task 4.3: Validate payment composer
            function validatePaymentComposer() {
                const grandTotal = currentSnapshot && currentSnapshot.totals ? Number(currentSnapshot.totals.grand_total || 0) : 0;
                const totalPaid = checkoutPayments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                let isValid = true;
                let errorMsg = '';

                // Check if we have any payments
                if (checkoutPayments.length === 0) {
                    isValid = false;
                    errorMsg = 'Tambahkan minimal satu metode pembayaran.';
                } else {
                    // Validate each payment row
                    checkoutPayments.forEach((payment, index) => {
                        payment.errors = [];

                        if (!payment.amount || payment.amount <= 0) {
                            payment.errors.push('Jumlah harus lebih dari 0');
                            isValid = false;
                        }

                        if (payment.method && payment.method.requires_reference && !payment.reference) {
                            payment.errors.push('Referensi wajib diisi');
                            isValid = false;
                        }
                    });

                    // Check if total paid matches grand total
                    if (isValid && totalPaid !== grandTotal) {
                        isValid = false;
                        const diff = grandTotal - totalPaid;
                        if (diff > 0) {
                            errorMsg = 'Total pembayaran kurang ' + formatPrice(diff);
                        } else {
                            errorMsg = 'Total pembayaran lebih ' + formatPrice(Math.abs(diff));
                        }
                    }

                    renderPaymentsList();
                }

                if (checkoutError) {
                    checkoutError.textContent = errorMsg;
                    checkoutError.classList.toggle('d-none', !errorMsg && isValid);
                }

                if (checkoutSubmit) {
                    checkoutSubmit.disabled = !isValid;
                }

                return isValid;
            }

            // Task 1.1: Canonical quantity-approval state mapper
            // Normalizes approval objects from mixed sources (client cache and server snapshot)
            // into a canonical shape for consistent rendering
            function normalizeQtyApprovalState(approvalObj) {
                if (!approvalObj) {
                    console.log('[normalizeQtyApprovalState] Input is null/undefined');
                    return null;
                }

                const normalized = {
                    request_id: approvalObj.request_id || approvalObj.requestId,
                    status: String(approvalObj.status || '').toUpperCase(),
                    requested_qty: approvalObj.requestedQty || approvalObj.payload?.qty || approvalObj.requested_qty,
                    token: approvalObj.token || approvalObj.approval_token,
                    approval_token: approvalObj.approval_token || approvalObj.token
                };
                console.log('[normalizeQtyApprovalState] Input: ' + JSON.stringify(approvalObj) + ' → Normalized: ' + JSON.stringify(normalized));
                return normalized;
            }

            // Task 1.1: Shared qty-approval state-to-button renderer
            // Maps normalized approval state to HTML button for rendering in either serial or non-serial rows.
            // Ensures both row types render equivalent UI for the same approval state.
            function renderQtyApprovalSlotButton(qtyReduceReq, lineId, currentQty) {
                if (!qtyReduceReq) {
                    // No approval request: render reduce button
                    return `<button type="button" class="btn btn-sm btn-outline-warning js-reduce-qty pos-qty-reduce-btn" data-line-id="${lineId}" data-current-qty="${currentQty}" title="Kurangi Jumlah" aria-label="Kurangi Jumlah">-</button>`;
                }

                if (qtyReduceReq.status === 'APPROVED') {
                    // Approved: show proceed button with approved qty
                    const approvedQty = qtyReduceReq.requested_qty || '?';
                    return `<button type="button" class="btn btn-sm btn-success js-check-qty-approval pos-qty-reduce-btn" data-line-id="${lineId}" data-approval-token="${qtyReduceReq.approval_token || ''}" data-approved-qty="${approvedQty}" title="Lanjutkan (qty: ${approvedQty})" aria-label="Lanjutkan">✓ ${approvedQty}</button>`;
                }

                if (qtyReduceReq.status !== 'REJECTED' && qtyReduceReq.status !== 'CANCELLED') {
                    // Pending or other active states: show check approval button
                    return `<button type="button" class="btn btn-sm btn-warning js-check-qty-approval pos-qty-reduce-btn" data-line-id="${lineId}" data-approval-pending="${qtyReduceReq.request_id}" title="Periksa Persetujuan" aria-label="Periksa Persetujuan">Periksa</button>`;
                }

                // Rejected or cancelled: render reduce button
                return `<button type="button" class="btn btn-sm btn-outline-warning js-reduce-qty pos-qty-reduce-btn" data-line-id="${lineId}" data-current-qty="${currentQty}" title="Kurangi Jumlah" aria-label="Kurangi Jumlah"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>`;
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
                    if (options.method === 'GET') {
                        const qs = new URLSearchParams(payload).toString();
                        url += (url.includes('?') ? '&' : '?') + qs;
                    } else {
                        options.body = JSON.stringify(payload);
                    }
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
                    const err = new Error(errorMessage);
                    err.code = body && body.code ? body.code : null;
                    err.details = body && body.details ? body.details : null;
                    err.warning = body && body.warning ? body.warning : null;
                    err.status = response.status;
                    throw err;
                }

                return body;
            }

            const ApprovalManager = window.ApprovalManager = {
                async wrapAction(btn, originalText, actionType, targetType, targetId, payload, actionFn) {
                    const pendingRequestId = btn.getAttribute('data-approval-pending');
                    if (pendingRequestId) {
                        await this.checkApproval(btn, originalText, pendingRequestId);
                        return false;
                    }

                    const token = btn.getAttribute('data-approval-token') || null;
                    if (token) {
                        const result = await Swal.fire({
                            title: 'Lanjutkan Aksi?',
                            text: 'Tekan Lanjutkan untuk mengeksekusi aksi, atau Batalkan untuk menghapus persetujuan.',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Lanjutkan',
                            cancelButtonText: 'Batalkan Persetujuan',
                            reverseButtons: true
                        });
                        
                        if (!result.isConfirmed) {
                            await this.cancelApproval(btn, originalText);
                            setCartStatus('Aksi dibatalkan tanpa perubahan keranjang.', 'text-muted');
                            return false;
                        }
                    }

                    try {
                        await actionFn(token);
                        this.resetBtn(btn, originalText);
                        return true;
                    } catch (error) {
                        const msg = error.message || '';
                        if (msg === 'APPROVAL_REQUIRED' || msg.includes('otorisasi') || msg.includes('APPROVAL')) {
                            await this.requestApproval(btn, originalText, actionType, targetType, targetId, payload);
                            return false;
                        }

                        setCartStatus(msg || 'Aksi gagal.', 'text-danger');
                        this.resetBtn(btn, originalText);
                        throw error;
                    }
                },

                async requestApproval(btn, originalText, actionType, targetType, targetId, payload) {
                    const { value: reasonInput, isDismissed } = await Swal.fire({
                        title: 'Permintaan Persetujuan',
                        text: 'Silakan masukkan alasan permintaan persetujuan (opsional):',
                        input: 'textarea',
                        inputPlaceholder: 'Tulis alasan di sini...',
                        showCancelButton: true,
                        confirmButtonText: 'Kirim Permintaan',
                        cancelButtonText: 'Batal',
                    });

                    if (isDismissed) {
                        return;
                    }

                    try {
                        const reqPayload = {
                            action_type: actionType,
                            target_type: targetType,
                            target_id: targetId,
                            payload: payload || {},
                            reason: reasonInput.trim() || null,
                        };
                        const res = await jsonRequest('/pos/sell/approval-requests', 'POST', reqPayload);
                        console.log('[requestApproval] Response received:', {request_id: res?.request_id, has_cart_snapshot: !!res?.cart_snapshot, cart_snapshot: res?.cart_snapshot});
                        if (res && res.request_id) {
                            console.log('[requestApproval] Success! Request ID:', res.request_id);
                            btn.setAttribute('data-approval-pending', res.request_id);
                            btn.setAttribute('data-approval-request-id', res.request_id);
                            if (btn.tagName === 'BUTTON') {
                                btn.textContent = 'Periksa Persetujuan';
                                btn.classList.remove('btn-danger', 'btn-outline-danger', 'btn-success');
                                btn.classList.add('btn-warning');
                                setCartStatus('Permintaan dikirim. Klik "Periksa Persetujuan" untuk cek status.', 'text-warning');
                            } else {
                                btn.classList.add('border-warning');
                                setCartStatus('Permintaan dikirim. Ulangi aksi untuk memeriksa status persetujuan.', 'text-warning');
                            }
                            // Render cart with snapshot to update approval state
                            if (res.cart_snapshot) {
                                console.log('[requestApproval] Calling renderCart with snapshot');
                                console.log('[requestApproval] Snapshot lines:', res.cart_snapshot.lines);
                                res.cart_snapshot.lines.forEach((line, idx) => {
                                    console.log(`[requestApproval] Line ${idx} (line_id=${line.line_id}):`, JSON.stringify({
                                        product_name: line.product_name,
                                        pending_approvals: line.pending_approvals
                                    }));
                                });
                                renderCart(res.cart_snapshot);
                            } else {
                                console.log('[requestApproval] WARNING: No cart_snapshot in response!');
                            }
                        } else {
                            console.log('[requestApproval] No request_id in response:', res);
                        }
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal meminta persetujuan.', 'text-danger');
                        this.resetBtn(btn, originalText);
                    }
                },

                async checkApproval(btn, originalText, requestId) {
                    try {
                        const res = await jsonRequest('/pos/sell/approval-requests/' + requestId, 'GET');
                        const state = String(res.state || res.status || '').toLowerCase();
                        const decisionReason = String(res.decision_reason || '').trim();

                        if (state === 'pending') {
                            setCartStatus('Status masih pending. Anda dapat klik lagi untuk cek ulang.', 'text-warning');
                            return;
                        }

                        if (state === 'approved' && (res.approval_token || res.token)) {
                            const token = res.approval_token || res.token;
                            btn.removeAttribute('data-approval-pending');
                            btn.setAttribute('data-approval-token', token);
                            if (btn.tagName === 'BUTTON') {
                                btn.textContent = 'Lanjutkan / Batalkan';
                                btn.classList.remove('btn-warning', 'btn-danger', 'btn-outline-danger');
                                btn.classList.add('btn-success');
                                setCartStatus('Permintaan disetujui. Klik tombol untuk Lanjutkan atau Batalkan.', 'text-success');
                            } else {
                                btn.classList.remove('border-warning');
                                btn.classList.add('border-success');
                                setCartStatus('Permintaan disetujui. Ulangi aksi untuk Lanjutkan atau Batalkan.', 'text-success');
                            }
                            return;
                        }

                        this.resetBtn(btn, originalText);
                        if (state === 'rejected') {
                            const suffix = decisionReason ? ' Alasan: ' + decisionReason : '';
                            setCartStatus('Permintaan ditolak.' + suffix, 'text-danger');
                            return;
                        }

                        if (state === 'cancelled') {
                            setCartStatus('Permintaan dibatalkan.', 'text-muted');
                            return;
                        }

                        if (state === 'expired') {
                            setCartStatus('Persetujuan kedaluwarsa. Ajukan ulang bila diperlukan.', 'text-danger');
                            return;
                        }

                        setCartStatus('Status persetujuan tidak dikenali. Ajukan ulang permintaan.', 'text-danger');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memeriksa status persetujuan.', 'text-danger');
                    }
                },

                async cancelApproval(btn, originalText) {
                    const requestId = btn.getAttribute('data-approval-pending') || btn.getAttribute('data-approval-request-id');
                    if (!requestId) {
                        this.resetBtn(btn, originalText);
                        return;
                    }

                    try {
                        await jsonRequest('/pos/sell/approval-requests/' + requestId + '/cancel', 'POST');
                    } catch (error) {
                        // Request might already be consumed/cancelled; reset UI anyway.
                    }

                    this.resetBtn(btn, originalText);
                },

                resetBtn(btn, originalText) {
                    btn.removeAttribute('data-approval-pending');
                    btn.removeAttribute('data-approval-token');
                    btn.removeAttribute('data-approval-request-id');
                    btn.classList.remove('border-warning', 'border-success');
                    if (btn.tagName === 'BUTTON') {
                        btn.innerHTML = originalText;
                        btn.className = btn.getAttribute('data-original-class') || btn.className;
                    } else if (btn.tagName === 'INPUT') {
                        btn.value = originalText;
                    }
                    // For inline qty elements, reset logic will be handled manually.
                }
            };

            function getLineEndpoint(lineId) {
                return cartLinesBaseUrl + '/' + lineId;
            }

            function renderTotals(snapshot) {
                const totals = snapshot && snapshot.totals ? snapshot.totals : {};
                const subtotal = Number(totals.subtotal || 0);
                const grandTotal = Number(totals.grand_total || 0);

                if (subtotalElement) subtotalElement.textContent = formatPrice(subtotal);
                if (grandElement) grandElement.textContent = formatPrice(grandTotal);
                if (paymentSummaryTotal) paymentSummaryTotal.textContent = formatPrice(grandTotal);
            }

            function renderCustomer(snapshot) {
                if (!customerResolutionElement) {
                    return;
                }

                const customer = snapshot && snapshot.customer ? snapshot.customer : {};
                const selected = customer.selected_customer || null;
                const selectedName = selected && selected.display_name ? selected.display_name : null;
                const selectedPhone = selected && selected.customer_phone ? selected.customer_phone : null;
                const selectedTier = selected && selected.tier ? selected.tier : null;
                const resolutionSource = customer.resolution_source || 'unresolved';

                if (resolutionSource === 'selected' || resolutionSource === 'walk_in') {
                    // Phase 3C: Make selected/default customer display prominent
                    const tierBadge = selectedTier ? `<span class="badge badge-primary ml-2">${escapeHtml(selectedTier)}</span>` : '';
                    const defaultBadge = resolutionSource === 'walk_in' ? '<span class="badge badge-secondary ml-2">Default</span>' : '';
                    customerResolutionElement.innerHTML = `
                        <div class="card p-2 bg-light border-primary">
                            <div class="font-weight-bold" style="font-size: 1.1rem;">${escapeHtml(selectedName || 'Pelanggan terpilih')}${tierBadge}${defaultBadge}</div>
                            ${selectedPhone ? '<div class="small text-muted">' + escapeHtml(selectedPhone) + '</div>' : ''}
                        </div>
                    `;
                    return;
                }

                customerResolutionElement.innerHTML = '<div class="text-muted small">Belum ada pelanggan dipilih.</div>';
            }

            function setNoteStatus(message, tone) {
                if (!transactionNoteStatus) return;
                transactionNoteStatus.textContent = message || '';
                transactionNoteStatus.classList.remove('text-muted', 'text-danger', 'text-success');
                transactionNoteStatus.classList.add(tone || 'text-muted');
            }

            async function submitNoteUpdate() {
                if (!transactionNote) return;
                const note = transactionNote.value;
                const reqId = ++latestNoteRequestId;
                
                setNoteStatus('Menyimpan...', 'text-muted');

                try {
                    const response = await jsonRequest(cartNoteEndpoint, 'PATCH', { note });
                    if (reqId === latestNoteRequestId) {
                        setNoteStatus('Tersimpan', 'text-success');
                        setTimeout(() => {
                            if (reqId === latestNoteRequestId) {
                                setNoteStatus('', 'text-muted');
                            }
                        }, 2000);
                        if (response.cart_snapshot) {
                            renderCart(response.cart_snapshot);
                        }
                    }
                    return true;
                } catch (err) {
                    if (reqId === latestNoteRequestId) {
                        setNoteStatus('Gagal menyimpan.', 'text-danger');
                    }
                    return false;
                }
            }

            function renderNote(snapshot) {
                if (!transactionNote) return;
                const note = snapshot && snapshot.note ? snapshot.note : '';
                
                if (document.activeElement !== transactionNote) {
                    transactionNote.value = note;
                    if (transactionNoteCount) {
                        transactionNoteCount.textContent = note.length;
                    }
                }
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
                    // Phase 3C: Increase customer suggestion item size
                    button.className = 'list-group-item list-group-item-action list-group-item-light py-2 px-3';

                    const displayName = escapeHtml(customer.display_name || customer.customer_name || '-');
                    const phone = escapeHtml(customer.customer_phone || '-');
                    const tier = customer.tier ? `<span class="badge badge-primary ml-2">${escapeHtml(customer.tier)}</span>` : '';

                    button.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="font-weight-bold">${displayName}${tier}</div>
                                <div class="small text-muted">${phone}</div>
                            </div>
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
                        throw new Error('Pencarian pelanggan gagal.');
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
                console.log('Building row for line:', JSON.stringify(line));
                const serialBadge = line.serial_number_required
                    ? '<span class="badge badge-warning ml-1">Perlu Serial</span>'
                    : '';

                const productName = escapeHtml(line.product_name || '-');
                const productCode = escapeHtml(line.product_code || '-');
                const barcode = escapeHtml(line.barcode || '-');
                const qty = Number(line.qty || 0);
                const lineId = Number(line.line_id || 0);
                const priceValid = line.price_valid !== false;
                const priceError = escapeHtml(line.price_error || '');

                // Pattern 1: Privilege-based quantity control rendering
                // For non-privileged users: increment-only input + reduce button + approval workflow (shows "Periksa Persetujuan")
                // For privileged users: standard editable qty input with +/- spinner
                // NOTE: The approval workflow only renders for users WITHOUT pos.cart.line.reduce permission
                let qtyCell = '';
                if (line.serial_number_required === true) {
                    // Serial line: editable qty + serial management
                    const assignedCount = Array.isArray(line.assigned_serials) ? line.assigned_serials.length : 0;
                    const serialChips = (line.assigned_serials || []).map(serial => `
                        <div class="pos-serial-chip">
                            <span title="${escapeHtml(serial)}">${escapeHtml(serial)}</span>
                            <button type="button" class="js-serial-remove" data-serial="${escapeHtml(serial)}">×</button>
                        </div>
                    `).join('');

                    // Fetch latest qty-reduce approval from server snapshot (shared for both paths)
                    const backendQtyReduceReq = (line.pending_approvals || [])
                        .slice()
                        .sort((a, b) => b.request_id - a.request_id)
                        .find(a => a.action_type === 'QTY_REDUCE');
                    const clientPending = clientPendingApprovals[lineId]?.QTY_REDUCE;
                    const qtyReduceRaw = backendQtyReduceReq || clientPending;
                    const qtyReduceReq = normalizeQtyApprovalState(qtyReduceRaw);

                    // For privileged users: full qty control with serial button
                    if (canReduceQuantity) {
                        // Privileged serial: direct decrease button, then qty, then increase, then serial action
                        qtyCell = `
                            <td class="pos-cart-serial-cell align-middle" style="min-width: 200px;">
                                <div class="d-flex flex-column align-items-center" style="gap: 2px;">
                                    <div class="d-flex align-items-center pos-qty-control-strip">
                                        <button type="button" class="btn btn-sm btn-outline-danger js-qty-decrease" data-line-id="${lineId}" title="Kurangi" aria-label="Kurangi">−</button>
                                        <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                               type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                               style="width: 55px;">
                                        <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 4px;">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info js-serial-add pos-serial-action"
                                                data-line-id="${lineId}"
                                                data-product-name="${productName}"
                                                title="Atur Serial"
                                                aria-label="Atur Serial">
                                            <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                            <span class="pos-serial-action-label">Serial</span>
                                        </button>
                                        <small class="text-muted font-weight-bold" style="font-size: 0.65rem;">${assignedCount}/${qty} Serial</small>
                                    </div>
                                    <div class="pos-serial-wrapper flex-grow-1">
                                        ${serialChips}
                                    </div>
                                </div>
                            </td>
                        `;
                    } else {
                        // Non-privileged serial: use shared control strip [Reduce/Periksa slot][qty input][+]
                        // Build shared control strip in order: [Reduce/Periksa slot][qty input][+]
                        const slotButtonHtml = renderQtyApprovalSlotButton(qtyReduceReq, lineId, qty);

                        qtyCell = `
                            <td class="pos-cart-serial-cell align-middle" style="min-width: 200px;">
                                <div class="d-flex flex-column align-items-center" style="gap: 2px;">
                                    <div class="d-flex align-items-center pos-qty-control-strip">
                                        ${slotButtonHtml}
                                        <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                               type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                               data-can-reduce="${canReduceQuantity}"
                                               style="width: 55px;">
                                        <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 4px;">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info js-serial-add pos-serial-action"
                                                data-line-id="${lineId}"
                                                data-product-name="${productName}"
                                                title="Atur Serial"
                                                aria-label="Atur Serial">
                                            <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                            <span class="pos-serial-action-label">Serial</span>
                                        </button>
                                        <small class="text-muted font-weight-bold" style="font-size: 0.65rem;">${assignedCount}/${qty} Serial</small>
                                    </div>
                                    <div class="pos-serial-wrapper flex-grow-1">
                                        ${serialChips}
                                    </div>
                                </div>
                            </td>
                        `;
                    }
                } else {
                    // Non-serial line: fetch approval state once for both paths
                    const backendQtyReduceReq = (line.pending_approvals || [])
                        .slice()
                        .sort((a, b) => b.request_id - a.request_id)
                        .find(a => a.action_type === 'QTY_REDUCE');
                    const clientPending = clientPendingApprovals[lineId]?.QTY_REDUCE;
                    const qtyReduceRaw = backendQtyReduceReq || clientPending;
                    const qtyReduceReq = normalizeQtyApprovalState(qtyReduceRaw);

                    if (canReduceQuantity) {
                        // Privileged non-serial: direct decrease, qty, increase in centered strip
                        qtyCell = `
                            <td class="text-center align-middle">
                                <div class="d-flex align-items-center justify-content-center pos-qty-control-strip">
                                    <button type="button" class="btn btn-sm btn-outline-danger js-qty-decrease" data-line-id="${lineId}" title="Kurangi" aria-label="Kurangi">−</button>
                                    <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                           type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                           data-can-reduce="${canReduceQuantity}"
                                           style="width: 55px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                </div>
                            </td>
                        `;
                    } else {
                        // Non-privileged non-serial: use shared control strip [Reduce/Periksa slot][qty input][+]
                        const slotButtonHtml = renderQtyApprovalSlotButton(qtyReduceReq, lineId, qty);

                        qtyCell = `
                            <td class="text-center align-middle">
                                <div class="d-flex align-items-center justify-content-center pos-qty-control-strip">
                                    ${slotButtonHtml}
                                    <input class="form-control form-control-sm text-center pos-cart-qty js-line-qty"
                                           type="number" min="1" value="${qty}" data-prev-qty="${qty}"
                                           data-can-reduce="${canReduceQuantity}"
                                           style="width: 55px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-qty-increase" data-line-id="${lineId}" title="Tambah" aria-label="Tambah">+</button>
                                </div>
                            </td>
                        `;
                    }
                }

                // Phase 3B: Price validity indicator
                const priceWarning = !priceValid ? `<div class="text-warning small font-weight-bold mb-1">⚠ ${priceError}</div>` : '';
                const rowClass = !priceValid ? 'bg-warning-light' : '';

                // Phase 3C: Delete button with approval state
                // Sort by request_id DESC to get the latest request (defensive against ordering)
                const removeReq = (line.pending_approvals || [])
                    .slice()
                    .sort((a, b) => b.request_id - a.request_id)
                    .find(a => a.action_type === 'LINE_REMOVE');
                let deleteButtonHtml = '';
                if (removeReq) {
                    if (removeReq.status === 'APPROVED') {
                        deleteButtonHtml = `<button type="button" class="btn btn-link text-success p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" data-approval-token="${removeReq.token || removeReq.approval_token || ''}" title="Lanjutkan" aria-label="Lanjutkan">Lanjutkan</button>`;
                    } else {
                        deleteButtonHtml = `<button type="button" class="btn btn-link text-warning p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" data-approval-pending="${removeReq.request_id}" title="Periksa Persetujuan" aria-label="Periksa Persetujuan">Periksa</button>`;
                    }
                } else {
                    deleteButtonHtml = `<button type="button" class="btn btn-link text-danger p-0 small js-line-remove" data-original-class="btn btn-link text-danger p-0 small js-line-remove" title="Hapus" aria-label="Hapus">Hapus</button>`;
                }

                // Two independent row monetary controls. Approval state is keyed
                // by BOTH line and action type so neither control can ever show
                // the other's "Periksa"/"Lanjutkan" state.
                const latestApprovalFor = (actionType) => (line.pending_approvals || [])
                    .slice()
                    .sort((a, b) => b.request_id - a.request_id)
                    .find(a => a.action_type === actionType);

                // Every rendered cart row is billable: bundle components are not
                // cart rows at all — they are nested under their parent in
                // `bundle_items` and surfaced read-only through the bundle
                // detail modal, which carries no monetary controls. The guard
                // stays explicit so a future change that promotes components to
                // real rows cannot silently give them price controls.
                const isBillableRow = !line.is_bundle_component
                    && !line.is_component_row
                    && Number(line.parent_line_id || 0) === 0;

                const buildOverrideButton = (config) => {
                    if (!isBillableRow) {
                        return '';
                    }

                    const req = latestApprovalFor(config.actionType);
                    const baseClass = `btn btn-sm btn-link ${config.idleTextClass} p-0 ml-1 ${config.jsClass}`;

                    if (req && req.status === 'APPROVED') {
                        const approvedValue = config.approvedValueOf(req);
                        return `<button type="button" class="btn btn-sm btn-success ml-1 ${config.jsClass}" data-original-class="${baseClass}" data-approval-token="${req.token || req.approval_token || ''}" data-approved-value="${approvedValue}" title="Lanjutkan ${config.label}: ${formatPrice(approvedValue)}" aria-label="Lanjutkan ${config.label}">✓ ${formatPrice(approvedValue)}</button>`;
                    }

                    if (req) {
                        return `<button type="button" class="btn btn-sm btn-warning ml-1 ${config.jsClass}" data-original-class="${baseClass}" data-approval-pending="${req.request_id}" title="Periksa Persetujuan ${config.label}" aria-label="Periksa Persetujuan ${config.label}">Periksa</button>`;
                    }

                    return `<button type="button" class="${baseClass}" data-original-class="${baseClass}" title="${config.label}" aria-label="${config.label}"><i class="bi ${config.icon}"></i></button>`;
                };

                // The amount the row-total modal treats as authoritative: the
                // row's own net, before any allocated bill discount. `line_total`
                // is post-bill-discount, so showing it beside the control would
                // let a cashier click next to one figure and see a different
                // "current total" in the modal.
                const authoritativeRowTotal = Number(
                    line.line_net_before_bill !== undefined
                        ? line.line_net_before_bill
                        : (line.line_total || 0)
                );

                const unitPriceBtnHtml = buildOverrideButton({
                    actionType: 'LINE_UNIT_PRICE_OVERRIDE',
                    jsClass: 'js-unit-price-edit',
                    label: 'Ubah Harga Satuan',
                    icon: 'bi-tag',
                    idleTextClass: 'text-primary',
                    approvedValueOf: (req) => req.requested_unit_price ?? 0,
                });

                const rowTotalBtnHtml = buildOverrideButton({
                    actionType: 'LINE_TOTAL_OVERRIDE',
                    jsClass: 'js-row-total-edit',
                    label: 'Ubah Total Baris',
                    icon: 'bi-calculator',
                    idleTextClass: 'text-info',
                    approvedValueOf: (req) => req.requested_line_total ?? 0,
                });

                let bundleSerialBadge = '';
                if (line.bundle_id && Array.isArray(line.bundle_items)) {
                    const bundleItemSerials = (line.bundle_item_serials && typeof line.bundle_item_serials === 'object') ? line.bundle_item_serials : {};
                    let hasSerialReq = false;
                    let allCompFulfilled = true;
                    for (const item of line.bundle_items) {
                        if (item.serial_number_required === true) {
                            hasSerialReq = true;
                            const bItemId = Number(item.bundle_item_id || 0);
                            const requiredCompQty = Math.round((Number(item.quantity_per_bundle || item.quantity || 1)) * Number(line.qty || 1));
                            const assignedList = Array.isArray(bundleItemSerials[bItemId])
                                ? bundleItemSerials[bItemId]
                                : (Array.isArray(item.assigned_serials) ? item.assigned_serials : []);
                            if (assignedList.length !== requiredCompQty) {
                                allCompFulfilled = false;
                                break;
                            }
                        }
                    }
                    if (hasSerialReq) {
                        bundleSerialBadge = allCompFulfilled
                            ? '<span class="badge badge-success ml-1" style="font-size: 0.75rem;"><i class="fas fa-barcode mr-1"></i>Serial Lengkap</span>'
                            : '<span class="badge badge-warning ml-1" style="font-size: 0.75rem;"><i class="fas fa-barcode mr-1"></i>Perlu Serial</span>';
                    }
                }

                const bundleInfo = line.bundle_id
                    ? `<div class="text-primary mt-1 d-flex align-items-center flex-wrap">
                         <button type="button" class="btn btn-link p-0 text-primary small font-weight-bold js-bundle-detail" 
                                 data-line-id="${lineId}" style="text-decoration: none; border: none; background: none; font-size: inherit;">
                             <i class="fas fa-box-open mr-1"></i> Paket: ${escapeHtml(line.bundle_name)}
                         </button>
                         ${bundleSerialBadge}
                       </div>`
                    : '';

                let packedInfo = '';
                if (line.price_source === 'PACKED' && line.breakdown && typeof line.breakdown === 'object') {
                    const breakdown = line.breakdown;
                    const boxCount = Number(breakdown.box_count || 0);
                    const looseCount = Number(breakdown.loose_count || 0);
                    const conversionUnit = escapeHtml(breakdown.conversion_unit_label || line.conversion_unit_name || 'Box');
                    const baseUnit = escapeHtml(breakdown.base_unit_label || 'Unit');
                    // Packed breakdown prices are deliberately stored in minor units.
                    // Convert only at this read-only display boundary.
                    const formatMinorPrice = (minorValue) => formatPrice(Number(minorValue || 0) / 100);
                    const breakdownLines = [];

                    if (boxCount > 0) {
                        breakdownLines.push(`<div>${boxCount} ${conversionUnit} @ ${formatMinorPrice(breakdown.box_price_applied)}</div>`);
                    }
                    if (looseCount > 0) {
                        breakdownLines.push(`<div>${looseCount} ${baseUnit} @ ${formatMinorPrice(breakdown.loose_price_applied)}</div>`);
                    }

                    if (breakdownLines.length > 0) {
                        packedInfo = `<div class="text-info mt-1 small" style="line-height: 1.2;">
                            <div class="font-weight-bold"><i class="fas fa-box mr-1"></i> Rincian Kemasan:</div>
                            ${breakdownLines.join('')}
                        </div>`;
                    }
                }

                return `
                    <tr data-line-id="${lineId}" class="${rowClass}">
                        <td class="pos-cart-product align-middle">
                            ${priceWarning}
                            <div class="name">${productName}${serialBadge}</div>
                            ${bundleInfo}
                            ${packedInfo}
                            <div class="meta">${productCode} | ${barcode}</div>
                        </td>
                        <td class="text-right align-middle">
                            <div class="d-flex align-items-center justify-content-end">
                                <span>${formatPrice(line.unit_price || 0)}</span>
                                ${unitPriceBtnHtml}
                            </div>
                        </td>
                        ${qtyCell}
                        <td class="text-right align-middle" style="vertical-align: top;">
                            <div class="d-flex align-items-center justify-content-end">
                                <span class="font-weight-bold">${formatPrice(authoritativeRowTotal)}</span>
                                ${rowTotalBtnHtml}
                            </div>
                            ${(
                                line.line_net_before_bill !== undefined
                                && Number(line.bill_discount_amount || 0) > 0
                            ) ? `<div class="small text-muted">Setelah diskon nota: ${formatPrice(line.line_total || 0)}</div>` : ''}
                        </td>
                        <td class="text-center align-middle">
                            ${deleteButtonHtml}
                        </td>
                    </tr>
                `;
            }

            function renderCart(snapshot) {
                console.log('[renderCart] Called with snapshot: ' + JSON.stringify({
                    hasSnapshot: !!snapshot,
                    lines: snapshot?.lines?.length || 0,
                    lineIds: snapshot?.lines?.map(l => l.line_id) || [],
                    cartItems: snapshot?.lines?.map(l => ({
                        line_id: l.line_id,
                        product_name: l.product_name,
                        pending_approvals_count: l.pending_approvals?.length || 0
                    })) || []
                }));
                currentSnapshot = snapshot || null;
                window.posLifecycleAcknowledged = false;
                if (typeof PosStagedPayment !== 'undefined' && typeof PosStagedPayment.setLifecycleAcknowledged === 'function') {
                    PosStagedPayment.setLifecycleAcknowledged(false);
                }
                const lines = snapshot && Array.isArray(snapshot.lines) ? snapshot.lines : [];

                if (lines.length === 0) {
                    cartBody.innerHTML = `
                        <tr id="pos-shell-cart-empty-row">
                            <td colspan="4" class="text-muted text-center py-4">Keranjang kosong.</td>
                        </tr>
                    `;
                } else {
                    cartBody.innerHTML = lines.map(buildLineRow).join('');
                }

                renderTotals(snapshot);
                renderCustomer(snapshot);
                renderNote(snapshot);

                // Phase 3B: Enhanced checkout button guards
                const grandTotal = snapshot && snapshot.totals ? Number(snapshot.totals.grand_total || 0) : 0;
                const hasItems = snapshot && Array.isArray(snapshot.lines) && snapshot.lines.length > 0;
                const customer = snapshot && snapshot.customer ? snapshot.customer : {};
                const hasCustomer = customer.resolution_source === 'selected' || customer.resolution_source === 'walk_in' || customer.resolution_source === 'default';
                
                // Check for price validity
                const allPricesValid = !snapshot || !Array.isArray(snapshot.lines) || 
                    snapshot.lines.every(line => line.price_valid !== false);
                
                // Check for serial count matching (parent lines and bundle component lines)
                let mismatchMessage = null;
                const allSerialsValid = !snapshot || !Array.isArray(snapshot.lines) ||
                    snapshot.lines.every((line, lineIdx) => {
                        const lineNum = lineIdx + 1;
                        if (line.serial_number_required === true) {
                            const assignedCount = Array.isArray(line.assigned_serials) ? line.assigned_serials.length : 0;
                            if (assignedCount !== line.qty) {
                                if (!mismatchMessage) {
                                    mismatchMessage = `Baris ${lineNum}: ${assignedCount} serial ditetapkan tetapi qty adalah ${line.qty}`;
                                }
                                return false;
                            }
                        }

                        // Check bundle component serial requirements
                        if (line.bundle_id && Array.isArray(line.bundle_items)) {
                            const bundleItemSerials = (line.bundle_item_serials && typeof line.bundle_item_serials === 'object') ? line.bundle_item_serials : {};
                            for (const item of line.bundle_items) {
                                if (item.serial_number_required === true) {
                                    const bItemId = Number(item.bundle_item_id || 0);
                                    const requiredCompQty = Math.round((Number(item.quantity_per_bundle || item.quantity || 1)) * Number(line.qty || 1));
                                    const assignedList = Array.isArray(bundleItemSerials[bItemId])
                                        ? bundleItemSerials[bItemId]
                                        : (Array.isArray(item.assigned_serials) ? item.assigned_serials : []);
                                    if (assignedList.length !== requiredCompQty) {
                                        if (!mismatchMessage) {
                                            const compName = item.product_name || `Komponen #${bItemId}`;
                                            mismatchMessage = `Baris ${lineNum} (${compName}): ${assignedList.length} serial ditetapkan tetapi butuh ${requiredCompQty}`;
                                        }
                                        return false;
                                    }
                                }
                            }
                        }

                        return true;
                    });

                 const canSaveDraft = hasItems && grandTotal > 0 && hasCustomer && allPricesValid && allSerialsValid;
                 const canCheckout = canSaveDraft && canCheckoutByRole;

                 if (btnCheckout) {
                     btnCheckout.disabled = !canCheckout;
                 }

                 if (saveDraftButton) {
                     saveDraftButton.disabled = !canSaveDraft;
                 }

                 // Display serial mismatch error if present
                 if (!allSerialsValid && mismatchMessage) {
                     setCartStatus(mismatchMessage, 'text-danger');
                 } else if (requiresTerminalForCheckout && hasItems) {
                     setCartStatus('Kasir tanpa terminal tetap bisa menyiapkan draft, tetapi pembayaran baru aktif setelah sesi terhubung ke terminal.', 'text-muted');
                 } else if (!hasCheckoutAuthority && hasItems) {
                     setCartStatus('Anda dapat menyimpan draft, tetapi pembayaran membutuhkan izin pos.checkout.payment.', 'text-muted');
                 }

                 if (clearCartButton) {
                     const isCustomerSelected = snapshot && snapshot.customer && snapshot.customer.resolution_source === 'selected';
                     const canClear = hasItems || isCustomerSelected;
                     clearCartButton.disabled = !canClear;

                     // Persist approval state for Kosongkan Keranjang
                     const clearReq = (snapshot && snapshot.pending_approvals || []).find(a => a.action_type === 'CART_CLEAR');
                     if (clearReq) {
                         clearCartButton.setAttribute('data-approval-request-id', clearReq.request_id);
                         if (clearReq.status === 'APPROVED') {
                             clearCartButton.removeAttribute('data-approval-pending');
                             clearCartButton.setAttribute('data-approval-token', clearReq.approval_token || clearReq.token || '');
                             clearCartButton.textContent = 'Lanjutkan / Batalkan';
                             clearCartButton.classList.remove('btn-outline-danger', 'btn-warning');
                             clearCartButton.classList.add('btn-success');
                         } else {
                             clearCartButton.setAttribute('data-approval-pending', clearReq.request_id);
                             clearCartButton.textContent = 'Periksa Persetujuan';
                             clearCartButton.classList.remove('btn-outline-danger', 'btn-success');
                             clearCartButton.classList.add('btn-warning');
                         }
                     } else {
                         clearCartButton.removeAttribute('data-approval-pending');
                         clearCartButton.removeAttribute('data-approval-request-id');
                         clearCartButton.removeAttribute('data-approval-token');
                         clearCartButton.textContent = 'Kosongkan Keranjang';
                         clearCartButton.classList.remove('btn-warning', 'btn-success');
                         clearCartButton.classList.add('btn-outline-danger');
                     }
                 }

             }
 
             async function refreshCart() {
                 try {
                     const response = await jsonRequest(cartShowEndpoint, 'GET');
                     if (!response) {
                         return;
                     }
 
                     renderCart(response.cart_snapshot || null);
                 } catch (error) {
                     setCartStatus(error.message || 'Gagal memuat keranjang.', 'text-danger');
                 }
             }

            // Phase 3A: Handle serial scan result - append serial to cart line
            async function handleSerialScanResult(result) {
                const product = result.product;
                const serial = result.serial;

                // Check if serial is already in cart (prevent duplicate scans)
                if (serialAlreadyInCart(serial.serial_number)) {
                    const message = 'Serial "' + escapeHtml(serial.serial_number) + '" sudah ditambahkan. Silakan pindai serial lainnya.';
                    setSearchStatus(message, 'text-info');
                    if (searchInput) {
                        clearSearchInput();
                    }
                    return;
                }

                // If product is a bundle parent, always route through bundle selection flow
                // regardless of existing cart lines.
                if (product.is_bundle_parent === 1 || product.is_bundle_parent === true) {
                    await addProductToCart(product, 'scan', { serialNumber: serial.serial_number });
                    return;
                }

                if (!currentSnapshot || !Array.isArray(currentSnapshot.lines)) {
                    // If no cart, add product with the serial
                    await addProductToCart(product, 'scan', { serialNumber: serial.serial_number });
                    return;
                }

                // Try to find an existing line for this product
                // First preference: find a line with unfilled serial slots
                let targetLine = currentSnapshot.lines.find(line => 
                    line.product_id === product.id && 
                    line.serial_number_required === true &&
                    (line.assigned_serials.length < line.qty)
                );

                // Second preference: if all are full, pick the first line for this product to consolidate
                if (!targetLine) {
                    targetLine = currentSnapshot.lines.find(line => 
                        line.product_id === product.id && 
                        line.serial_number_required === true
                    );
                }

                if (!targetLine) {
                    // No existing line at all, add product with the serial
                    await addProductToCart(product, 'scan', { serialNumber: serial.serial_number });
                } else {
                    // Found existing line (either with space or full), append serial to it
                    // Backend will now auto-increment qty if full
                    await appendSerialToLine(targetLine.line_id, serial.serial_number);
                }
            }

            // Phase 3A: Append serial to a cart line
            let currentSerialLineId = null;
            let serialAppendInFlight = false;

            function setSerialAppendInFlight(inFlight) {
                serialAppendInFlight = inFlight === true;
                if (serialModalSubmitButton) {
                    serialModalSubmitButton.disabled = serialAppendInFlight;
                }
            }

            function renderSerialModalList() {
                if (!serialModalList || !currentSnapshot || currentSerialLineId === null) return;
                
                const line = currentSnapshot.lines.find(l => Number(l.line_id) === currentSerialLineId);
                if (!line) return;

                const serials = Array.isArray(line.assigned_serials) ? line.assigned_serials : [];
                
                serialModalList.innerHTML = serials.map(serial => `
                    <div class="badge badge-primary d-flex align-items-center p-2">
                        <span class="mr-2">${escapeHtml(serial)}</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-white js-serial-remove" 
                                data-serial="${escapeHtml(serial)}" title="Hapus" aria-label="Hapus serial ${escapeHtml(serial)}">
                            &times;
                        </button>
                    </div>
                `).join('');

                if (serialModalQtyInfo) {
                    serialModalQtyInfo.textContent = `${serials.length} dari ${line.qty} serial terinput`;
                    serialModalQtyInfo.className = serials.length === line.qty ? 'text-success font-weight-bold' : 'text-primary';
                }
            }

            function openSerialModal(lineId, productName) {
                currentSerialLineId = lineId;
                if (serialModalProductName) serialModalProductName.textContent = productName;
                if (serialModalInput) serialModalInput.value = '';
                if (serialModalStatus) {
                    serialModalStatus.textContent = '';
                    serialModalStatus.className = 'small mb-3';
                }
                
                renderSerialModalList();
                $(serialModalElement).modal('show');
            }

            async function appendSerialToLine(lineId, serialNumber) {
                try {
                    const url = cartLinesBaseUrl + '/' + lineId + '/serials/append';
                    const response = await jsonRequest(url, 'POST', { serial_number: serialNumber });
                    if (response && response.cart_snapshot) {
                        renderCart(response.cart_snapshot);
                        
                        // If modal is open for this line, update it
                        if (currentSerialLineId === lineId) {
                            renderSerialModalList();
                            if (serialModalStatus) {
                                serialModalStatus.textContent = `Serial ${serialNumber} berhasil ditambahkan.`;
                                serialModalStatus.className = 'small mb-3 text-success';
                            }
                        }
                    }
                    return true;
                } catch (error) {
                    const errorMsg = error.message || 'Gagal menambahkan serial.';
                    if (currentSerialLineId === lineId) {
                        if (serialModalStatus) {
                            serialModalStatus.textContent = errorMsg;
                            serialModalStatus.className = 'small mb-3 text-danger';
                        }
                    } else {
                        setCartStatus(errorMsg, 'text-danger', true);
                    }
                    return false;
                }
            }

            async function submitSerialModalInput() {
                if (!serialModalInput || currentSerialLineId === null) {
                    return false;
                }

                if (serialAppendInFlight) {
                    return false;
                }

                const serialNumber = (serialModalInput.value || '').trim();
                if (!serialNumber) {
                    if (serialModalStatus) {
                        serialModalStatus.textContent = 'Serial tidak boleh kosong.';
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    serialModalInput.focus();
                    return false;
                }

                // Check if serial is already in cart (prevent duplicate modal input)
                if (serialAlreadyInCart(serialNumber)) {
                    if (serialModalStatus) {
                        serialModalStatus.textContent = 'Serial "' + escapeHtml(serialNumber) + '" sudah ditambahkan. Silakan masukkan serial lainnya.';
                        serialModalStatus.className = 'small mb-3 text-info';
                    }
                    serialModalInput.value = '';
                    serialModalInput.focus();
                    return false;
                }

                setSerialAppendInFlight(true);
                try {
                    const didAppend = await appendSerialToLine(currentSerialLineId, serialNumber);
                    if (didAppend) {
                        serialModalInput.value = '';
                        serialModalInput.focus();
                    }

                    return didAppend;
                } finally {
                    setSerialAppendInFlight(false);
                }
            }

            async function removeSerialFromLine(lineId, serialNumber, source) {
                const normalizedLineId = Number(lineId);
                const normalizedSerial = String(serialNumber || '').trim();

                if (!Number.isFinite(normalizedLineId) || normalizedLineId <= 0 || normalizedSerial.length === 0) {
                    const message = 'Data serial tidak valid.';
                    if (source === 'modal' && serialModalStatus) {
                        serialModalStatus.textContent = message;
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    setCartStatus(message, 'text-danger', true);
                    return;
                }

                try {
                    const url = cartLinesBaseUrl + '/' + normalizedLineId + '/serials/' + encodeURIComponent(normalizedSerial);
                    const response = await jsonRequest(url, 'DELETE');
                    if (response && response.cart_snapshot) {
                        renderCart(response.cart_snapshot);
                        if (currentSerialLineId === normalizedLineId) {
                            renderSerialModalList();
                        }

                        if (source === 'modal' && serialModalStatus) {
                            serialModalStatus.textContent = `Serial ${normalizedSerial} berhasil dihapus.`;
                            serialModalStatus.className = 'small mb-3 text-success';
                        }

                        setCartStatus('Serial berhasil dihapus.', 'text-success');
                    }
                } catch (error) {
                    const errorMessage = 'Gagal menghapus serial: ' + (error.message || 'Server error');
                    if (source === 'modal' && serialModalStatus) {
                        serialModalStatus.textContent = errorMessage;
                        serialModalStatus.className = 'small mb-3 text-danger';
                    }
                    setCartStatus(errorMessage, 'text-danger', true);
                }
            }

            // Bundle Detail Function
            function openBundleDetailModal(lineId) {
                if (!currentSnapshot || !Array.isArray(currentSnapshot.lines)) {
                    return;
                }

                const line = currentSnapshot.lines.find(l => Number(l.line_id) === Number(lineId));
                if (!line || !line.bundle_id) {
                    return;
                }

                // Render Header
                if (bundleDetailName) bundleDetailName.textContent = line.bundle_name || '-';
                if (bundleDetailParent) bundleDetailParent.textContent = line.product_name || '-';
                if (bundleDetailQty) bundleDetailQty.textContent = line.qty || 0;

                // Render Price Composition (derived per unit)
                const unitPrice = Number(line.unit_price || 0);
                const bundlePrice = Number(line.bundle_price || 0);
                const basePrice = Math.max(0, unitPrice - bundlePrice);
                const lineTotal = Number(line.line_total || 0);

                if (bundleDetailBasePrice) bundleDetailBasePrice.textContent = formatPrice(basePrice);
                if (bundleDetailAddonPrice) bundleDetailAddonPrice.textContent = '+ ' + formatPrice(bundlePrice);
                if (bundleDetailUnitPrice) bundleDetailUnitPrice.textContent = formatPrice(unitPrice);
                
                // Update line total and subtotal label
                if (bundleDetailSubtotalLabel) {
                    bundleDetailSubtotalLabel.textContent = `Subtotal Baris (${line.qty} Unit)`;
                }
                if (bundleDetailLineTotal) {
                    bundleDetailLineTotal.textContent = formatPrice(lineTotal);
                }

                // Render Items
                if (bundleDetailItems) {
                    bundleDetailItems.innerHTML = '';
                    const items = Array.isArray(line.bundle_items) ? line.bundle_items : [];
                    const bundleItemSerials = (line.bundle_item_serials && typeof line.bundle_item_serials === 'object') ? line.bundle_item_serials : {};
                    
                    if (items.length > 0) {
                        if (bundleDetailEmptyItems) bundleDetailEmptyItems.classList.add('d-none');
                        
                        items.forEach(item => {
                            const li = document.createElement('li');
                            li.className = 'list-group-item border-0 p-3 mb-2 bg-white rounded shadow-sm';
                            
                            const bItemId = Number(item.bundle_item_id || 0);
                            const serialRequired = Boolean(item.serial_number_required);
                            const totalItemQty = Math.round((Number(item.quantity_per_bundle || item.quantity || 1)) * Number(line.qty || 1));
                            const assignedList = Array.isArray(bundleItemSerials[bItemId]) 
                                ? bundleItemSerials[bItemId] 
                                : (Array.isArray(item.assigned_serials) ? item.assigned_serials : []);
                            
                            let serialSectionHtml = '';
                            if (serialRequired) {
                                const isFulfilled = assignedList.length === totalItemQty;
                                const isOver = assignedList.length > totalItemQty;
                                const countTone = isFulfilled ? 'text-success' : (isOver ? 'text-danger' : 'text-warning');
                                
                                const chipsHtml = assignedList.map(sn => `
                                    <span class="badge badge-primary p-2 mr-1 mb-1 d-inline-flex align-items-center" style="font-size: 0.8rem;">
                                        <span class="mr-1">${escapeHtml(sn)}</span>
                                        <button type="button" class="btn btn-link btn-sm p-0 text-white js-bundle-comp-serial-remove" 
                                                data-line-id="${line.line_id}" 
                                                data-bundle-item-id="${bItemId}" 
                                                data-serial="${escapeHtml(sn)}" 
                                                title="Hapus serial">&times;</button>
                                    </span>
                                `).join('');

                                serialSectionHtml = `
                                    <div class="mt-2 pt-2 border-top">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="small font-weight-bold ${countTone}">
                                                <i class="fas fa-barcode mr-1"></i> ${assignedList.length} / ${totalItemQty} Serial
                                            </span>
                                            ${isFulfilled ? '<span class="badge badge-success">Lengkap</span>' : '<span class="badge badge-warning">Belum Lengkap</span>'}
                                        </div>
                                        ${chipsHtml ? `<div class="d-flex flex-wrap mb-2">${chipsHtml}</div>` : ''}
                                        ${!isFulfilled ? `
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control js-bundle-comp-serial-input" 
                                                       placeholder="Scan / Ketik nomor seri lalu Enter" 
                                                       data-line-id="${line.line_id}" 
                                                       data-bundle-item-id="${bItemId}">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-primary js-bundle-comp-serial-submit" type="button" 
                                                            data-line-id="${line.line_id}" 
                                                            data-bundle-item-id="${bItemId}">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="small mt-1 text-muted js-bundle-comp-serial-status" data-bundle-item-id="${bItemId}"></div>
                                        ` : ''}
                                    </div>
                                `;
                            }
                                
                            li.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <span class="font-weight-bold text-dark">${escapeHtml(item.product_name || '-')}</span>
                                        ${serialRequired ? '<span class="badge badge-warning ml-2" style="font-size: 0.7rem;">Wajib Serial</span>' : ''}
                                    </div>
                                    <span class="badge badge-light border font-weight-bold" style="font-size: 0.9rem;">x ${totalItemQty}</span>
                                </div>
                                ${serialSectionHtml}
                            `;
                            bundleDetailItems.appendChild(li);
                        });

                        // Attach event handlers for component serial actions inside the modal
                        bundleDetailItems.querySelectorAll('.js-bundle-comp-serial-submit').forEach(btn => {
                            btn.addEventListener('click', async function() {
                                const lId = Number(this.getAttribute('data-line-id'));
                                const bId = Number(this.getAttribute('data-bundle-item-id'));
                                const input = bundleDetailItems.querySelector(`.js-bundle-comp-serial-input[data-bundle-item-id="${bId}"]`);
                                const statusEl = bundleDetailItems.querySelector(`.js-bundle-comp-serial-status[data-bundle-item-id="${bId}"]`);
                                if (!input) return;
                                const sn = input.value.trim();
                                if (!sn) return;

                                try {
                                    this.disabled = true;
                                    const url = cartLinesBaseUrl + '/' + lId + '/serials/append';
                                    const response = await jsonRequest(url, 'POST', { serial_number: sn, bundle_item_id: bId });
                                    if (response && response.cart_snapshot) {
                                        renderCart(response.cart_snapshot);
                                        openBundleDetailModal(lId);
                                        // Auto-focus next incomplete input if available
                                        setTimeout(() => {
                                            const nextInput = bundleDetailItems.querySelector('.js-bundle-comp-serial-input');
                                            if (nextInput) nextInput.focus();
                                        }, 100);
                                    }
                                } catch (err) {
                                    if (statusEl) {
                                        statusEl.textContent = err.message || 'Gagal menambahkan serial.';
                                        statusEl.className = 'small mt-1 text-danger js-bundle-comp-serial-status';
                                    }
                                } finally {
                                    this.disabled = false;
                                }
                            });
                        });

                        bundleDetailItems.querySelectorAll('.js-bundle-comp-serial-input').forEach(input => {
                            input.addEventListener('keydown', function(e) {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    const bId = this.getAttribute('data-bundle-item-id');
                                    const btn = bundleDetailItems.querySelector(`.js-bundle-comp-serial-submit[data-bundle-item-id="${bId}"]`);
                                    if (btn) btn.click();
                                }
                            });
                        });

                        bundleDetailItems.querySelectorAll('.js-bundle-comp-serial-remove').forEach(btn => {
                            btn.addEventListener('click', async function() {
                                const lId = Number(this.getAttribute('data-line-id'));
                                const bId = Number(this.getAttribute('data-bundle-item-id'));
                                const sn = this.getAttribute('data-serial');
                                if (!sn) return;

                                try {
                                    this.disabled = true;
                                    const url = cartLinesBaseUrl + '/' + lId + '/serials/' + encodeURIComponent(sn);
                                    const response = await jsonRequest(url, 'DELETE', { bundle_item_id: bId });
                                    if (response && response.cart_snapshot) {
                                        renderCart(response.cart_snapshot);
                                        openBundleDetailModal(lId);
                                    }
                                } catch (err) {
                                    setCartStatus('Gagal menghapus serial: ' + (err.message || 'Error'), 'text-danger', true);
                                }
                            });
                        });
                    } else {
                        if (bundleDetailEmptyItems) bundleDetailEmptyItems.classList.remove('d-none');
                    }
                }

                if (typeof $ !== 'undefined') {
                    $(bundleDetailModal).modal('show');
                }
            }

            function showMismatchModal(message, details) {
                const modal = document.getElementById('pos-checkout-mismatch-modal');
                const errorMessageElement = document.getElementById('pos-mismatch-error-message');
                const tbody = document.getElementById('pos-mismatch-lines-body');

                if (!modal || !tbody) return;

                if (errorMessageElement) {
                    errorMessageElement.textContent = message || 'Beberapa item di keranjang Anda tidak dapat diproses.';
                }

                tbody.innerHTML = '';

                if (Array.isArray(details.unfulfilled_lines) && details.unfulfilled_lines.length > 0) {
                    details.unfulfilled_lines.forEach(line => {
                        const tr = document.createElement('tr');
                        const requested = Number(line.requested_qty || 0);
                        const allocated = Number(line.allocated_qty || 0);
                        const shortage = Math.max(0, requested - allocated);
                        const displayName = line.product_name || line.product_code || ('Product #' + (line.product_id || '?'));
                        
                        tr.innerHTML = `
                            <td class="product-name">${escapeHtml(displayName)}</td>
                            <td class="text-center font-weight-bold">${requested}</td>
                            <td class="text-center text-success font-weight-bold">${allocated}</td>
                            <td class="text-center text-danger font-weight-bold">-${shortage}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
                
                if (Array.isArray(details.invalid_lines) && details.invalid_lines.length > 0) {
                    // Handle serial validation errors if they return line details
                    details.invalid_lines.forEach(line => {
                        const tr = document.createElement('tr');
                        const displayName = line.product_name || line.product_code || ('Product #' + (line.product_id || '?'));
                        
                        tr.innerHTML = `
                            <td colspan="4">
                                <span class="product-name text-danger">${escapeHtml(displayName)}</span>
                                <span class="text-muted small d-block">${escapeHtml(line.message || 'Serial invalid atau belum lengkap')}</span>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }

                if (typeof $ !== 'undefined') {
                    $(modal).modal('show');
                }
            }

            // Bundle Selection Functions
            async function openBundleSelectionModal(product, source, serialNumber = null) {
                pendingBundleProduct = product;
                pendingBundleSource = source;
                pendingBundleSerial = serialNumber;
                
                if (bundleParentName) bundleParentName.textContent = product.product_name;
                if (bundleLoading) bundleLoading.classList.remove('d-none');
                if (bundleError) bundleError.classList.add('d-none');
                if (bundleOptions) bundleOptions.innerHTML = '';
                
                $(bundleSelectionModal).modal('show');
                
                try {
                    const response = await jsonRequest(`/pos/sell/products/${product.id}/bundles`, 'GET');
                    if (bundleLoading) bundleLoading.classList.add('d-none');
                    
                    if (response && response.bundles) {
                        renderBundleOptions(response.bundles);
                    }
                } catch (error) {
                    if (bundleLoading) bundleLoading.classList.add('d-none');
                    if (bundleError) {
                        bundleError.textContent = error.message || 'Gagal memuat paket.';
                        bundleError.classList.remove('d-none');
                    }
                }
            }

            function renderBundleOptions(bundles) {
                if (!bundleOptions) return;
                
                bundleOptions.innerHTML = '';
                
                if (bundles.length === 0) {
                    bundleOptions.innerHTML = '<div class="col-12 text-center py-4 text-muted">Tidak ada paket tersedia untuk produk ini.</div>';
                    return;
                }
                
                bundles.forEach(bundle => {
                    const col = document.createElement('div');
                    col.className = 'col-md-6 mb-3';
                    
                    const itemsHtml = bundle.items.map(item => `
                        <div class="bundle-item">
                            <span class="bundle-item-name">${escapeHtml(item.name)}</span>
                            <span class="bundle-item-qty">x${item.quantity}</span>
                        </div>
                    `).join('');
                    
                    col.innerHTML = `
                        <div class="pos-bundle-card js-select-bundle" data-bundle-id="${bundle.id}">
                            <div class="bundle-name">${escapeHtml(bundle.name)}</div>
                            <div class="bundle-price">${formatPrice(bundle.price)}</div>
                            <div class="bundle-items border-top pt-2">
                                ${itemsHtml}
                            </div>
                        </div>
                    `;
                    
                    bundleOptions.appendChild(col);
                });
                
                // Event listeners for bundle selection
                bundleOptions.querySelectorAll('.js-select-bundle').forEach(el => {
                    el.addEventListener('click', function() {
                        const bundleId = this.dataset.bundleId;
                        const bundle = bundles.find(b => String(b.id) === bundleId);
                        if (bundle) {
                            addBundleToCart(pendingBundleProduct, bundle, pendingBundleSource);
                            $(bundleSelectionModal).modal('hide');
                        }
                    });
                });
            }

            async function addBundleToCart(product, bundle, source) {
                latestRequestId += 1;
                clearResults();
                if (searchInput) {
                    clearSearchInput({ keepFocus: false });
                }

                try {
                    const payload = {
                        product_id: Number(product.id),
                        qty: 1,
                        bundle_id: Number(bundle.id)
                    };

                    const response = await jsonRequest(cartStoreLineEndpoint, 'POST', payload);
                    if (!response) return;

                    renderCart(response.cart_snapshot || null);

                    // Check for pending serial and append it to the new bundle line
                    if (pendingBundleSerial && response.cart_snapshot) {
                        const snapshot = response.cart_snapshot;
                        const newLine = findCartLine(snapshot, product.id, bundle.id);
                        if (newLine) {
                             await appendSerialToLine(newLine.line_id, pendingBundleSerial);
                        }
                        pendingBundleSerial = null; // Clear it after use
                    }

                    if (searchInput) searchInput.focus();

                    const msg = `Paket "${bundle.name}" ditambahkan.`;
                    setSearchStatus(msg, 'text-success');
                    setCartStatus('Keranjang berhasil diperbarui.', 'text-success');
                } catch (error) {
                    setCartStatus(error.message || 'Gagal menambahkan paket ke keranjang.', 'text-danger');
                }
            }

            async function addProductToCart(product, source, options = {}) {
                // If product is a bundle parent, show selection modal instead of adding directly
                // skipBundleCheck is used when continuing without a bundle
                if (!options.skipBundleCheck && (product.is_bundle_parent === 1 || product.is_bundle_parent === true)) {
                    openBundleSelectionModal(product, source, options.serialNumber);
                    return;
                }

                latestRequestId += 1;
                clearResults();
                if (searchInput) {
                    clearSearchInput({ keepFocus: false });
                }

                try {
                    const payload = {
                        product_id: Number(product.id),
                        qty: 1,
                    };

                    // If product was resolved via conversion barcode, include conversion_id
                    if (product.conversion && product.conversion.id) {
                        payload.conversion_id = Number(product.conversion.id);
                    }

                    const response = await jsonRequest(cartStoreLineEndpoint, 'POST', payload);

                    if (!response) {
                        return;
                    }

                    renderCart(response.cart_snapshot || null);

                    // Atomic serial appending for non-bundle paths
                    if (options.serialNumber && response.cart_snapshot) {
                        const snapshot = response.cart_snapshot;
                        const newLine = findCartLine(snapshot, product.id, null);
                        if (newLine) {
                             await appendSerialToLine(newLine.line_id, options.serialNumber);
                        }
                    }
                    clearResults();
                    if (searchInput) {
                        searchInput.focus();
                    }

                    if (source === 'auto') {
                        setSearchStatus('Produk ditambahkan otomatis dari barcode.', 'text-success');
                    } else if (source === 'scan') {
                        setSearchStatus('Produk ditambahkan dari pindai.', 'text-success');
                    } else {
                        setSearchStatus('Produk ditambahkan ke keranjang.', 'text-success');
                    }

                    setCartStatus('Keranjang berhasil diperbarui.', 'text-success');
                } catch (error) {
                    setCartStatus(error.message || 'Gagal menambahkan produk ke keranjang.', 'text-danger');
                }
            }



            // Phase 3: Render search results in card-grid layout
            function renderSearchResultsModal(data) {
                if (!searchResultsModalContainer) {
                    return;
                }

                searchResultsModalContainer.innerHTML = '';

                const results = Array.isArray(data.results) ? data.results : [];
                const autoSelectId = data.meta && data.meta.auto_select_product_id ? Number(data.meta.auto_select_product_id) : null;

                // Auto-select if exact barcode match from search endpoint
                if (autoSelectId) {
                    const autoSelected = results.find((item) => Number(item.id) === autoSelectId);
                    if (autoSelected) {
                        addProductToCart(autoSelected, 'auto');
                        return;
                    }
                }

                // Show "not found" message if no results
                if (results.length === 0) {
                    const notFoundDiv = document.createElement('div');
                    notFoundDiv.className = 'text-muted text-center py-5';
                    notFoundDiv.textContent = 'Produk tidak ditemukan.';
                    searchResultsModalContainer.appendChild(notFoundDiv);
                    return;
                }

                // Render each result as a card in the grid
                results.forEach((product) => {
                    const isStockManaged = product.stock_managed !== false && product.stock_managed !== 0 && product.stock_managed !== '0';
                    const availableQty = Number(product.available_qty || 0);
                    const isOutOfStock = isStockManaged && availableQty <= 0;

                    const card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'pos-search-card' + (isOutOfStock ? ' pos-search-card-disabled' : '');
                    if (isOutOfStock) {
                        card.disabled = true;
                    }

                    const productName = escapeHtml(product.product_name);
                    const productCode = escapeHtml(product.product_code || '-');
                    const barcode = escapeHtml(product.barcode || '-');
                    const price = formatPrice(product.sale_price);
                    
                    let oosBadge = '';
                    if (!isStockManaged) {
                        oosBadge = '<div class="pos-search-card-oos-badge" style="background-color: var(--info);">Service</div>';
                    } else if (isOutOfStock) {
                        oosBadge = '<div class="pos-search-card-oos-badge">Stok Kosong</div>';
                    }
                    const stockDisplay = !isStockManaged ? '-' : product.available_qty;

                    card.innerHTML = `
                        <!-- Image placeholder for future use -->
                        <div style="width: 100%; height: 80px; background-color: #f8f9fa; border-radius: 4px; margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: center; color: #999;">
                            <small>Gambar</small>
                        </div>
                        <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.5rem;">${productName}</div>
                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.75rem;">
                            <div>SKU: ${productCode}</div>
                            <div>Barcode: ${barcode}</div>
                            <div class="${isOutOfStock ? 'text-danger font-weight-bold' : ''}">Stok: ${stockDisplay}</div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; align-items: center; font-weight: 500; padding-top: 0.75rem; border-top: 1px solid #eee;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.75rem; color: #999;">${price}</div>
                            </div>
                        </div>
                        ${oosBadge}
                    `;

                    if (!isOutOfStock) {
                        // Hover effect only for available items (disabled property handles OOS)
                        card.addEventListener('mouseenter', function () {
                            this.style.borderColor = '#007bff';
                            this.style.boxShadow = '0 0.125rem 0.25rem rgba(0,0,0,0.075)';
                            this.style.backgroundColor = '#f8f9ff';
                        });
                        card.addEventListener('mouseleave', function () {
                            this.style.borderColor = '#dee2e6';
                            this.style.boxShadow = 'none';
                            this.style.backgroundColor = 'white';
                        });

                        card.addEventListener('click', async function () {
                            // Close modal and let addProductToCart handle cleanup
                            if (searchResultsModalElement) {
                                try {
                                    if (typeof jQuery !== 'undefined') {
                                        jQuery(searchResultsModalElement).modal('hide');
                                    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                        const modal = bootstrap.Modal.getInstance(searchResultsModalElement);
                                        if (modal) {
                                            modal.hide();
                                        }
                                    }
                                } catch (e) {
                                    console.error('Error closing modal:', e);
                                }
                            }
                            await addProductToCart(product, 'manual');
                        });
                    }

                    searchResultsModalContainer.appendChild(card);
                });

                // Setup keyboard navigation for the cards
                setupSearchResultsModalKeyboard();
            }



            // Phase 1: Execute search and show results in modal
            async function executeSearchModal(query) {
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
                        throw new Error('Permintaan pencarian gagal.');
                    }

                    const data = await response.json();

                    if (requestId !== latestRequestId) {
                        return;
                    }

                    renderSearchResultsModal(data);
                    setSearchStatus('', 'text-muted');
                } catch (error) {
                    if (requestId !== latestRequestId) {
                        return;
                    }

                    clearResults();
                    setSearchStatus('Pencarian gagal: ' + (error.message || 'Server error'), 'text-danger');
                }
            }

            // Phase 3: Setup keyboard navigation for search results modal
            function setupSearchResultsModalKeyboard() {
                const items = searchResultsModalContainer ? Array.from(searchResultsModalContainer.querySelectorAll('button.pos-search-card:not(:disabled)')) : [];
                if (items.length === 0) {
                    return;
                }

                let currentFocusIndex = 0;

                // Focus first item on setup
                items[0].focus();

                // Key navigation handler
                function handleKeyNav(event) {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        currentFocusIndex = (currentFocusIndex + 1) % items.length;
                        items[currentFocusIndex].focus();
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        currentFocusIndex = (currentFocusIndex - 1 + items.length) % items.length;
                        items[currentFocusIndex].focus();
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        items[currentFocusIndex].click();
                    }
                }

                // Add keydown listeners to all items
                items.forEach((item, index) => {
                    item.addEventListener('keydown', handleKeyNav);
                    item.addEventListener('focus', () => {
                        currentFocusIndex = index;
                    });
                });
            }

            // Phase 3: Wire up modal keyboard navigation
            if (searchResultsModalElement) {
                searchResultsModalElement.addEventListener('shown.bs.modal', setupSearchResultsModalKeyboard);
            }

            // Shared scan resolver function (2.1): used by both Enter and helper button
            async function executeScanResolve(query) {
                clearResults();
                setSearchStatus('Memindai...', 'text-muted');

                try {
                    const response = await jsonRequest(scanResolveEndpoint + '?q=' + encodeURIComponent(query), 'GET');
                    if (!response) {
                        setSearchStatus('Pindai gagal.', 'text-danger');
                        return {
                            ok: false,
                            outcome: 'resolver_error',
                            message: 'Pindai gagal.'
                        };
                    }

                    if (response.type === 'product_exact') {
                        await addProductToCart(response.product, 'scan');
                        clearSearchInput({ keepFocus: false });
                        // Task 3.1: Enrich message with product name
                        const productMessage = 'Produk "' + (response.product.product_name || 'Unknown') + '" telah ditambahkan';
                        setSearchStatus(productMessage, 'text-success');
                        return {
                            ok: true,
                            outcome: 'product_exact',
                            message: productMessage,
                            product: response.product,
                            response: response
                        };
                    } else if (response.type === 'serial_exact') {
                        await handleSerialScanResult(response);
                        clearSearchInput({ keepFocus: false });
                        // Task 3.2: Enrich message with serial number
                        const serialMessage = 'Serial "' + (response.serial.serial_number || 'Unknown') + '" telah ditambahkan';
                        setSearchStatus(serialMessage, 'text-success');
                        return {
                            ok: true,
                            outcome: 'serial_exact',
                            message: serialMessage,
                            serial: response.serial,
                            response: response
                        };
                    } else {
                        // Task 3.3: Enrich message with the scanned query code
                        const notFoundMessage = 'Kode "' + query + '" tidak ditemukan';
                        setSearchStatus(notFoundMessage, 'text-warning');
                        return {
                            ok: false,
                            outcome: 'not_found',
                            message: notFoundMessage,
                            response: response
                        };
                    }
                } catch (error) {
                    const message = 'Pindai gagal: ' + (error.message || 'Server error');
                    setSearchStatus(message, 'text-danger');
                    return {
                        ok: false,
                        outcome: 'resolver_error',
                        message: message,
                        error: error
                    };
                }
            }

            // Expose shared resolver to global scope so camera scanner can access it (2.3)
            window.executeScanResolve = executeScanResolve;

            searchInput.addEventListener('input', function () {
                syncSearchClearButtonVisibility();
            });

            if (searchClearButton) {
                searchClearButton.addEventListener('click', function () {
                    clearSearchInput();
                });
            }

            syncSearchClearButtonVisibility();

            // Phase 1: Enter key handler for scan resolver (2.2: preserve for scanner hardware)
            searchInput.addEventListener('keydown', async function (event) {
                if (event.key !== 'Enter' && event.code !== 'Enter') {
                    return;
                }
                event.preventDefault();
                const query = (this.value || '').trim();
                if (!query) {
                    setSearchStatus('Masukkan kode produk atau nomor serial.', 'text-muted');
                    return;
                }
                await executeScanResolve(query);
            });

            // Helper button handler (2.2: wire helper to shared resolver)
            const scanHelperButton = document.getElementById('pos-btn-scan-helper');
            if (scanHelperButton) {
                scanHelperButton.addEventListener('click', async function () {
                    const query = (searchInput.value || '').trim();
                    if (!query) {
                        setSearchStatus('Masukkan kode produk atau nomor serial.', 'text-muted');
                        return;
                    }
                    await executeScanResolve(query);
                });
            }

            // Phase 3: Cari Produk button click handler
            if (cariProdukButton) {
                cariProdukButton.addEventListener('click', function () {
                    // Clear modal search input and results
                    if (modalSearchInput) {
                        modalSearchInput.value = '';
                    }
                    if (searchResultsModalContainer) {
                        searchResultsModalContainer.innerHTML = '';
                        const placeholderDiv = document.createElement('div');
                        placeholderDiv.className = 'text-muted text-center py-5';
                        placeholderDiv.textContent = 'Ketik nama produk atau SKU lalu tekan Cari.';
                        searchResultsModalContainer.appendChild(placeholderDiv);
                    }
                    
                    if (searchResultsModalElement) {
                        // Try jQuery first (most likely available)
                        try {
                            if (typeof jQuery !== 'undefined') {
                                jQuery(searchResultsModalElement).modal('show');
                                return;
                            }
                        } catch (e) {}
                        
                        // Fallback to Bootstrap 5
                        try {
                            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                const modal = new bootstrap.Modal(searchResultsModalElement);
                                modal.show();
                                return;
                            }
                        } catch (e) {}
                    }
                    
                    // Refocus scanner field on modal close
                    if (searchResultsModalElement) {
                        searchResultsModalElement.addEventListener('hidden.bs.modal', function refocusScanner() {
                            if (searchInput) {
                                searchInput.focus();
                            }
                            searchResultsModalElement.removeEventListener('hidden.bs.modal', refocusScanner);
                        }, { once: true });
                    }
                });
                
                // Phase 3: Auto-focus modal search input when modal opens
                if (searchResultsModalElement) {
                    searchResultsModalElement.addEventListener('shown.bs.modal', function () {
                        if (modalSearchInput) {
                            modalSearchInput.focus();
                        }
                    });
                }

                // Phase 4: Auto-focus serial modal input when modal opens
                if (serialModalElement) {
                    serialModalElement.addEventListener('shown.bs.modal', function () {
                        if (serialModalInput) {
                            serialModalInput.focus();
                        }
                        setSerialAppendInFlight(false);
                    });
                    
                    serialModalElement.addEventListener('hidden.bs.modal', function() {
                        currentSerialLineId = null;
                        if (serialModalInput) serialModalInput.value = '';
                        setSerialAppendInFlight(false);
                        if (serialModalStatus) {
                            serialModalStatus.textContent = '';
                            serialModalStatus.className = 'small mb-3';
                        }
                        if (searchInput) searchInput.focus();
                    });
                }
            }

            // Phase 4: Serial modal input keyboard handler
            if (serialModalInput) {
                serialModalInput.addEventListener('keydown', async function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        await submitSerialModalInput();
                    }
                });
            }

            if (serialModalSubmitButton) {
                serialModalSubmitButton.addEventListener('click', async function (event) {
                    event.preventDefault();
                    await submitSerialModalInput();
                });
            }

            if (serialModalList) {
                serialModalList.addEventListener('click', async function (event) {
                    const removeSerialBtn = event.target.closest('.js-serial-remove');
                    if (!removeSerialBtn) {
                        return;
                    }

                    event.preventDefault();
                    if (currentSerialLineId === null) {
                        if (serialModalStatus) {
                            serialModalStatus.textContent = 'Baris serial tidak aktif.';
                            serialModalStatus.className = 'small mb-3 text-danger';
                        }
                        return;
                    }

                    const serialNumber = removeSerialBtn.getAttribute('data-serial');
                    await removeSerialFromLine(currentSerialLineId, serialNumber, 'modal');
                });
            }

            // Phase 3: Modal search input/button event handlers
            function executeModalSearch() {
                const query = (modalSearchInput ? modalSearchInput.value : '').trim();
                if (!query) {
                    setSearchStatus('Masukkan kode pencarian.', 'text-muted');
                    return;
                }
                executeSearchModal(query);
            }

            if (modalSearchBtn) {
                modalSearchBtn.addEventListener('click', executeModalSearch);
            }

            if (modalSearchInput) {
                modalSearchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        executeModalSearch();
                    }
                });
            }

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

            if (customerCreateButton) {
                customerCreateButton.addEventListener('click', function () {
                    if (customerCreateError) customerCreateError.classList.add('d-none');
                    if (newCustomerName) newCustomerName.value = '';
                    if (newCustomerPhone) newCustomerPhone.value = '';
                    if (newCustomerTier) newCustomerTier.selectedIndex = 0;
                    $(customerCreateModal).modal('show');
                });
            }

            if (transactionNote) {
                transactionNote.addEventListener('input', function () {
                    if (transactionNoteCount) {
                        transactionNoteCount.textContent = this.value.length;
                    }
                    clearTimeout(noteDebounceHandle);
                    setNoteStatus('Menunggu...', 'text-muted');
                    noteDebounceHandle = setTimeout(submitNoteUpdate, 500);
                });

                transactionNote.addEventListener('blur', function () {
                    clearTimeout(noteDebounceHandle);
                    submitNoteUpdate();
                });
            }

            if (customerCreateForm) {
                customerCreateForm.addEventListener('submit', async function (e) {
                    e.preventDefault();

                    const name = (newCustomerName ? newCustomerName.value : '').trim();
                    if (!name) {
                        if (customerCreateError) {
                            customerCreateError.textContent = 'Nama pelanggan wajib diisi.';
                            customerCreateError.classList.remove('d-none');
                        }
                        return;
                    }

                    if (customerCreateSubmit) customerCreateSubmit.disabled = true;
                    if (customerCreateSpinner) customerCreateSpinner.classList.remove('d-none');
                    if (customerCreateError) customerCreateError.classList.add('d-none');

                    try {
                        const payload = {
                            customer_name: name,
                            customer_phone: newCustomerPhone ? newCustomerPhone.value.trim() || null : null,
                            tier: newCustomerTier ? newCustomerTier.value || null : null
                        };

                        const response = await jsonRequest(customerStoreEndpoint, 'POST', payload);
                        if (!response) return;

                        const newId = response && response.id ? response.id : null;
                        if (newId) {
                            await updateCustomerSelection(newId);
                            if (customerSearchInput) customerSearchInput.value = response.display_name || name;
                            setCustomerStatus('Pelanggan baru berhasil ditambahkan dan dipilih.', 'text-success');
                        }

                        $(customerCreateModal).modal('hide');
                    } catch (error) {
                        if (customerCreateError) {
                            customerCreateError.textContent = error.message || 'Gagal menyimpan pelanggan.';
                            customerCreateError.classList.remove('d-none');
                        }
                    } finally {
                        if (customerCreateSubmit) customerCreateSubmit.disabled = false;
                        if (customerCreateSpinner) customerCreateSpinner.classList.add('d-none');
                    }
                });
            }

            // Siap Pindai button removed in Phase 3A - Enter key now handles scan resolution

            if (clearCartButton) {
                if (!clearCartButton.hasAttribute('data-original-class')) {
                    clearCartButton.setAttribute('data-original-class', clearCartButton.className);
                }
                clearCartButton.addEventListener('click', async function () {
                    const btn = this;
                    const originalText = 'Kosongkan Keranjang';
                    
                    ApprovalManager.wrapAction(btn, originalText, 'CART_CLEAR', 'pos_session', window.posSessionId || 0, {}, async (token) => {
                        const payload = token ? { approval_token: token } : undefined;
                        const response = await jsonRequest(cartClearEndpoint, 'DELETE', payload);
                        if (response) {
                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Keranjang dikosongkan.', 'text-success');
                        }
                    });
                });
            }

            if (saveDraftButton) {
                saveDraftButton.addEventListener('click', async function () {
                    const originalText = saveDraftButton.textContent;
                    saveDraftButton.disabled = true;
                    saveDraftButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Menyimpan...';

                    try {
                        const response = await jsonRequest(saveAndNewEndpoint, 'POST');
                        await refreshCart();

                        const code = response && response.transaction ? response.transaction.code : '-';
                        const trxId = response && response.transaction ? response.transaction.id : null;
                        
                        // Task 2.2: Show success modal instead of just status message
                        const successModalTrx = document.getElementById('pos-save-success-trx-code');
                        const printBtn = document.getElementById('pos-save-success-print-btn');
                        
                        if (successModalTrx) successModalTrx.textContent = code;
                        if (printBtn) {
                            printBtn.setAttribute('data-trx-id', trxId);
                        }
                        
                        if (typeof $ !== 'undefined') {
                            $('#pos-save-success-modal').modal('show');
                        }
                        
                        setCartStatus('Transaksi ' + code + ' disimpan.', 'text-success');
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal menyimpan transaksi.', 'text-danger', true);
                    } finally {
                        saveDraftButton.disabled = false;
                        saveDraftButton.textContent = originalText;
                    }
                });
            }

            // Task 2.3: Implement button actions for the modal
            const saveSuccessContinueBtn = document.getElementById('pos-save-success-continue-btn');
            if (saveSuccessContinueBtn) {
                saveSuccessContinueBtn.addEventListener('click', function() {
                    if (typeof $ !== 'undefined') {
                        $('#pos-save-success-modal').modal('hide');
                    }
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                });
            }

            const saveSuccessPrintBtn = document.getElementById('pos-save-success-print-btn');
            if (saveSuccessPrintBtn) {
                saveSuccessPrintBtn.addEventListener('click', function() {
                    const trxId = this.getAttribute('data-trx-id');
                    if (trxId) {
                        const url = `/pos/transactions/${trxId}/receipt`;
                        window.open(url, '_blank');
                    }
                });
            }

            cartBody.addEventListener('change', async function (event) {
                const qtyInput = event.target.closest('.js-line-qty');
                if (!qtyInput) return;

                const row = qtyInput.closest('tr[data-line-id]');
                if (!row) return;

                const lineId = Number(row.getAttribute('data-line-id'));
                const newQty = Number(qtyInput.value || 0);
                const prevQty = Number(qtyInput.getAttribute('data-prev-qty') || 0);
                const userCanReduce = canReduceQuantity; // Privilege check

                if (!Number.isFinite(newQty) || newQty < 1) {
                    qtyInput.value = prevQty;
                    setCartStatus('Qty harus minimal 1.', 'text-danger', true);
                    return;
                }

                if (newQty === prevQty) {
                    return;
                }

                // Pattern 1: For non-privileged users, prevent direct reduction via input
                if (!userCanReduce && newQty < prevQty) {
                    qtyInput.value = prevQty;
                    qtyInput.setAttribute('data-prev-qty', String(prevQty));
                    setCartStatus('Gunakan tombol Kurangi untuk mengurangi jumlah.', 'text-warning', true);
                    return;
                }

                const applyQtyUpdate = async (token) => {
                    const payload = { qty: newQty };
                    if (token) payload.approval_token = token;

                    const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', payload);
                    if (!response) {
                        throw new Error('Gagal memperbarui qty.');
                    }

                    renderCart(response.cart_snapshot || null);
                    setCartStatus('Qty berhasil diperbarui.', 'text-success');
                };

                try {
                    // For privileged users: reduction triggers approval workflow
                    // For non-privileged users: only increases reach here (decreases are blocked above)
                    if (newQty < prevQty) {
                        const executed = await ApprovalManager.wrapAction(
                            qtyInput,
                            String(prevQty),
                            'QTY_REDUCE',
                            'pos_cart_line',
                            lineId,
                            { qty: newQty },
                            applyQtyUpdate
                        );
                        if (!executed) {
                            qtyInput.value = prevQty;
                            qtyInput.setAttribute('data-prev-qty', String(prevQty));
                        }
                    } else {
                        // Increase: apply immediately without approval
                        await applyQtyUpdate(null);
                        qtyInput.setAttribute('data-prev-qty', String(newQty));
                    }
                } catch (error) {
                    qtyInput.value = prevQty;
                    qtyInput.setAttribute('data-prev-qty', String(prevQty));
                }
            });

            cartBody.addEventListener('click', async function (event) {
                const button = event.target.closest('button');
                const row = event.target.closest('tr[data-line-id]');

                if (!button || !row) {
                    return;
                }

                const lineId = Number(row.getAttribute('data-line-id'));
                if (!Number.isFinite(lineId) || lineId <= 0) {
                    setCartStatus('Baris keranjang tidak valid.', 'text-danger');
                    return;
                }

                if (button.classList.contains('js-line-remove')) {
                    ApprovalManager.wrapAction(button, 'Hapus', 'LINE_REMOVE', 'pos_cart_line', lineId, {}, async (token) => {
                        const payload = token ? { approval_token: token } : undefined;
                        const response = await jsonRequest(getLineEndpoint(lineId), 'DELETE', payload);
                        if (response) {
                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Baris keranjang dihapus.', 'text-success');
                        }
                    });
                    return;
                }

                if (button.classList.contains('js-bundle-detail')) {
                    openBundleDetailModal(lineId);
                    return;
                }

                // Handle Reduce Quantity button click (non-privileged users only)
                if (button.classList.contains('js-reduce-qty')) {
                    const currentQty = Number(button.getAttribute('data-current-qty') || 0);
                    if (currentQty < 1) {
                        setCartStatus('Jumlah saat ini tidak valid.', 'text-danger', true);
                        return;
                    }

                    pendingReduceLineId = lineId;
                    pendingReduceCurrentQty = currentQty;
                    pendingReduceButton = button;  // Store the reduce button for approval tracking

                    // Reset modal
                    if (reduceQtyCurrent) reduceQtyCurrent.textContent = currentQty;
                    if (reduceQtyNewInput) {
                        reduceQtyNewInput.min = 1;
                        reduceQtyNewInput.max = currentQty - 1;
                        reduceQtyNewInput.value = Math.max(1, currentQty - 1);
                    }
                    if (reduceQtyReason) reduceQtyReason.value = '';
                    if (reduceQtyError) {
                        reduceQtyError.textContent = '';
                        reduceQtyError.style.display = 'none';
                    }
                    if (reduceQtySubmit) reduceQtySubmit.disabled = false;

                    // Open modal
                    if (reduceQuantityModal) {
                        $(reduceQuantityModal).modal('show');
                    }
                    return;
                }

                // Handle Check Approval / Proceed button (separate from reduce button)
                if (button.classList.contains('js-check-qty-approval')) {
                    const pendingRequestId = button.getAttribute('data-approval-pending');
                    const approvalToken = button.getAttribute('data-approval-token');
                    const approvedQty = Number(button.getAttribute('data-approved-qty') || 0);

                    if (pendingRequestId) {
                        // Check approval status
                        await ApprovalManager.checkApproval(button, '⏳ Periksa', pendingRequestId);

                        // Task 2.1: Fetch fresh cart snapshot from server after approval check
                        // The button state is temporary (set by checkApproval); the snapshot is authoritative
                        try {
                            const freshResponse = await jsonRequest(cartShowEndpoint, 'GET');
                            // Task 2.2 & 2.3: Pass fresh snapshot so line row re-renders with latest approval state
                            renderCart(freshResponse && freshResponse.cart_snapshot ? freshResponse.cart_snapshot : null);
                        } catch (error) {
                            // Task 2.4: Fallback - re-render with current snapshot if refresh fails
                            console.error('Failed to fetch fresh snapshot after approval check:', error);
                            renderCart(currentSnapshot);
                        }
                    } else if (approvalToken && approvedQty > 0) {
                        // Approved - proceed with reduction
                        const applyQtyUpdate = async (token) => {
                            const payload = { qty: approvedQty };
                            if (token) payload.approval_token = token;

                            const response = await jsonRequest(getLineEndpoint(lineId), 'PATCH', payload);
                            if (!response) {
                                throw new Error('Gagal memperbarui qty.');
                            }

                            // Clear only the QTY_REDUCE action key so unrelated actions on the same line are preserved
                            if (clientPendingApprovals[lineId]) {
                                delete clientPendingApprovals[lineId].QTY_REDUCE;
                                if (Object.keys(clientPendingApprovals[lineId]).length === 0) {
                                    delete clientPendingApprovals[lineId];
                                }
                            }

                            renderCart(response.cart_snapshot || null);
                            setCartStatus('Qty berhasil diperbarui.', 'text-success');
                        };

                        try {
                            await ApprovalManager.wrapAction(
                                button,
                                '✓ Lanjutkan',
                                'QTY_REDUCE',
                                'pos_cart_line',
                                lineId,
                                { qty: approvedQty },
                                applyQtyUpdate
                            );
                        } catch (error) {
                            setCartStatus(error.message || 'Gagal memproses pengurangan.', 'text-danger', true);
                        }
                    }
                    return;
                }

                // Handle both row monetary override buttons. One handler,
                // parameterised per action, so neither action can read the
                // other's state, endpoint, or approved value.
                const overrideControl = ROW_OVERRIDE_CONTROLS.find(
                    (candidate) => button.classList.contains(candidate.jsClass)
                );

                if (overrideControl) {
                    const pendingRequestId = button.getAttribute('data-approval-pending');
                    const approvalToken = button.getAttribute('data-approval-token');
                    const approvedValue = Number(button.getAttribute('data-approved-value') || 0);

                    if (pendingRequestId) {
                        await ApprovalManager.checkApproval(button, '\u23F3 Periksa', pendingRequestId);
                        await refreshCart();
                    } else if (approvalToken) {
                        const applyApprovedOverride = async (token) => {
                            const payload = {
                                [overrideControl.valueField]: approvedValue,
                                approval_token: token,
                            };
                            const response = await jsonRequest(
                                getLineEndpoint(lineId) + overrideControl.endpointSuffix,
                                'POST',
                                payload
                            );
                            if (!response) {
                                throw new Error(overrideControl.failureMessage);
                            }

                            // Clear only this action's key for this line.
                            if (clientPendingApprovals[lineId]) {
                                delete clientPendingApprovals[lineId][overrideControl.actionType];
                                if (Object.keys(clientPendingApprovals[lineId]).length === 0) {
                                    delete clientPendingApprovals[lineId];
                                }
                            }
                            renderCart(response.cart_snapshot || null);
                            setCartStatus(overrideControl.successMessage, 'text-success');
                        };

                        try {
                            await ApprovalManager.wrapAction(
                                button,
                                '\u2713 Lanjutkan',
                                overrideControl.actionType,
                                'pos_cart_line',
                                lineId,
                                { [overrideControl.requestedField]: approvedValue },
                                applyApprovedOverride
                            );
                        } catch (error) {
                            setCartStatus(error.message || overrideControl.failureMessage, 'text-danger', true);
                        }
                    } else {
                        const line = currentSnapshot.lines.find(l => Number(l.line_id) === lineId);
                        if (!line) return;

                        overrideControl.openModal(line, lineId, button);
                    }
                    return;
                }

                // Handle Quantity Increment button (+ spinner button)
                if (button.classList.contains('js-qty-increase')) {
                    const qtyInput = row.querySelector('.js-line-qty');
                    if (qtyInput) {
                        const currentQty = Number(qtyInput.value || 0);
                        const newQty = currentQty + 1;
                        qtyInput.value = newQty;
                        qtyInput.setAttribute('data-prev-qty', String(currentQty));
                        // Trigger change event to handle the update
                        qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    return;
                }

                // Handle Quantity Decrement button (- spinner button)
                if (button.classList.contains('js-qty-decrease')) {
                    const qtyInput = row.querySelector('.js-line-qty');
                    if (qtyInput) {
                        const currentQty = Number(qtyInput.value || 0);
                        if (currentQty > 1) {
                            const newQty = currentQty - 1;
                            qtyInput.value = newQty;
                            qtyInput.setAttribute('data-prev-qty', String(currentQty));
                            // Trigger change event to handle the update
                            qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    return;
                }
            });

            // Phase 3B: Add serial chip event handlers
            cartBody.addEventListener('click', async function (event) {
                // Handle + Serial button click
                const addSerialBtn = event.target.closest('.js-serial-add');
                if (addSerialBtn) {
                    const lineId = Number(addSerialBtn.getAttribute('data-line-id'));
                    const productName = String(addSerialBtn.getAttribute('data-product-name') || '').trim() || 'Produk';
                    
                    if (lineId) {
                        openSerialModal(lineId, productName);
                    }
                    return;
                }

                // Handle serial chip remove button click
                const removeSerialBtn = event.target.closest('.js-serial-remove');
                if (removeSerialBtn) {
                    const row = removeSerialBtn.closest('tr[data-line-id]');
                    if (!row) return;
                    
                    const lineId = Number(row.getAttribute('data-line-id'));
                    const serialNumber = removeSerialBtn.getAttribute('data-serial');
                    await removeSerialFromLine(lineId, serialNumber, 'cart');
                    return;
                }
            });

            // Quantity Reduction Modal validation and submission
            if (reduceQtyNewInput) {
                reduceQtyNewInput.addEventListener('input', function () {
                    const newQty = Number(this.value || 0);
                    const maxQty = pendingReduceCurrentQty ? (pendingReduceCurrentQty - 1) : 0;

                    if (!Number.isFinite(newQty) || newQty < 1) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = `Jumlah harus minimal 1.`;
                            reduceQtyError.style.display = 'block';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = true;
                    } else if (newQty > maxQty) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = `Jumlah harus kurang dari ${pendingReduceCurrentQty}.`;
                            reduceQtyError.style.display = 'block';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = true;
                    } else {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = '';
                            reduceQtyError.style.display = 'none';
                        }
                        if (reduceQtySubmit) reduceQtySubmit.disabled = false;
                    }
                });
            }

            // Per-control validation: each modal validates against its own
            // current value and writes to its own error node, so an error in
            // one modal can never appear in the other.
            ROW_OVERRIDE_CONTROLS.forEach((control) => {
                if (!control.els.input) {
                    return;
                }

                control.els.input.addEventListener('input', function () {
                    const state = overrideEditState[control.actionType];
                    const nextValue = Number(this.value);
                    const showError = (message) => {
                        if (control.els.error) {
                            control.els.error.textContent = message;
                            control.els.error.style.display = message ? 'block' : 'none';
                        }
                        if (control.els.submit) {
                            control.els.submit.disabled = message !== '';
                        }
                    };

                    if (this.value === '' || isNaN(nextValue)) {
                        showError(control.negativeMessage);
                    } else if (nextValue < 0) {
                        showError(control.negativeMessage);
                    } else if (nextValue === state.currentValue) {
                        showError(control.unchangedMessage);
                    } else {
                        showError('');
                    }
                });
            });


            if (reduceQtySubmit) {
                reduceQtySubmit.addEventListener('click', async function () {
                    if (!Number.isFinite(pendingReduceLineId) || pendingReduceLineId <= 0) {
                        setCartStatus('Baris keranjang tidak valid.', 'text-danger', true);
                        if (reduceQuantityModal) $(reduceQuantityModal).modal('hide');
                        return;
                    }

                    const newQty = Number(reduceQtyNewInput ? reduceQtyNewInput.value : 0);
                    const reason = reduceQtyReason ? reduceQtyReason.value.trim() || null : null;

                    if (!Number.isFinite(newQty) || newQty < 1 || newQty >= pendingReduceCurrentQty) {
                        if (reduceQtyError) {
                            reduceQtyError.textContent = 'Input jumlah tidak valid.';
                            reduceQtyError.style.display = 'block';
                        }
                        return;
                    }

                    // Close modal before submitting
                    if (reduceQuantityModal) $(reduceQuantityModal).modal('hide');

                    // Call ApprovalManager with reduction request
                    const applyQtyUpdate = async (token) => {
                        const payload = { qty: newQty };
                        if (token) payload.approval_token = token;

                        const response = await jsonRequest(getLineEndpoint(pendingReduceLineId), 'PATCH', payload);
                        if (!response) {
                            throw new Error('Gagal memperbarui qty.');
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus('Qty berhasil diperbarui.', 'text-success');
                    };

                    try {
                        // Use the actual reduce button so approval state is stored on it
                        const buttonForApproval = pendingReduceButton || reduceQtySubmit;
                        const originalButtonText = '↓ Kurangi';
                        const lineIdForStorage = pendingReduceLineId;
                        const requestedQtyForStorage = newQty;

                        await ApprovalManager.wrapAction(
                            buttonForApproval,
                            originalButtonText,
                            'QTY_REDUCE',
                            'pos_cart_line',
                            pendingReduceLineId,
                            { qty: newQty, reason: reason },
                            applyQtyUpdate
                        );

                        // After ApprovalManager sets the pending state, capture it and re-render cart
                        // to immediately show the "Periksa" button
                        const pendingRequestId = buttonForApproval.getAttribute('data-approval-pending');
                        if (pendingRequestId) {
                            // Store in client-side pending approvals scoped to QTY_REDUCE action key only
                            if (!clientPendingApprovals[lineIdForStorage]) {
                                clientPendingApprovals[lineIdForStorage] = {};
                            }
                            clientPendingApprovals[lineIdForStorage].QTY_REDUCE = {
                                requestId: pendingRequestId,
                                requestedQty: requestedQtyForStorage,
                                status: 'PENDING'
                            };
                            // Task 2.1: Fetch fresh cart snapshot from server (includes pending_approvals)
                            // before re-rendering to ensure the button renders with correct state
                            try {
                                const freshResponse = await jsonRequest(cartShowEndpoint, 'GET');
                                // Task 2.1: Ensure we pass cart_snapshot, not the wrapper response
                                renderCart(freshResponse && freshResponse.cart_snapshot ? freshResponse.cart_snapshot : null);
                            } catch (error) {
                                // Task 2.2: On refresh failure, still re-render with current snapshot
                                console.error('Failed to fetch fresh cart snapshot:', error);
                                // Fallback: re-render with current snapshot and client-side tracking
                                renderCart(currentSnapshot);
                            }
                        }
                    } catch (error) {
                        setCartStatus(error.message || 'Gagal memproses permintaan pengurangan.', 'text-danger', true);
                    } finally {
                        pendingReduceLineId = null;
                        pendingReduceCurrentQty = null;
                        pendingReduceButton = null;
                    }
                });
            }

            ROW_OVERRIDE_CONTROLS.forEach((control) => {
                if (!control.els.submit) {
                    return;
                }

                control.els.submit.addEventListener('click', async function () {
                    const state = overrideEditState[control.actionType];

                    if (!Number.isFinite(state.lineId) || state.lineId <= 0) {
                        setCartStatus('Baris keranjang tidak valid.', 'text-danger', true);
                        if (control.els.modal) $(control.els.modal).modal('hide');
                        return;
                    }

                    const nextValue = Number(control.els.input ? control.els.input.value : 0);
                    const reason = control.els.reason ? (control.els.reason.value.trim() || null) : null;

                    if (isNaN(nextValue) || nextValue < 0 || nextValue === state.currentValue) {
                        return;
                    }

                    if (control.els.modal) $(control.els.modal).modal('hide');

                    const lineIdForStorage = state.lineId;
                    const buttonForApproval = state.button || control.els.submit;

                    const applyOverride = async (token) => {
                        const payload = { [control.valueField]: nextValue, reason: reason };
                        if (token) payload.approval_token = token;

                        const response = await jsonRequest(
                            getLineEndpoint(lineIdForStorage) + control.endpointSuffix,
                            'POST',
                            payload
                        );
                        if (!response) {
                            throw new Error(control.failureMessage);
                        }

                        renderCart(response.cart_snapshot || null);
                        setCartStatus(control.successMessage, 'text-success');
                    };

                    try {
                        await ApprovalManager.wrapAction(
                            buttonForApproval,
                            '\u21A9',
                            control.actionType,
                            'pos_cart_line',
                            lineIdForStorage,
                            // Only the requested value and reason are sent; the
                            // server derives source values and the fingerprint.
                            { [control.requestedField]: nextValue, reason: reason },
                            applyOverride
                        );

                        const pendingRequestId = buttonForApproval.getAttribute('data-approval-pending');
                        if (pendingRequestId) {
                            if (!clientPendingApprovals[lineIdForStorage]) {
                                clientPendingApprovals[lineIdForStorage] = {};
                            }
                            // Keyed by action type: a pending unit-price request
                            // must never light up the row-total control.
                            clientPendingApprovals[lineIdForStorage][control.actionType] = {
                                requestId: pendingRequestId,
                                requestedValue: nextValue,
                                status: 'PENDING'
                            };
                            await refreshCart();
                        }
                    } catch (error) {
                        setCartStatus(error.message || control.failureMessage, 'text-danger', true);
                    } finally {
                        overrideEditState[control.actionType] = { lineId: null, currentValue: null, button: null };
                    }
                });
            });


            // Handle modal close/reset
            if (reduceQuantityModal) {
                reduceQuantityModal.addEventListener('hidden.bs.modal', function () {
                    pendingReduceLineId = null;
                    pendingReduceCurrentQty = null;
                    pendingReduceButton = null;
                    if (reduceQtyError) {
                        reduceQtyError.textContent = '';
                        reduceQtyError.style.display = 'none';
                    }
                });
            }

            // Each modal resets only its own state and error node on close.
            ROW_OVERRIDE_CONTROLS.forEach((control) => {
                if (!control.els.modal) {
                    return;
                }

                control.els.modal.addEventListener('hidden.bs.modal', function () {
                    overrideEditState[control.actionType] = { lineId: null, currentValue: null, button: null };
                    if (control.els.error) {
                        control.els.error.textContent = '';
                        control.els.error.style.display = 'none';
                    }
                });
            });




            // Cash Pickup Modal
            const pickupModalElement = document.getElementById('pos-cash-pickup-modal');
            const pickupBtn = document.getElementById('pos-cash-pickup-btn');
            const pickupStep1 = document.getElementById('pos-pickup-step-1');
            const pickupStep2 = document.getElementById('pos-pickup-step-2');
            const pickupStep1Footer = document.getElementById('pos-pickup-step-1-footer');
            const pickupStep2Footer = document.getElementById('pos-pickup-step-2-footer');
            const pickupTerminalInfo = document.getElementById('pos-pickup-terminal-info');
            const pickupCashierInfo = document.getElementById('pos-pickup-cashier-info');
            const pickupExpectedCash = document.getElementById('pos-pickup-expected-cash');
            const pickupAmountInput = document.getElementById('pos-pickup-amount');
            const pickupAmountError = document.getElementById('pos-pickup-amount-error');
            const pickupNextBtn = document.getElementById('pos-pickup-next-btn');
            const pickupBackBtn = document.getElementById('pos-pickup-back-btn');
            const pickupConfirmBtn = document.getElementById('pos-pickup-confirm-btn');
            const pickupSupervisorSearch = document.getElementById('pos-pickup-supervisor-search');
            const pickupSupervisorResults = document.getElementById('pos-pickup-supervisor-results');
            const pickupSupervisorSelected = document.getElementById('pos-pickup-supervisor-selected');
            const pickupSupervisorName = document.getElementById('pos-pickup-supervisor-name');
            const pickupSupervisorClear = document.getElementById('pos-pickup-supervisor-clear');
            const pickupOtpCode = document.getElementById('pos-pickup-otp-code');
            const pickupConfirmationAmount = document.getElementById('pos-pickup-confirmation-amount');
            const pickupStep2Error = document.getElementById('pos-pickup-step2-error');
            const pickupSpinner = document.getElementById('pos-pickup-spinner');
            
            let currentSessionData = null;
            let selectedSupervisor = null;
            let latestSupervisorRequestId = 0;
            let expectedCashLoadError = false;

            // Bundle selection "continue normal" handler
            if (bundleContinueNormal) {
                bundleContinueNormal.addEventListener('click', async function() {
                    if (pendingBundleProduct) {
                        const product = pendingBundleProduct;
                        const source = pendingBundleSource;
                        const serial = pendingBundleSerial; // Capture serial before hiding modal
                        
                        pendingBundleProduct = null;
                        pendingBundleSource = null;
                        pendingBundleSerial = null; // Clear it here manually
                        
                        $(bundleSelectionModal).modal('hide');
                        
                        // Proceed with normal add by passing skipBundleCheck and the captured serial
                        await addProductToCart(product, source, { 
                            skipBundleCheck: true, 
                            serialNumber: serial 
                        });
                    }
                });
            }

            // Reset bundle state when modal hidden
            if (typeof $ !== 'undefined') {
                $(bundleSelectionModal).on('hidden.bs.modal', function() {
                    pendingBundleProduct = null;
                    pendingBundleSource = null;
                    pendingBundleSerial = null;
                    if (bundleLoading) bundleLoading.classList.add('d-none');
                    if (bundleError) bundleError.classList.add('d-none');
                    if (bundleOptions) bundleOptions.innerHTML = '';
                });
            }

            function showPickupStep1() {
                pickupStep1.classList.remove('d-none');
                pickupStep2.classList.add('d-none');
                pickupStep1Footer.classList.remove('d-none');
                pickupStep2Footer.classList.add('d-none');
            }

            function showPickupStep2() {
                pickupStep1.classList.add('d-none');
                pickupStep2.classList.remove('d-none');
                pickupStep1Footer.classList.add('d-none');
                pickupStep2Footer.classList.remove('d-none');
                pickupSupervisorSearch.focus();
            }

            async function fetchLiveExpectedCash() {
                if (!currentSessionData || !currentSessionData.session_id) {
                    return false;
                }

                try {
                    pickupExpectedCash.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"><span class="sr-only">Loading...</span></div>';
                    pickupAmountInput.disabled = true;
                    pickupNextBtn.disabled = true;
                    expectedCashLoadError = false;

                    const sessionId = currentSessionData.session_id;
                    const endpoint = `{{ url('/pos/sessions') }}/${sessionId}/summary`;

                    const response = await fetch(endpoint, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        throw new Error('Failed to fetch expected cash');
                    }

                    const data = await response.json();
                    const expectedCashValue = data.expected_cash_total || 0;

                    currentSessionData.expected_cash = Number(expectedCashValue);
                    pickupExpectedCash.textContent = formatPrice(expectedCashValue);
                    pickupAmountInput.disabled = false;
                    pickupAmountInput.max = expectedCashValue;
                    pickupAmountError.classList.add('d-none');
                    pickupNextBtn.disabled = false;

                    return true;
                } catch (error) {
                    expectedCashLoadError = true;
                    pickupExpectedCash.innerHTML = '<span class="text-danger small">Gagal memuat data kas. <button type="button" class="btn btn-link btn-sm" id="pos-pickup-retry-cash">Coba lagi</button></span>';
                    pickupAmountInput.disabled = true;
                    pickupNextBtn.disabled = true;

                    const retryBtn = document.getElementById('pos-pickup-retry-cash');
                    if (retryBtn) {
                        retryBtn.addEventListener('click', fetchLiveExpectedCash);
                    }

                    return false;
                }
            }

            function formatPrice(amount) {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    maximumFractionDigits: 0
                }).format(amount || 0);
            }

            function resetSupervisorSelection() {
                selectedSupervisor = null;
                pickupSupervisorSearch.value = '';
                pickupSupervisorResults.innerHTML = '';
                pickupSupervisorResults.style.display = 'none';
                pickupSupervisorSelected.classList.add('d-none');
                pickupOtpCode.value = '';
                updateConfirmButtonState();
            }

            function updateConfirmButtonState() {
                const hasOtp = pickupOtpCode.value.length === 6 && /^\d{6}$/.test(pickupOtpCode.value);
                pickupConfirmBtn.disabled = !selectedSupervisor || !hasOtp;
            }

            async function searchSupervisors(query) {
                if (!query || query.trim().length === 0) {
                    pickupSupervisorResults.innerHTML = '';
                    pickupSupervisorResults.style.display = 'none';
                    return;
                }

                latestSupervisorRequestId++;
                const requestId = latestSupervisorRequestId;

                try {
                    const endpoint = `{{ route('pos.sell.supervisors.search') }}?q=${encodeURIComponent(query)}&limit=10`;
                    const response = await fetch(endpoint, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        throw new Error('Failed to search supervisors');
                    }

                    // Ignore stale responses
                    if (requestId !== latestSupervisorRequestId) {
                        return;
                    }

                    const data = await response.json();
                    const results = data.results || [];

                    if (results.length === 0) {
                        pickupSupervisorResults.innerHTML = '<div class="list-group-item text-muted small">Tidak ada supervisor dengan OTP aktif.</div>';
                        pickupSupervisorResults.style.display = 'block';
                        return;
                    }

                    pickupSupervisorResults.innerHTML = results.map(supervisor => 
                        `<button type="button" class="list-group-item list-group-item-action supervisor-result" data-id="${supervisor.id}" data-name="${supervisor.name}" data-email="${supervisor.email}">
                            <div class="font-weight-bold small">${supervisor.name}</div>
                            <div class="text-muted small">${supervisor.email}</div>
                        </button>`
                    ).join('');

                    pickupSupervisorResults.style.display = 'block';

                    // Add click handlers to results
                    document.querySelectorAll('.supervisor-result').forEach(btn => {
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            selectSupervisor({
                                id: Number(this.getAttribute('data-id')),
                                name: this.getAttribute('data-name'),
                                email: this.getAttribute('data-email')
                            });
                        });
                    });
                } catch (error) {
                    console.error('Supervisor search error:', error);
                    pickupSupervisorResults.innerHTML = '';
                    pickupSupervisorResults.style.display = 'none';
                }
            }

            function selectSupervisor(supervisor) {
                selectedSupervisor = supervisor;
                pickupSupervisorSearch.value = '';
                pickupSupervisorResults.innerHTML = '';
                pickupSupervisorResults.style.display = 'none';
                pickupSupervisorSelected.classList.remove('d-none');
                pickupSupervisorName.textContent = `${supervisor.name} (${supervisor.email})`;
                pickupOtpCode.focus();
                updateConfirmButtonState();
            }

            if (pickupAmountInput && pickupNextBtn) {
                pickupAmountInput.addEventListener('input', function () {
                    const amount = Number(pickupAmountInput.value || 0);
                    const expectedCash = currentSessionData && currentSessionData.expected_cash ? Number(currentSessionData.expected_cash) : 0;

                    pickupAmountError.classList.add('d-none');

                    if (amount <= 0) {
                        pickupAmountError.textContent = 'Jumlah pengambilan harus lebih dari 0.';
                        pickupAmountError.classList.remove('d-none');
                        pickupNextBtn.disabled = true;
                        return;
                    }

                    if (amount > expectedCash) {
                        pickupAmountError.textContent = 'Jumlah pengambilan tidak boleh melebihi ekspektasi kas.';
                        pickupAmountError.classList.remove('d-none');
                        pickupNextBtn.disabled = true;
                        return;
                    }

                    pickupNextBtn.disabled = false;
                });
            }

            if (pickupSupervisorSearch) {
                let searchTimeout;
                pickupSupervisorSearch.addEventListener('input', function (e) {
                    clearTimeout(searchTimeout);
                    const query = e.target.value.trim();
                    
                    if (query.length > 0) {
                        searchTimeout = setTimeout(() => searchSupervisors(query), 250);
                    } else {
                        pickupSupervisorResults.innerHTML = '';
                        pickupSupervisorResults.style.display = 'none';
                    }
                });
            }

            if (pickupOtpCode) {
                pickupOtpCode.addEventListener('input', function () {
                    // Only allow digits
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
                    updateConfirmButtonState();
                });
            }

            if (pickupSupervisorClear) {
                pickupSupervisorClear.addEventListener('click', resetSupervisorSelection);
            }

            if (pickupNextBtn) {
                pickupNextBtn.addEventListener('click', function () {
                    const amount = Number(pickupAmountInput.value || 0);
                    const expectedCash = currentSessionData && currentSessionData.expected_cash ? Number(currentSessionData.expected_cash) : 0;

                    if (amount <= 0 || amount > expectedCash) {
                        pickupAmountError.textContent = 'Jumlah pengambilan tidak valid.';
                        pickupAmountError.classList.remove('d-none');
                        return;
                    }

                    pickupConfirmationAmount.innerHTML = `<span>Rp ${amount.toLocaleString('id-ID')}</span>`;
                    resetSupervisorSelection();
                    showPickupStep2();
                });
            }

            if (pickupBackBtn) {
                pickupBackBtn.addEventListener('click', function () {
                    showPickupStep1();
                });
            }

            if (pickupConfirmBtn) {
                pickupConfirmBtn.addEventListener('click', async function () {
                    if (!selectedSupervisor) {
                        pickupStep2Error.textContent = 'Pilih supervisor terlebih dahulu.';
                        pickupStep2Error.classList.remove('d-none');
                        return;
                    }

                    const otpCode = (pickupOtpCode.value || '').trim();
                    if (!otpCode || !/^\d{6}$/.test(otpCode)) {
                        pickupStep2Error.textContent = 'Kode OTP harus 6 digit.';
                        pickupStep2Error.classList.remove('d-none');
                        return;
                    }

                    pickupStep2Error.classList.add('d-none');
                    pickupConfirmBtn.disabled = true;
                    if (pickupSpinner) pickupSpinner.classList.remove('d-none');

                    try {
                        const sessionId = currentSessionData && currentSessionData.session_id ? currentSessionData.session_id : null;
                        if (!sessionId) {
                            throw new Error('Session ID tidak ditemukan.');
                        }

                        const amount = Number(pickupAmountInput.value || 0);
                        const endpoint = `{{ url('/pos/sessions') }}/${sessionId}/pickup`;

                        const response = await jsonRequest(endpoint, 'POST', {
                            amount: amount,
                            supervisor_id: selectedSupervisor.id,
                            otp_code: otpCode
                        });

                        if (response) {
                            $(pickupModalElement).modal('hide');
                            const newExpectedCash = response.expected_cash_after || 0;
                            const message = `Pengambilan kas berhasil. Ekspektasi kas: ${formatPrice(newExpectedCash)}`;
                            showToast(message, 'success');

                            // Refresh cart to update expected cash display
                            refreshCart();
                        }
                    } catch (error) {
                        pickupStep2Error.textContent = error.message || 'Gagal memproses pengambilan kas.';
                        pickupStep2Error.classList.remove('d-none');
                    } finally {
                        pickupConfirmBtn.disabled = false;
                        if (pickupSpinner) pickupSpinner.classList.add('d-none');
                    }
                });
            }

            if (pickupBtn) {
                pickupBtn.addEventListener('click', async function (e) {
                    e.preventDefault();
                    // Get dropdown menu element with session data attributes
                    const dropdownMenu = document.querySelector('.dropdown-menu[data-session-id]');
                    if (dropdownMenu) {
                        currentSessionData = {
                            session_id: Number(dropdownMenu.getAttribute('data-session-id')),
                            terminal_code: dropdownMenu.getAttribute('data-terminal-code') || '-',
                            terminal_name: dropdownMenu.getAttribute('data-terminal-name') || '-',
                            cashier_name: dropdownMenu.getAttribute('data-cashier-name') || '-',
                            expected_cash: 0 // Will be fetched live
                        };

                        const terminal = `${currentSessionData.terminal_code} - ${currentSessionData.terminal_name}`;
                        if (pickupTerminalInfo) pickupTerminalInfo.textContent = terminal;
                        if (pickupCashierInfo) pickupCashierInfo.textContent = currentSessionData.cashier_name;

                        pickupAmountInput.value = '';
                        pickupAmountError.classList.add('d-none');
                        pickupNextBtn.disabled = true;

                        showPickupStep1();
                        if (pickupModalElement) {
                            if (typeof $ !== 'undefined') {
                                $(pickupModalElement).modal('show');
                            } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                new bootstrap.Modal(pickupModalElement).show();
                            }
                        }
                        if (pickupAmountInput) pickupAmountInput.focus();

                        // Fetch live expected cash after modal opens
                        await fetchLiveExpectedCash();

                        // Close dropdown if it's open
                        const dropdownToggle = document.getElementById('pos-nav-menu-dropdown');
                        if (dropdownToggle && typeof $ !== 'undefined') {
                            $(dropdownToggle).dropdown('toggle');
                        }
                    }
                });
            }

            if (pickupModalElement) {
                pickupModalElement.addEventListener('hidden.bs.modal', function () {
                    currentSessionData = null;
                    pickupAmountInput.value = '';
                    pickupStep2Error.classList.add('d-none');
                    pickupAmountError.classList.add('d-none');
                    resetSupervisorSelection();
                    expectedCashLoadError = false;
                    showPickupStep1();
                });
            }

            // Close Session Handler
            const closeSessionBtn = document.getElementById('pos-close-session-btn');
            let closeSessionData = null;

            if (closeSessionBtn) {
                closeSessionBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    console.log('[POS Session] Close session button clicked');
                    // Get dropdown menu element with session data attributes
                    const dropdownMenu = document.querySelector('.dropdown-menu[data-session-id]');
                    if (dropdownMenu) {
                        closeSessionData = {
                            session_id: Number(dropdownMenu.getAttribute('data-session-id')),
                        };

                        console.log('[POS Session] Closing session', closeSessionData);

                        // Close dropdown if it's open
                        const dropdownToggle = document.getElementById('pos-nav-menu-dropdown');
                        if (dropdownToggle && typeof $ !== 'undefined') {
                            $(dropdownToggle).dropdown('toggle');
                        }

                        const closeBtn = closeSessionBtn;
                        closeBtn.disabled = true;
                        const originalText = closeBtn.innerHTML;
                        closeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';

                        fetch(`/pos/sessions/${closeSessionData.session_id}/close`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        })
                            .then(response => {
                                console.log('[POS Session] Close session response', {
                                    status: response.status,
                                    statusText: response.statusText,
                                });

                                if (!response.ok) {
                                    return response.json().then(data => {
                                        throw new Error(data.message || 'Gagal menutup sesi');
                                    });
                                }
                                return response.json();
                            })
                            .then(data => {
                                console.log('[POS Session] Close session successful', data);
                                alert('Terminal berhasil dirilis. Silakan buka sesi baru atau keluar.');
                                window.location.href = '{{ route("home") }}';
                            })
                            .catch(error => {
                                console.error('[POS Session] Close session error', error);
                                alert(error.message || 'Gagal menutup sesi');
                                closeBtn.disabled = false;
                                closeBtn.innerHTML = originalText;
                            });
                    } else {
                        console.warn('[POS Session] Dropdown menu not found');
                    }
                });
            }

            function generateIdempotencyKey() {
                return 'pos-' + Date.now() + '-' + Math.random().toString(36).substring(2, 15);
            }

            function renderReceiptPreview(snapshot) {
                if (!checkoutReceiptLines) return;

                const lines = snapshot && Array.isArray(snapshot.lines) ? snapshot.lines : [];

                if (lines.length === 0) {
                    checkoutReceiptLines.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Keranjang kosong.</td></tr>';
                } else {
                    checkoutReceiptLines.innerHTML = lines.map(line => {
                        const productName = escapeHtml(line.product_name || '-');
                        const qty = Number(line.qty || 0);
                        const unitPrice = formatPrice(line.unit_price || 0);
                        const total = formatPrice(line.line_total || 0);

                        return `
                            <tr>
                                <td>
                                    <div class="text-truncate" style="max-width: 15rem;" title="${productName}">${productName}</div>
                                </td>
                                <td class="text-right">${qty}</td>
                                <td class="text-right">${unitPrice}</td>
                                <td class="text-right font-weight-bold">${total}</td>
                            </tr>
                        `;
                    }).join('');
                }

                const totals = snapshot && snapshot.totals ? snapshot.totals : {};
                if (checkoutReceiptTotal) {
                    checkoutReceiptTotal.textContent = formatPrice(totals.grand_total || 0);
                }
            }

            // Phase 3D: Load payment methods from API
            async function loadPaymentMethods() {
                try {
                    const response = await jsonRequest(paymentMethodSearchEndpoint, 'GET');
                    if (response && Array.isArray(response.methods)) {
                        cachedPaymentMethods = response.methods;
                        return true;
                    }
                } catch (error) {
                    console.error('Failed to load payment methods:', error);
                }
                return false;
            }

            // Phase 3D: Render payment method search results
            function renderPaymentMethodResults(results) {
                if (!checkoutMethodResults) return;
                
                checkoutMethodResults.innerHTML = '';
                if (results.length === 0) {
                    checkoutMethodResults.style.display = 'none';
                    return;
                }

                results.forEach(method => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'list-group-item list-group-item-action';
                    button.innerHTML = `<div class="font-weight-bold">${escapeHtml(method.name)}</div>`;
                    button.addEventListener('click', function () {
                        selectPaymentMethod(method);
                    });
                    checkoutMethodResults.appendChild(button);
                });

                checkoutMethodResults.style.display = 'block';
            }

            // Task 4.2: Select payment method and add to composer (not replace)
            function selectPaymentMethod(method) {
                addPaymentRow(method);
            }

            function openPaymentModal() {
                if (!currentSnapshot || !currentSnapshot.totals) return;

                const grandTotal = Number(currentSnapshot.totals.grand_total || 0);
                if (grandTotal <= 0) return;

                checkoutTotalLabel.value = formatPrice(grandTotal);
                checkoutError.classList.add('d-none');
                checkoutError.textContent = '';

                // Task 4.1: Reset payment composer
                checkoutPayments = [];
                renderPaymentsList();
                updatePaymentSummary();

                renderReceiptPreview(currentSnapshot);

                // Load payment methods before showing modal
                (async () => {
                    const loaded = await loadPaymentMethods();
                    if (!loaded || cachedPaymentMethods.length === 0) {
                        checkoutError.textContent = 'Tidak ada metode pembayaran yang diaktifkan untuk unit ini. Atur di Konfigurasi Pembayaran POS.';
                        checkoutError.classList.remove('d-none');
                        if (checkoutSubmit) checkoutSubmit.disabled = true;
                    }
                })();

                $(checkoutModalElement).modal('show');
                setTimeout(() => checkoutMethodSearch.focus(), 200);
            }

            // Task 4.2: Payment method search handler for composer
            // Phase 3D: Add payment method search input handler
            if (checkoutMethodSearch) {
                checkoutMethodSearch.addEventListener('input', function () {
                    const query = (this.value || '').trim().toLowerCase();
                    
                    if (query.length === 0) {
                        renderPaymentMethodResults(cachedPaymentMethods);
                        return;
                    }

                    const filtered = cachedPaymentMethods.filter(method => 
                        (method.name || '').toLowerCase().includes(query)
                    );
                    renderPaymentMethodResults(filtered);
                });

                checkoutMethodSearch.addEventListener('focus', function () {
                    if (cachedPaymentMethods.length > 0) {
                        renderPaymentMethodResults(cachedPaymentMethods);
                    }
                });
            }

            if (checkoutMethodResults) {
                checkoutMethodResults.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (checkoutMethodResults && 
                    !checkoutMethodResults.contains(e.target) && 
                    checkoutMethodSearch && 
                    !checkoutMethodSearch.contains(e.target)) {
                    checkoutMethodResults.style.display = 'none';
                }
            });

            if (btnCheckout) {
                btnCheckout.addEventListener('click', async function () {
                    if (requiresTerminalForCheckout) {
                        setCartStatus('Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran.', 'text-danger');
                        return;
                    }

                    if (!hasCheckoutAuthority) {
                        setCartStatus('Anda tidak memiliki izin pembayaran POS.', 'text-danger');
                        return;
                    }

                    if (noteDebounceHandle) {
                        clearTimeout(noteDebounceHandle);
                        const saved = await submitNoteUpdate();
                        if (!saved) {
                            setCartStatus('Gagal menyimpan catatan, pembayaran dibatalkan.', 'text-danger');
                            return;
                        }
                    }

                    console.log('[CHECKOUT] Preflight check initiated');
                    const originalHtml = btnCheckout.innerHTML;
                    btnCheckout.disabled = true;
                    btnCheckout.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validasi...';

                    let acknowledgedWarning = false;
                    try {
                        const preflight = await jsonRequest(checkoutPreflightEndpoint, 'POST', {
                            acknowledge_lifecycle_warning: false
                        });
                        console.log('[CHECKOUT] Preflight success', preflight);
                    } catch (error) {
                        const helper = window.BundleLifecycleWarning;
                        if (!helper || typeof helper.resolveLifecycleWarning !== 'function' || typeof helper.buildLifecycleWarningModalHtml !== 'function') {
                            console.error('BundleLifecycleWarning helper is unavailable.');
                            btnCheckout.disabled = false;
                            btnCheckout.innerHTML = originalHtml;
                            setCartStatus('Gagal memverifikasi status paket produk: modul peringatan tidak tersedia.', 'text-danger', true);
                            return;
                        }

                        const warningData = helper.resolveLifecycleWarning(error);

                        if (warningData && Array.isArray(warningData.items) && warningData.items.length > 0) {
                            const modalHtml = helper.buildLifecycleWarningModalHtml(
                                warningData,
                                'Terdapat perubahan status pada paket produk dalam transaksi ini.',
                                'Apakah Anda ingin melanjutkan transaksi dengan komposisi yang tersimpan?'
                            );

                            const result = await Swal.fire({
                                icon: 'warning',
                                title: 'Peringatan Status Paket',
                                html: modalHtml,
                                showCancelButton: true,
                                confirmButtonText: 'Lanjutkan Transaksi',
                                cancelButtonText: 'Batal',
                            });

                            if (result.isConfirmed) {
                                acknowledgedWarning = true;
                                window.posLifecycleAcknowledged = true;
                                if (typeof PosStagedPayment !== 'undefined' && typeof PosStagedPayment.setLifecycleAcknowledged === 'function') {
                                    PosStagedPayment.setLifecycleAcknowledged(true);
                                }
                                try {
                                    await jsonRequest(checkoutPreflightEndpoint, 'POST', {
                                        acknowledge_lifecycle_warning: true
                                    });
                                } catch (ackError) {
                                    btnCheckout.disabled = false;
                                    btnCheckout.innerHTML = originalHtml;
                                    const hasUnfulfilled = Array.isArray(ackError.details?.unfulfilled_lines) && ackError.details.unfulfilled_lines.length > 0;
                                    const hasInvalid = Array.isArray(ackError.details?.invalid_lines) && ackError.details.invalid_lines.length > 0;
                                    if (ackError.details && (hasUnfulfilled || hasInvalid)) {
                                        showMismatchModal(ackError.message, ackError.details);
                                    } else {
                                        setCartStatus(ackError.message || 'Validasi checkout gagal.', 'text-danger', true);
                                    }
                                    return;
                                }
                            } else {
                                btnCheckout.disabled = false;
                                btnCheckout.innerHTML = originalHtml;
                                return;
                            }
                        } else {
                            console.error('[CHECKOUT] Preflight failed', error);
                            btnCheckout.disabled = false;
                            btnCheckout.innerHTML = originalHtml;

                            const hasUnfulfilled = Array.isArray(error.details?.unfulfilled_lines) && error.details.unfulfilled_lines.length > 0;
                            const hasInvalid = Array.isArray(error.details?.invalid_lines) && error.details.invalid_lines.length > 0;

                            if (error.details && (hasUnfulfilled || hasInvalid)) {
                                showMismatchModal(error.message, error.details);
                            } else {
                                setCartStatus(error.message || 'Validasi checkout gagal.', 'text-danger', true);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal Validasi',
                                    text: error.message || 'Terdapat kendala pada stok atau serial produk Anda.',
                                });
                            }
                            return;
                        }
                    }

                    btnCheckout.disabled = false;
                    btnCheckout.innerHTML = originalHtml;

                    console.log('[CHECKOUT] Proceeding to staged payment modal', { currentSnapshot, PosStagedPayment: typeof PosStagedPayment });

                    // Wire to staged payment flow using cart token and grand total
                    if (currentSnapshot && currentSnapshot.totals) {
                        if (typeof PosStagedPayment !== 'undefined' && typeof PosStagedPayment.setLifecycleAcknowledged === 'function') {
                            PosStagedPayment.setLifecycleAcknowledged(acknowledgedWarning || !!window.posLifecycleAcknowledged);
                        }

                        // Generate token if it doesn't exist yet
                        let cartToken = currentSnapshot.staged_payment_token;
                        if (!cartToken) {
                            cartToken = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                                const r = Math.random() * 16 | 0;
                                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                                return v.toString(16);
                            });
                            currentSnapshot.staged_payment_token = cartToken;
                            console.log('[CHECKOUT] Generated new token:', cartToken);
                        }

                        const grandTotal = currentSnapshot.totals.grand_total || 0;
                        const hasCustomer = currentSnapshot.customer && currentSnapshot.customer.resolved_customer_id ? true : false;
                        const customerName = hasCustomer && currentSnapshot.customer.selected_customer ? currentSnapshot.customer.selected_customer.customer_name : '-';
                        console.log('[CHECKOUT] Opening modal with token:', cartToken, 'grandTotal:', grandTotal, 'hasCustomer:', hasCustomer, 'customerName:', customerName);

                        if (typeof PosStagedPayment !== 'undefined') {
                            PosStagedPayment.openModal(cartToken, grandTotal, hasCustomer, customerName);
                        } else {
                            console.error('[CHECKOUT] PosStagedPayment module not loaded');
                        }
                    } else {
                        console.warn('[CHECKOUT] No snapshot or totals available');
                    }
                });
            }

            // Task 4.4: Checkout submit with multi-payment payload
            if (checkoutSubmit) {
                checkoutSubmit.addEventListener('click', async function () {
                    // Validate before submit
                    if (!validatePaymentComposer()) {
                        return;
                    }

                    checkoutSubmit.disabled = true;
                    checkoutSubmit.textContent = 'Memproses...';
                    checkoutError.classList.add('d-none');

                    try {
                        // Task 4.4: Build payments[] array payload (multi-payment support)
                        // Also include legacy payment field for fallback compatibility
                        const payments = checkoutPayments.map(p => ({
                            payment_method_id: p.method.id,
                            amount_paid: Number(p.amount),
                            reference: p.reference || null
                        }));

                        const payload = {
                            idempotency_key: generateIdempotencyKey(),
                            acknowledge_lifecycle_warning: !!window.posLifecycleAcknowledged,
                            payments: payments,
                            // Legacy compatibility: use first payment method as fallback
                            payment: {
                                payment_method_id: checkoutPayments[0]?.method?.id,
                                amount_paid: checkoutPayments[0]?.amount || 0,
                                reference: checkoutPayments[0]?.reference || null
                            }
                        };

                        const response = await jsonRequest(finalizeEndpoint, 'POST', payload);
                        if (!response) {
                            checkoutSubmit.disabled = false;
                            checkoutSubmit.textContent = 'Konfirmasi Pembayaran';
                            return;
                        }

                        $(checkoutModalElement).modal('hide');

                        if (successReceiptElement) successReceiptElement.textContent = 'No. Struk: ' + (response.receipt_number || '-');
                        if (successChangeElement) {
                            const change = Number(response.change_total || 0);
                            successChangeElement.textContent = change > 0 ? 'Kembalian: ' + formatPrice(change) : '';
                        }

                        window.lastCheckoutId = response.pos_checkout_id;
                        window.posLifecycleAcknowledged = false;
                        if (shortcutReprintBtn) shortcutReprintBtn.disabled = false;

                        $('#pos-success-modal').modal('show');

                        renderCart(null);
                        setCartStatus('Transaksi berhasil diselesaikan.', 'text-success');
                    } catch (error) {
                        checkoutError.textContent = error.message || 'Gagal memproses pembayaran.';
                        checkoutError.classList.remove('d-none');
                    } finally {
                        checkoutSubmit.disabled = false;
                        checkoutSubmit.textContent = 'Konfirmasi Pembayaran';
                    }
                });
            }

            window.printReceipt = function () {
                if (window.lastCheckoutId) {
                    const url = `{{ url('/pos/sell/checkout') }}/${window.lastCheckoutId}/receipt`;
                    window.open(url, '_blank');
                }
            };

            if (shortcutReprintBtn) {
                shortcutReprintBtn.addEventListener('click', function () {
                    if (window.lastCheckoutId) {
                        const url = `{{ url('/pos/sell/checkout') }}/${window.lastCheckoutId}/receipt/reprint`;
                        window.open(url, '_blank');
                    }
                });
            }

            // Initialize Staged Payment Module
            if (hasCheckoutAuthority && typeof PosStagedPayment !== 'undefined') {
                PosStagedPayment.initialize({
                    modalElement: document.getElementById('pos-staged-checkout-modal'),
                    methodSearchInput: document.getElementById('staged-method-search'),
                    methodResults: document.getElementById('staged-method-results'),
                    paymentChainList: document.getElementById('staged-payment-chain'),
                    remainderLabel: document.getElementById('staged-remainder-amount'),
                    amountInput: document.getElementById('staged-amount-input'),
                    edcRefInput: document.getElementById('staged-edc-reference'),
                    edcRefContainer: document.getElementById('staged-edc-reference-container'),
                    submitButton: document.getElementById('staged-payment-submit'),
                    spinner: document.getElementById('staged-payment-spinner'),
                    errorAlert: document.getElementById('staged-payment-error'),
                    canUsePaymentFlow: canCheckoutByRole,
                    paymentFlowBlockedMessage: requiresTerminalForCheckout
                        ? 'Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran.'
                        : 'Anda tidak memiliki izin pembayaran POS.',
                });

                // Load payment methods
                if (canCheckoutByRole && typeof window.POS_PAYMENT_METHODS !== 'undefined') {
                    PosStagedPayment.setPaymentMethods(window.POS_PAYMENT_METHODS);
                } else if (canCheckoutByRole) {
                    // Fallback: load payment methods from API
                    PosStagedPayment.loadPaymentMethods();
                }

                // Set onComplete callback after staged payment has already finalized checkout
                PosStagedPayment.setOnComplete(async function(changeAmount, finalizedCheckout) {
                    const gratitudeModal = document.getElementById('pos-gratitude-modal');
                    const gratitudeBtn = document.getElementById('pos-gratitude-continue-btn');
                    const checkoutId = finalizedCheckout?.payload?.checkout?.id
                        || finalizedCheckout?.checkout?.id
                        || finalizedCheckout?.pos_checkout_id;

                    if (checkoutId) {
                        window.lastCheckoutId = checkoutId;
                        if (shortcutReprintBtn) shortcutReprintBtn.disabled = false;
                    }

                    console.log('[GRATITUDE SETUP] Button found:', !!gratitudeBtn, 'Button element:', gratitudeBtn);

                    if (gratitudeBtn) {
                        gratitudeBtn.onclick = async function(e) {
                            console.log('[GRATITUDE] Button clicked!');
                            e.preventDefault();

                            try {
                                $(gratitudeModal).modal('hide');

                                if (checkoutId) {
                                    window.open(`/pos/sell/checkout/${checkoutId}/receipt`, '_blank');
                                }

                                await refreshCart();
                                setCartStatus('Transaksi berhasil diselesaikan.', 'text-success');
                            } catch (error) {
                                console.error('[GRATITUDE] Error after finalized checkout:', error);
                                alert('Terjadi kesalahan: ' + error.message);
                            }
                        };
                    }
                });

                // Check for reload recovery on page load
                if (canCheckoutByRole && currentSnapshot && currentSnapshot.staged_payment_token) {
                    fetch(`/pos/sell/checkout/payment-chain?cart_token=${encodeURIComponent(currentSnapshot.staged_payment_token)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.has_chain && data.payment_chain) {
                                const hasCustomer = currentSnapshot.customer && currentSnapshot.customer.resolved_customer_id ? true : false;
                                const customerName = hasCustomer && currentSnapshot.customer.selected_customer ? currentSnapshot.customer.selected_customer.customer_name : '-';
                                PosStagedPayment.openModal(
                                    currentSnapshot.staged_payment_token,
                                    currentSnapshot.totals?.grand_total || 0,
                                    hasCustomer,
                                    customerName
                                );
                            }
                        })
                        .catch(e => console.error('Reload recovery error:', e));
                }
            }

            refreshCart();
        })();
    </script>
@endpush
@endsection
