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
    let currentHasCustomer = false;

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

    // Confirmation Modal DOM elements
    let confirmModalElement = null;
    let confirmMethodLabel = null;
    let confirmRemainingLabel = null;
    let confirmEnteredLabel = null;
    let confirmAlertContainer = null;
    let confirmProceedButton = null;

    // Final Confirmation Modal DOM elements
    let finalModalElement = null;
    let finalGrandTotalLabel = null;
    let finalPaidAmountLabel = null;
    let finalChangeContainer = null;
    let finalChangeLabel = null;
    let finalDebtContainer = null;
    let finalDebtLabel = null;
    let finalDebtDetailsContainer = null;
    let finalDebtCustomerLabel = null;
    let finalDebtTermLabel = null;
    let finalAlertContainer = null;
    let finalProceedButton = null;
    let finalCancelButton = null;

    // Finalization Idempotency
    let currentFinalizeIdempotencyKey = null;

    // Debt variables
    let stagedIsDebtToggle = null;
    let stagedDebtTermsContainer = null;
    let stagedPaymentTermSelect = null;
    let stagedMethodContainer = null;
    let cachedPaymentTerms = [];

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
        
        confirmModalElement = document.getElementById('pos-payment-confirmation-modal');
        confirmMethodLabel = document.getElementById('confirm-payment-method');
        confirmRemainingLabel = document.getElementById('confirm-remaining-balance');
        confirmEnteredLabel = document.getElementById('confirm-entered-amount');
        confirmAlertContainer = document.getElementById('confirm-payment-alert');
        confirmProceedButton = document.getElementById('confirm-payment-proceed-btn');

        finalModalElement = document.getElementById('pos-final-confirmation-modal');
        finalGrandTotalLabel = document.getElementById('final-grand-total');
        finalPaidAmountLabel = document.getElementById('final-paid-amount');
        finalChangeContainer = document.getElementById('final-change-container');
        finalChangeLabel = document.getElementById('final-change-amount');
        finalDebtContainer = document.getElementById('final-debt-container');
        finalDebtLabel = document.getElementById('final-debt-amount');
        finalDebtDetailsContainer = document.getElementById('final-debt-details-container');
        finalDebtCustomerLabel = document.getElementById('final-debt-customer');
        finalDebtTermLabel = document.getElementById('final-debt-term');
        finalAlertContainer = document.getElementById('final-payment-alert');
        finalProceedButton = document.getElementById('final-payment-proceed-btn');
        finalCancelButton = document.getElementById('final-payment-cancel-btn');

        stagedIsDebtToggle = document.getElementById('staged-payment-is-debt');
        stagedDebtTermsContainer = document.getElementById('staged-payment-debt-terms-container');
        stagedPaymentTermSelect = document.getElementById('staged-payment-term');
        stagedMethodContainer = document.getElementById('staged-method-container');

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
            stagedSubmitButton.addEventListener('click', confirmStagePayment);
        }

        if (confirmProceedButton) {
            confirmProceedButton.addEventListener('click', executeStagePayment);
        }

        if (finalProceedButton) {
            finalProceedButton.addEventListener('click', finalizeCheckout);
        }

        if (finalCancelButton) {
            finalCancelButton.addEventListener('click', finalCancelCheckout);
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

        if (stagedIsDebtToggle) {
            stagedIsDebtToggle.addEventListener('change', () => {
                handleDebtToggle();
                syncDebtState();
            });
        }
        if (stagedPaymentTermSelect) {
            stagedPaymentTermSelect.addEventListener('change', () => {
                updateStageValidation();
                syncDebtState();
            });
        }
    }

    function handleDebtToggle() {
        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
        if (isDebt) {
            if (!currentHasCustomer) {
                showError('Pilih pelanggan terlebih dahulu untuk menggunakan fitur Utang/Kasbon.');
                stagedIsDebtToggle.checked = false;
                // Re-evaluate without debt
                const recursiveDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
                if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.add('d-none');
                updateStageValidation();
                return;
            }
            if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.remove('d-none');
            if (cachedPaymentTerms.length === 0) loadPaymentTerms();
        } else {
            if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.add('d-none');
        }
        updateStageValidation();
    }

    async function syncDebtState() {
        if (!currentCartToken || !paymentChain || paymentChain.remainder === undefined) return;

        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
        const termId = stagedPaymentTermSelect ? stagedPaymentTermSelect.value : null;

        try {
            await fetch('/pos/sell/checkout/sync-debt-state', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cart_token: currentCartToken,
                    grand_total: paymentChain.original_grand_total || paymentChain.remainder,
                    is_debt: isDebt,
                    payment_term_id: termId
                })
            });
        } catch (e) {
            console.error('[PosStagedPayment] Error syncing debt state:', e);
        }
    }

    // Task 5.1 & 5.2: Open modal with cart token and grand total
    async function openModal(cartToken, grandTotal, hasCustomer = true, customerName = '-') {
        console.log('[PosStagedPayment] openModal called with:', { cartToken, grandTotal, hasCustomer, customerName });

        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        if (!cartToken || grandTotal === null || grandTotal === undefined) {
            console.warn('[PosStagedPayment] Invalid parameters, returning');
            return;
        }

        currentHasCustomer = hasCustomer;
        currentCustomerName = customerName;
        state = States.SELECTING_METHOD;
        clearErrors();
        
        // Only reset checkout context if it's a completely new checkout (different token)
        if (currentCartToken !== cartToken) {
            resetCheckoutContext();
        }
        
        resetStageForm();

        currentFinalizeIdempotencyKey = null;

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

            // Recover debt state
            if (data.payment_chain.is_debt) {
                if (stagedIsDebtToggle) stagedIsDebtToggle.checked = true;
                if (stagedPaymentTermSelect && data.payment_chain.payment_term_id) {
                    stagedPaymentTermSelect.value = data.payment_chain.payment_term_id;
                }
                handleDebtToggle();
            }

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
        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;

        // If remainder is 0, allow finalization without additional payment entry
        if (remainder === 0) {
            // Payment is complete, button should be enabled to finalize
            isValid = true;
        } else {
            const amount = Number(stagedAmountInput?.dataset.rawValue || 0);
            
            if (isDebt) {
                if (!stagedPaymentTermSelect || !stagedPaymentTermSelect.value) {
                    isValid = false;
                }
                
                if (amount > 0) {
                    if (!selectedPaymentMethod) {
                        isValid = false;
                    }
                    if (isValid && selectedPaymentMethod && paymentChain) {
                        if (!validateAmountForMethod(amount, paymentChain.remainder, selectedPaymentMethod, isDebt)) {
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
            } else {
                // Need to add more payments
                if (!selectedPaymentMethod) {
                    isValid = false;
                }

                if (amount <= 0) {
                    isValid = false;
                }

                // Task 2.4: Call validation function to check method-specific amount rules
                if (isValid && selectedPaymentMethod && paymentChain) {
                    if (!validateAmountForMethod(amount, paymentChain.remainder, selectedPaymentMethod, isDebt)) {
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
        }

        if (stagedSubmitButton) {
            stagedSubmitButton.disabled = !isValid;
        }
    }

    // Task 2.1 & 4.1 & 4.3: Confirm stage payment before submit
    async function confirmStagePayment(event) {
        event.preventDefault();

        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        const remainder = paymentChain?.remainder || 0;
        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
        const amount = Number(stagedAmountInput.dataset.rawValue || stagedAmountInput.value || 0);

        // If remainder is 0, finalize checkout instead of adding another payment
        if (remainder === 0) {
            showFinalConfirmation();
            return;
        }

        if (!validateBeforeSubmit()) return;

        if (isDebt && amount === 0) {
            showFinalConfirmation();
            return;
        }

        // Populate Confirmation Modal (Task 2.2)
        if (confirmMethodLabel) confirmMethodLabel.textContent = selectedPaymentMethod?.name || '-';
        if (confirmRemainingLabel) confirmRemainingLabel.textContent = formatPrice(remainder);
        if (confirmEnteredLabel) confirmEnteredLabel.textContent = formatPrice(amount);

        // Warning Logic and Alerts (Task 3.1 & 3.2)
        if (confirmAlertContainer) {
            confirmAlertContainer.className = 'alert'; // reset classes
            
            if (amount === remainder) {
                confirmAlertContainer.classList.add('alert-success');
                confirmAlertContainer.textContent = 'Pembayaran Pas';
            } else if (amount > remainder) {
                const change = amount - remainder;
                confirmAlertContainer.classList.add('alert-info');
                confirmAlertContainer.textContent = `Kembalian: ${formatPrice(change)}`;
            } else {
                const short = remainder - amount;
                confirmAlertContainer.classList.add('alert-warning');
                confirmAlertContainer.textContent = `Pembayaran Kurang (Sisa: ${formatPrice(short)})`;
            }
            confirmAlertContainer.classList.remove('d-none');
        }

        // Show Confirmation Modal (Task 3.3)
        if (confirmModalElement) {
            $(confirmModalElement).modal('show');
        }
    }

    // Task 4.2: Execute stage payment (previously submitStagePayment)
    async function executeStagePayment(event) {
        if (event) event.preventDefault();
        
        if (confirmModalElement) {
            $(confirmModalElement).modal('hide');
        }

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

            if (stagedIsDebtToggle && stagedIsDebtToggle.checked) {
                payload.is_debt = true;
                payload.payment_term_id = stagedPaymentTermSelect ? stagedPaymentTermSelect.value : null;
            }

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
            if (data.remainder > 0 && !payload.is_debt) {
                // More payments needed
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
            } else {
                // Payment complete or overpaid (data.remainder <= 0), or it's a debt down payment, proceed to authoritative finalization
                console.log('[PosStagedPayment] Payment complete/overpaid or debt down payment, initiating checkout finalization', { remainder: data.remainder, is_debt: payload.is_debt });
                showFinalConfirmation();
            }
        } catch (error) {
            console.error('[PosStagedPayment] Error:', error);
            showError('Terjadi kesalahan: ' + error.message);
        } finally {
            setProcessing(false);
        }
    }

    // Task 2.1: Create validateAmountForMethod() function that checks is_cash flag
    function validateAmountForMethod(amount, remainder, method, isDebt) {
        if (!method) return false;

        // Debt validation rule - amount must be strictly less than remainder
        if (isDebt) {
            return amount < remainder;
        }

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
        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;

        if (!isDebt && amount <= 0) {
            showError('Jumlah pembayaran harus lebih dari 0');
            return false;
        }

        if (isDebt && amount < 0) {
            showError('Jumlah pembayaran tidak boleh negatif');
            return false;
        }

        // Task 2.4 & 2.5: Call validation function with method-specific error messages
        if (selectedPaymentMethod && amount > 0) {
            if (!validateAmountForMethod(amount, remainder, selectedPaymentMethod, isDebt)) {
                if (isDebt) {
                    showError(`Pembayaran utang/kasbon harus kurang dari sisa tagihan ${formatPrice(remainder)}`);
                } else if (selectedPaymentMethod.is_cash) {
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

    // Reset form for next stage (payment inputs only)
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

    // Reset full checkout context (debt state, etc)
    function resetCheckoutContext() {
        if (stagedIsDebtToggle) stagedIsDebtToggle.checked = false;
        if (stagedPaymentTermSelect) stagedPaymentTermSelect.value = '';
        handleDebtToggle();
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

    // Show final transaction confirmation before completing (Task 2.1 & 2.2)
    function showFinalConfirmation() {
        if (!paymentChain) return;

        if (stagedModalElement) {
            $(stagedModalElement).modal('hide');
        }

        // Generate one canonical idempotency key for this finalization attempt (Task 2.4)
        if (!currentFinalizeIdempotencyKey) {
            currentFinalizeIdempotencyKey = `FINALIZE-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        }

        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
        const totalPaid = paymentChain.original_grand_total - paymentChain.remainder;

        if (finalGrandTotalLabel) finalGrandTotalLabel.textContent = formatPrice(paymentChain.original_grand_total);
        if (finalPaidAmountLabel) finalPaidAmountLabel.textContent = formatPrice(totalPaid);

        if (isDebt) {
            if (finalChangeContainer) finalChangeContainer.classList.add('d-none');
            if (finalDebtContainer) finalDebtContainer.classList.remove('d-none');
            if (finalDebtDetailsContainer) finalDebtDetailsContainer.classList.remove('d-none');
            
            if (finalDebtLabel) finalDebtLabel.textContent = formatPrice(paymentChain.remainder);
            
            // Get selected term text
            let termName = '-';
            if (stagedPaymentTermSelect && stagedPaymentTermSelect.options.length > 0) {
                const selectedOpt = stagedPaymentTermSelect.options[stagedPaymentTermSelect.selectedIndex];
                if (selectedOpt && selectedOpt.value) {
                    termName = selectedOpt.textContent;
                }
            }
            if (finalDebtTermLabel) finalDebtTermLabel.textContent = termName;

            // Customer name from parameter
            if (finalDebtCustomerLabel) finalDebtCustomerLabel.textContent = currentCustomerName || '-';
        } else {
            if (finalChangeContainer) finalChangeContainer.classList.remove('d-none');
            if (finalDebtContainer) finalDebtContainer.classList.add('d-none');
            if (finalDebtDetailsContainer) finalDebtDetailsContainer.classList.add('d-none');
            
            const change = totalPaid - paymentChain.original_grand_total;
            if (finalChangeLabel) finalChangeLabel.textContent = formatPrice(Math.max(0, change));
        }

        if (finalModalElement) {
            $(finalModalElement).modal('show');
        }
    }

    // Task 2.3: Handle cancel on final confirmation
    function finalCancelCheckout() {
        if (finalModalElement) {
            $(finalModalElement).modal('hide');
        }
        // Restore focus to staged checkout surface without clearing debt/payments
        if (stagedModalElement) {
            $(stagedModalElement).modal('show');
        }
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
        if (finalProceedButton) finalProceedButton.disabled = true;
        clearErrors();

        try {
            const payload = {
                cart_token: paymentChain.cart_token,
                idempotency_key: currentFinalizeIdempotencyKey,
            };

            if (stagedIsDebtToggle && stagedIsDebtToggle.checked) {
                payload.is_debt = true;
                payload.payment_term_id = stagedPaymentTermSelect ? stagedPaymentTermSelect.value : null;
            }

            console.log('[PosStagedPayment] Finalizing checkout:', payload);

            const performFinalize = async (token) => {
                if (token) {
                    payload.approval_token = token;
                }
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
                    if (data.code === 'APPROVAL_REQUIRED' || (data.message && data.message.includes('otorisasi'))) {
                        throw new Error('APPROVAL_REQUIRED');
                    }
                    throw new Error(data.message || 'Gagal menyelesaikan pembayaran');
                }

                const changeAmount = Math.abs(data.change_total || 0);
                console.log('[PosStagedPayment] Checkout finalized! Change:', changeAmount);
                handlePaymentComplete(changeAmount, data);
            };

            if (payload.is_debt && window.ApprovalManager) {
                // Task 2.5: Integrate ApprovalManager with the final confirmation modal
                const btn = finalProceedButton || stagedSubmitButton;
                const originalText = btn.innerHTML;
                const targetId = (typeof window.posSessionId !== 'undefined') ? window.posSessionId : 0;
                
                const success = await window.ApprovalManager.wrapAction(
                    btn,
                    originalText,
                    'CHECKOUT_AS_DEBT',
                    'pos_session',
                    targetId,
                    payload,
                    performFinalize
                );
                
                if (!success) {
                    setProcessing(false);
                    return; // Stopped by approval flow, modal stays open for token retry
                }
            } else {
                await performFinalize(null);
            }
        } catch (error) {
            console.error('[PosStagedPayment] Finalize error:', error);
            if (finalAlertContainer) {
                finalAlertContainer.className = 'alert alert-danger mt-3';
                finalAlertContainer.textContent = 'Terjadi kesalahan saat menyelesaikan pembayaran: ' + error.message;
                finalAlertContainer.classList.remove('d-none');
            } else {
                showError('Terjadi kesalahan saat menyelesaikan pembayaran: ' + error.message);
            }
        } finally {
            setProcessing(false);
            if (finalProceedButton) finalProceedButton.disabled = false;
        }
    }

    // Task 6.5: Handle payment completion
    function handlePaymentComplete(changeAmount, finalizedCheckout = {}) {
        state = States.COMPLETE;
        console.log('[PosStagedPayment] Payment complete, change:', changeAmount);

        if (stagedModalElement) {
            $(stagedModalElement).modal('hide');
        }
        if (finalModalElement) {
            $(finalModalElement).modal('hide');
        }
        
        currentFinalizeIdempotencyKey = null; // Clear key on success

        resetCheckoutContext();

        // Show gratitude modal with change amount
        showGratitudeModal(changeAmount);

        // Invoke the completion callback if registered
        if (onCompleteCallback) {
            console.log('[PosStagedPayment] Calling onCompleteCallback');
            onCompleteCallback(changeAmount, finalizedCheckout);
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

    async function loadPaymentTerms() {
        try {
            const response = await fetch('/pos/sell/payment-terms/search', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });
            if (response.ok) {
                const data = await response.json();
                cachedPaymentTerms = data.terms || [];
                
                if (stagedPaymentTermSelect) {
                    stagedPaymentTermSelect.innerHTML = '<option value="">-- Pilih Jangka Waktu --</option>';
                    cachedPaymentTerms.forEach(term => {
                        const opt = document.createElement('option');
                        opt.value = term.id;
                        opt.textContent = `${term.name} (${term.longevity} Hari)`;
                        stagedPaymentTermSelect.appendChild(opt);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load payment terms:', error);
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
