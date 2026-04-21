/**
 * POS Multi-Stage Sequential Payments Module
 * Handles staged payment flow with remainder tracking and reload recovery
 *
 * Tasks 12-35: Frontend state machine, UI components, and reload recovery
 */

window.PosStagedPayment = (function () {
    'use strict';

    // State Machine States
    const States = {
        IDLE: 'idle',
        SELECTING_METHOD: 'selecting_method',
        VALIDATING_REFERENCE: 'validating_reference',
        PROCESSING: 'processing',
        COMPLETE: 'complete',
    };

    // Module state
    let state = States.IDLE;
    let currentSaleId = null;
    let paymentChain = null;
    let currentStageData = null;
    let onCompleteCallback = null;

    // DOM elements
    let stagedModalElement = null;
    let stagedMethodSearchInput = null;
    let stagedMethodResultsDropdown = null;
    let stagedPaymentChainList = null;
    let stagedRemainderLabel = null;
    let stagedAmountInput = null;
    let stagedAmountHint = null;
    let stagedEdcReferenceInput = null;
    let stagedEdcReferenceContainer = null;
    let stagedSubmitButton = null;
    let stagedProcessingSpinner = null;
    let stagedErrorAlert = null;

    // Cached data
    let cachedPaymentMethods = [];
    let selectedPaymentMethod = null;
    let canUsePaymentFlow = true;
    let paymentFlowBlockedMessage = 'Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran.';

    // Task 3.1: Initialize state machine
    function initialize(config = {}) {
        console.log('[PosStagedPayment] Initializing with config:', config);

        state = States.IDLE;
        paymentChain = null;
        currentStageData = null;
        selectedPaymentMethod = null;

        // Cache DOM elements
        stagedModalElement = config.modalElement || document.getElementById('pos-staged-checkout-modal');
        stagedMethodSearchInput = config.methodSearchInput || document.getElementById('staged-method-search');
        stagedMethodResultsDropdown = config.methodResults || document.getElementById('staged-method-results');
        stagedPaymentChainList = config.paymentChainList || document.getElementById('staged-payment-chain');
        stagedRemainderLabel = config.remainderLabel || document.getElementById('staged-remainder-amount');
        stagedAmountInput = config.amountInput || document.getElementById('staged-amount-input');
        stagedAmountHint = config.amountHint || document.getElementById('staged-amount-hint');
        stagedEdcReferenceInput = config.edcRefInput || document.getElementById('staged-edc-reference');
        stagedEdcReferenceContainer = config.edcRefContainer || document.getElementById('staged-edc-reference-container');
        stagedSubmitButton = config.submitButton || document.getElementById('staged-payment-submit');
        stagedProcessingSpinner = config.spinner || document.getElementById('staged-payment-spinner');
        stagedErrorAlert = config.errorAlert || document.getElementById('staged-payment-error');
        canUsePaymentFlow = config.canUsePaymentFlow !== false;
        paymentFlowBlockedMessage = config.paymentFlowBlockedMessage || paymentFlowBlockedMessage;

        console.log('[PosStagedPayment] DOM elements cached:', {
            modalElement: !!stagedModalElement,
            methodSearchInput: !!stagedMethodSearchInput,
            paymentChainList: !!stagedPaymentChainList,
            submitButton: !!stagedSubmitButton,
        });

        setupEventListeners();

        // Task 5.1 & 5.2: Initialize formatters
        setupAmountInputFormatter();
        setupQuickAddButtons();
    }

    // Task 3.2: Setup event listeners
    function setupEventListeners() {
        if (stagedMethodSearchInput) {
            stagedMethodSearchInput.addEventListener('input', handleMethodSearch);
            stagedMethodSearchInput.addEventListener('focus', handleMethodFocus);
        }

        if (stagedMethodResultsDropdown) {
            stagedMethodResultsDropdown.addEventListener('click', handleMethodSelect);
        }

        if (stagedAmountInput) {
            stagedAmountInput.addEventListener('input', updateStageValidation);
        }

        if (stagedEdcReferenceInput) {
            stagedEdcReferenceInput.addEventListener('input', function (event) {
                validateEdcReferenceRealtime(event);
                updateStageValidation();
            });
        }

        if (stagedSubmitButton) {
            stagedSubmitButton.addEventListener('click', submitStagePayment);
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            if (stagedMethodResultsDropdown &&
                !stagedMethodResultsDropdown.contains(e.target) &&
                stagedMethodSearchInput &&
                !stagedMethodSearchInput.contains(e.target)) {
                stagedMethodResultsDropdown.style.display = 'none';
            }
        });
    }

    // Task 5.1 & 5.2: Open modal with cart token and grand total
    async function openModal(cartToken, grandTotal) {
        console.log('[PosStagedPayment] openModal called with:', { cartToken, grandTotal });

        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        if (!cartToken || grandTotal === null || grandTotal === undefined) {
            console.warn('[PosStagedPayment] Invalid parameters, returning');
            return;
        }

        state = States.SELECTING_METHOD;
        clearErrors();

        // Try to recover payment chain from session
        const recovered = await checkReloadRecovery(cartToken, grandTotal);
        if (!recovered) {
            // Start fresh with provided grand total
            initializeNewPaymentChain(cartToken, grandTotal);
        }

        if (stagedModalElement) {
            console.log('[PosStagedPayment] Showing modal');
            $(stagedModalElement).modal('show');
        } else {
            console.error('[PosStagedPayment] Modal element not found');
        }
    }

    // Task 5.3: Check and recover payment chain from session
    async function checkReloadRecovery(cartToken, grandTotal) {
        if (!ensurePaymentFlowAvailable()) {
            return false;
        }

        try {
            const response = await fetch(`/pos/sell/checkout/payment-chain?cart_token=${encodeURIComponent(cartToken)}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
            });

            if (!response.ok) return false;

            const data = await response.json();
            if (!data.has_chain) return false;

            // Recover payment chain from session
            // Task 1.4: Restore original_grand_total from session
            paymentChain = {
                cart_token: cartToken,
                original_grand_total: data.payment_chain.original_grand_total || Number(grandTotal) || 0,
                remainder: data.payment_chain.remainder || 0,
                payments: data.payment_chain.payments || [],
            };

            renderPaymentChain();
            updateRemainderDisplay();
            return true;
        } catch (error) {
            console.error('Reload recovery error:', error);
            return false;
        }
    }

    // Initialize fresh payment chain with provided grand total
    // Task 1.1 & 1.3: Store original_grand_total separately from remainder
    function initializeNewPaymentChain(cartToken, grandTotal) {
        const originalGrandTotal = Number(grandTotal) || 0;
        paymentChain = {
            cart_token: cartToken,
            original_grand_total: originalGrandTotal,  // Store at initialization, NEVER change
            remainder: originalGrandTotal,              // Running balance, updates each stage
            payments: [],
        };

        renderPaymentChain();
        updateRemainderDisplay();
    }

    // Task 4.1-4.4: Render payment chain UI with multi-line structure
    function renderPaymentChain() {
        if (!stagedPaymentChainList) return;

        stagedPaymentChainList.innerHTML = '';

        if (!paymentChain || paymentChain.payments.length === 0) {
            stagedPaymentChainList.innerHTML = '<p class="text-muted small">Belum ada pembayaran</p>';
            return;
        }

        const list = document.createElement('div');
        paymentChain.payments.forEach((payment, index) => {
            const item = document.createElement('div');
            item.className = 'badge badge-success mr-2 mb-2 p-3';
            item.style.whiteSpace = 'normal';
            item.style.display = 'inline-block';
            item.style.minWidth = '150px';

            // Task 4.2 & 4.3: Multi-line structure with formatting
            let content = `<div style="text-align: left;">`;
            content += `<div style="font-weight: bold; margin-bottom: 0.25rem;">✓ ${escapeHtml(payment.method_name)}</div>`;
            content += `<div style="font-size: 0.9rem; margin-bottom: 0.25rem;">${formatPrice(payment.amount)}</div>`;
            if (payment.edc_reference) {
                content += `<div style="font-size: 0.75rem; opacity: 0.8;">Ref: ${escapeHtml(payment.edc_reference)}</div>`;
            }
            content += `</div>`;

            item.innerHTML = content;
            list.appendChild(item);
        });

        stagedPaymentChainList.appendChild(list);
    }

    // Task 3.5 & 3.6: Show/hide EDC reference input and validate
    function updateEdcReferenceVisibility() {
        if (!stagedEdcReferenceContainer) return;

        if (selectedPaymentMethod && !selectedPaymentMethod.is_cash && selectedPaymentMethod.requires_reference) {
            stagedEdcReferenceContainer.style.display = 'block';
            if (stagedEdcReferenceInput) {
                stagedEdcReferenceInput.focus();
                stagedEdcReferenceInput.value = '';
            }
        } else {
            stagedEdcReferenceContainer.style.display = 'none';
        }
    }

    // Task 3.6: Real-time EDC reference validation - only check "not empty"
    function validateEdcReferenceRealtime(event) {
        const reference = event.target.value.trim();

        if (!reference) {
            stagedEdcReferenceInput.classList.add('is-invalid');
        } else {
            stagedEdcReferenceInput.classList.remove('is-invalid');
        }
    }

    // Task 3.2: Payment method search
    function handleMethodSearch(event) {
        const query = (event.target.value || '').trim().toLowerCase();

        if (query.length === 0) {
            renderPaymentMethods(cachedPaymentMethods);
            return;
        }

        const filtered = cachedPaymentMethods.filter(method =>
            (method.name || '').toLowerCase().includes(query)
        );
        renderPaymentMethods(filtered);
    }

    // Task 3.2: Show payment methods on focus
    function handleMethodFocus() {
        renderPaymentMethods(cachedPaymentMethods);
    }

    // Render payment method options
    function renderPaymentMethods(methods) {
        if (!stagedMethodResultsDropdown) return;

        stagedMethodResultsDropdown.innerHTML = '';
        if (methods.length === 0) {
            stagedMethodResultsDropdown.style.display = 'none';
            return;
        }

        stagedMethodResultsDropdown.style.display = 'block';
        methods.forEach(method => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action';
            item.textContent = method.name;
            item.dataset.methodId = method.id;
            // Ensure dropdown items have opaque white background in modal context
            item.style.backgroundColor = '#fff !important';
            item.style.color = '#212529 !important';
            item.addEventListener('click', (e) => {
                e.preventDefault();
                selectPaymentMethod(method);
            });
            stagedMethodResultsDropdown.appendChild(item);
        });
    }

    // Task 3.2: Handle method selection
    function handleMethodSelect(event) {
        event.preventDefault();
        event.stopPropagation();
    }

    // Select payment method
    function selectPaymentMethod(method) {
        selectedPaymentMethod = method;
        if (stagedMethodSearchInput) {
            stagedMethodSearchInput.value = method.name;
        }
        if (stagedMethodResultsDropdown) {
            stagedMethodResultsDropdown.style.display = 'none';
        }

        updateEdcReferenceVisibility();
        updateAmountHint();
        if (stagedAmountInput) {
            stagedAmountInput.focus();
            stagedAmountInput.value = '';
        }
        updateStageValidation();
    }

    // Task 2.1 & 2.3: Update amount hint based on selected method and remainder
    function updateAmountHint() {
        if (!stagedAmountHint) return;

        if (!selectedPaymentMethod || !paymentChain) {
            stagedAmountHint.style.display = 'none';
            return;
        }

        const remainder = Math.max(paymentChain.remainder, 0);

        if (selectedPaymentMethod.is_cash) {
            stagedAmountHint.textContent = `Minimal: ${formatPrice(remainder)}`;
            stagedAmountHint.className = 'form-text mt-1 font-weight-bold text-primary';
            stagedAmountHint.style.display = 'block';
        } else {
            stagedAmountHint.textContent = `Maksimal: ${formatPrice(remainder)}`;
            stagedAmountHint.className = 'form-text mt-1 font-weight-bold text-info';
            stagedAmountHint.style.display = 'block';
        }
    }

    // Task 4.2: Calculate and update remainder display
    function updateRemainderDisplay() {
        if (!stagedRemainderLabel || !paymentChain) return;

        const remainder = Math.max(paymentChain.remainder, 0);
        stagedRemainderLabel.textContent = formatPrice(remainder);

        // Color coding
        stagedRemainderLabel.classList.remove('text-danger', 'text-success', 'text-warning');
        if (remainder > 0) {
            stagedRemainderLabel.classList.add('text-danger');
        } else if (remainder === 0) {
            stagedRemainderLabel.classList.add('text-success');
        }

        updateAmountHint();
    }

    // Task 4.1 & 4.2: Validate current stage
    function updateStageValidation() {
        let isValid = true;
        const remainder = paymentChain?.remainder || 0;

        // If remainder is 0, allow finalization without additional payment entry
        if (remainder === 0) {
            // Payment is complete, button should be enabled to finalize
            isValid = true;
        } else {
            // Need to add more payments
            if (!selectedPaymentMethod) {
                isValid = false;
            }

            const amount = Number(stagedAmountInput?.dataset.rawValue || 0);
            if (amount <= 0) {
                isValid = false;
            }

            // Task 2.4: Call validation function to check method-specific amount rules
            if (isValid && selectedPaymentMethod && paymentChain) {
                if (!validateAmountForMethod(amount, paymentChain.remainder, selectedPaymentMethod)) {
                    isValid = false;
                }
            }

            if (selectedPaymentMethod && !selectedPaymentMethod.is_cash && selectedPaymentMethod.requires_reference) {
                const reference = stagedEdcReferenceInput?.value.trim() || '';
                if (!reference) {
                    isValid = false;
                }
            }
        }

        if (stagedSubmitButton) {
            stagedSubmitButton.disabled = !isValid;
        }
    }

    // Task 4.1 & 4.3: Submit stage payment
    async function submitStagePayment(event) {
        event.preventDefault();

        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        const remainder = paymentChain?.remainder || 0;

        // If remainder is 0, finalize checkout instead of adding another payment
        if (remainder === 0) {
            await finalizeCheckout();
            return;
        }

        if (!validateBeforeSubmit()) return;

        setProcessing(true);
        clearErrors();

        try {
            // Task 2.5: Use raw value from dataset instead of formatted input.value
            const amount = Number(stagedAmountInput.dataset.rawValue || stagedAmountInput.value);
            // Task 1.2: Send original grand total instead of (remainder + amount)
            const payload = {
                cart_token: paymentChain.cart_token,
                payment_method_id: selectedPaymentMethod.id,
                amount: amount,
                edc_reference: stagedEdcReferenceInput?.value.trim() || null,
                grand_total: paymentChain.original_grand_total,  // Send original, not (remainder + amount)
            };

            console.log('[PosStagedPayment] Submitting stage payment:', payload);

            const response = await fetch('/pos/sell/checkout/stage-payment', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            console.log('[PosStagedPayment] Stage payment response:', { status: response.status, ok: response.ok, data });

            if (!response.ok) {
                showError(data.message || 'Gagal memproses pembayaran');
                return;
            }

            // Update payment chain with new payment
            paymentChain.payments = data.payment_chain.payments || [];
            paymentChain.remainder = data.remainder || 0;

            console.log('[PosStagedPayment] Updated chain:', { remainder: paymentChain.remainder, payments: paymentChain.payments.length });

            // Check remainder and proceed
            if (data.remainder > 0) {
                // More payments needed
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
            } else {
                // Payment complete or overpaid (data.remainder <= 0), proceed to authoritative finalization
                console.log('[PosStagedPayment] Payment complete/overpaid, initiating checkout finalization', { remainder: data.remainder });
                await finalizeCheckout();
            }
        } catch (error) {
            console.error('[PosStagedPayment] Error:', error);
            showError('Terjadi kesalahan: ' + error.message);
        } finally {
            setProcessing(false);
        }
    }

    // Task 2.1: Create validateAmountForMethod() function that checks is_cash flag
    function validateAmountForMethod(amount, remainder, method) {
        if (!method) return false;

        // Task 2.2: Cash validation rule - amount >= remainder (allow overpayment)
        if (method.is_cash) {
            return amount >= remainder;
        }

        // Task 2.3: Non-cash validation rule - amount <= remainder (no overpayment)
        return amount <= remainder;
    }

    // Task 4.4: Validate before payment submission
    function validateBeforeSubmit() {
        // Task 2.5: Use raw value from dataset
        const amount = Number(stagedAmountInput?.dataset.rawValue || stagedAmountInput?.value || 0);
        const remainder = paymentChain?.remainder || 0;

        if (amount <= 0) {
            showError('Jumlah pembayaran harus lebih dari 0');
            return false;
        }

        // Task 2.4 & 2.5: Call validation function with method-specific error messages
        if (selectedPaymentMethod) {
            if (!validateAmountForMethod(amount, remainder, selectedPaymentMethod)) {
                if (selectedPaymentMethod.is_cash) {
                    showError(`Jumlah pembayaran tunai harus minimal ${formatPrice(remainder)} untuk melunasi`);
                } else {
                    showError(`Jumlah pembayaran tidak boleh lebih dari sisa ${formatPrice(remainder)}`);
                }
                return false;
            }
        }

        if (selectedPaymentMethod && !selectedPaymentMethod.is_cash && selectedPaymentMethod.requires_reference) {
            const reference = stagedEdcReferenceInput?.value.trim() || '';
            if (!reference) {
                showError('Nomor referensi EDC wajib diisi');
                // Task 3.3: Ensure EDC reference field gets focus when error occurs
                if (stagedEdcReferenceInput) stagedEdcReferenceInput.focus();
                return false;
            }
        }

        return true;
    }

    // Reset form for next stage
    function resetStageForm() {
        selectedPaymentMethod = null;
        if (stagedMethodSearchInput) stagedMethodSearchInput.value = '';
        if (stagedAmountInput) {
            stagedAmountInput.value = '';
            stagedAmountInput.dataset.rawValue = '';
        }
        if (stagedEdcReferenceInput) stagedEdcReferenceInput.value = '';
        updateEdcReferenceVisibility();
        updateAmountHint();
        updateStageValidation();
        if (stagedMethodSearchInput) stagedMethodSearchInput.focus();
    }

    // Task 3.4: Handle modal lock during processing
    function setProcessing(isProcessing) {
        state = isProcessing ? States.PROCESSING : States.SELECTING_METHOD;

        if (stagedProcessingSpinner) {
            stagedProcessingSpinner.style.display = isProcessing ? 'block' : 'none';
        }

        if (stagedMethodSearchInput) stagedMethodSearchInput.disabled = isProcessing;
        if (stagedAmountInput) stagedAmountInput.disabled = isProcessing;
        if (stagedEdcReferenceInput) stagedEdcReferenceInput.disabled = isProcessing;
        if (stagedSubmitButton) stagedSubmitButton.disabled = isProcessing;

        // Disable close button during processing
        const closeButton = stagedModalElement?.querySelector('[data-dismiss="modal"]');
        if (closeButton) closeButton.style.display = isProcessing ? 'none' : 'block';
    }

    // Finalize checkout when all payments are received
    async function finalizeCheckout() {
        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        if (!paymentChain) {
            showError('Payment chain is missing');
            return;
        }

        setProcessing(true);
        clearErrors();

        try {
            const payload = {
                cart_token: paymentChain.cart_token,
                idempotency_key: `FINALIZE-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
            };

            console.log('[PosStagedPayment] Finalizing checkout:', payload);

            const response = await fetch('/pos/sell/checkout/finalize', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            console.log('[PosStagedPayment] Finalize response:', { status: response.status, ok: response.ok, data });

            if (!response.ok) {
                showError(data.message || 'Gagal menyelesaikan pembayaran');
                return;
            }

            // Get change amount from response
            const changeAmount = Math.abs(data.change_total || 0);
            console.log('[PosStagedPayment] Checkout finalized! Change:', changeAmount);

            handlePaymentComplete(changeAmount);
        } catch (error) {
            console.error('[PosStagedPayment] Finalize error:', error);
            showError('Terjadi kesalahan saat menyelesaikan pembayaran: ' + error.message);
        } finally {
            setProcessing(false);
        }
    }

    // Task 6.5: Handle payment completion
    function handlePaymentComplete(changeAmount) {
        state = States.COMPLETE;
        console.log('[PosStagedPayment] Payment complete, change:', changeAmount);

        if (stagedModalElement) {
            $(stagedModalElement).modal('hide');
        }

        // Show gratitude modal with change amount
        showGratitudeModal(changeAmount);

        // Invoke the completion callback if registered
        if (onCompleteCallback) {
            console.log('[PosStagedPayment] Calling onCompleteCallback');
            onCompleteCallback(changeAmount);
        }
    }

    // Public API: set callback to be invoked when all payment stages are complete
    function setOnComplete(callback) {
        console.log('[PosStagedPayment] setOnComplete callback registered');
        onCompleteCallback = callback;
    }

    // Task 6.4 & 6.5: Show gratitude modal
    function showGratitudeModal(changeAmount) {
        const modal = document.getElementById('pos-gratitude-modal');
        console.log('[PosStagedPayment] Showing gratitude modal:', !!modal, 'changeAmount:', changeAmount);
        if (!modal) return;

        const changeLabel = modal.querySelector('#gratitude-change-amount');
        if (changeLabel) {
            if (changeAmount > 0) {
                changeLabel.textContent = `Total Kembalian: ${formatPrice(changeAmount)}`;
            } else {
                changeLabel.textContent = '';
            }
        }

        console.log('[PosStagedPayment] Calling modal.show()');
        $(modal).modal('show');
    }

    // Task 6.3: Print receipt in new tab
    function printReceipt(checkoutId) {
        if (checkoutId) {
            const url = `/pos/sell/checkout/${checkoutId}/receipt`;
            window.open(url, '_blank');
        }
    }

    // Load payment methods from API
    async function loadPaymentMethods() {
        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        try {
            // This should fetch available payment methods for the current setting
            // Assuming there's an endpoint or a cached list
            if (typeof window.POS_PAYMENT_METHODS !== 'undefined') {
                cachedPaymentMethods = window.POS_PAYMENT_METHODS;
                return;
            }

            // Fallback: try to load from API
            const response = await fetch('/pos/sell/payment-methods/search', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
            });

            if (response.ok) {
                const data = await response.json();
                cachedPaymentMethods = data.methods || [];
                console.log('[PosStagedPayment] Loaded payment methods:', cachedPaymentMethods);
            } else {
                console.error('[PosStagedPayment] Failed to load payment methods:', response.status);
            }
        } catch (error) {
            console.error('[PosStagedPayment] Failed to load payment methods:', error);
        }
    }

    // Helper: Show error message
    function showError(message) {
        if (stagedErrorAlert) {
            stagedErrorAlert.textContent = message;
            stagedErrorAlert.classList.remove('d-none');
        }
    }

    function ensurePaymentFlowAvailable() {
        if (canUsePaymentFlow) {
            return true;
        }

        showError(paymentFlowBlockedMessage);

        return false;
    }

    // Helper: Clear errors
    function clearErrors() {
        if (stagedErrorAlert) {
            stagedErrorAlert.classList.add('d-none');
            stagedErrorAlert.textContent = '';
        }
    }

    // Helper: Generate idempotency key
    function generateIdempotencyKey() {
        return `STAGE-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    }

    // Helper: Format price
    function formatPrice(amount) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
        }).format(Number(amount) || 0);
    }

    // Helper: Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Task 2.1: Format number for display with Indonesian thousand separators
    function formatNumberForDisplay(num) {
        const parsed = Number(num) || 0;
        return new Intl.NumberFormat('id-ID').format(parsed);
    }

    // Task 2.2 & 2.3: Setup amount input formatter with real-time formatting
    // This formatter maintains numeric accuracy by storing raw values separately from display
    // The !important override in input styling ensures white background visibility in the modal
    function setupAmountInputFormatter() {
        if (!stagedAmountInput) return;

        stagedAmountInput.addEventListener('input', function (e) {
            // Strip non-digits from input to get raw numeric value
            const rawValue = this.value.replace(/\D/g, '');

            // Store raw numeric value in dataset attribute for form submission
            // This ensures the backend receives accurate numeric values (no formatted strings)
            this.dataset.rawValue = rawValue;

            // Display formatted value with thousand separators (Indonesian locale)
            // User sees: 150000 → 150.000 but backend receives: 150000
            if (rawValue) {
                this.value = formatNumberForDisplay(rawValue);
            } else {
                this.value = '';
            }

            // Trigger validation after formatting to update button state
            updateStageValidation();
        });
    }

    // Task 3.4 & 3.5 & 3.6: Setup quick-add buttons
    function setupQuickAddButtons() {
        const quickAddButtons = document.querySelectorAll('.js-quick-add');
        const quickAddRemainderBtn = document.querySelector('.js-quick-add-remainder');

        // Task 3.5: Handle increment buttons
        quickAddButtons.forEach(button => {
            button.addEventListener('click', function () {
                const amount = Number(this.dataset.amount) || 0;
                const currentRaw = Number(stagedAmountInput.dataset.rawValue || 0);
                const newRaw = currentRaw + amount;

                // Update raw value and display formatted
                stagedAmountInput.dataset.rawValue = newRaw;
                stagedAmountInput.value = formatNumberForDisplay(newRaw);

                // Trigger validation
                updateStageValidation();
            });
        });

        // Task 3.6: Handle remainder button
        if (quickAddRemainderBtn) {
            quickAddRemainderBtn.addEventListener('click', function () {
                if (!paymentChain) return;

                const remainder = Number(paymentChain.remainder) || 0;

                // Fill with exact remainder
                stagedAmountInput.dataset.rawValue = remainder;
                stagedAmountInput.value = formatNumberForDisplay(remainder);

                // Trigger validation
                updateStageValidation();
            });
        }
    }

    // Public API
    return {
        initialize,
        openModal,
        setOnComplete,
        loadPaymentMethods,
        printReceipt,
        setPaymentMethods: function (methods) {
            cachedPaymentMethods = methods || [];
        },
    };
})();
