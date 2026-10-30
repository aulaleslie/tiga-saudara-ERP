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

    // Image Upload DOM elements
    let stagedPaymentImageContainer = null;
    let stagedPaymentImageUploadState = null;
    let stagedPaymentImageFile = null;
    let stagedPaymentImageError = null;
    let stagedPaymentImageUploadingState = null;
    let stagedPaymentImageSuccessState = null;
    let stagedPaymentImageFilename = null;
    let stagedPaymentImageRemoveBtn = null;
    
    // Image Upload State
    let currentImageToken = null;
    let currentImageName = null;
    let isImageUploading = false;
    let imageUploadAbortController = null;

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

    // Lifecycle Warning Acknowledgement State
    let isLifecycleAcknowledged = false;

    // Checkout Context State
    let currentCartToken = null;
    let currentCustomerName = null;

    // Flow identity and generation tracking
    let currentFlow = 'full_payment'; // 'full_payment' | 'kasbon'
    let activeGeneration = 0;
    let pendingTargetFlow = null;
    let pendingTargetGrandTotal = null;

    // Flow controls DOM elements
    let stagedFlowBadge = null;
    let stagedSwitchFlowBtn = null;
    let stagedSwitchFlowText = null;
    let stagedRemainderTitle = null;
    let stagedAmountLabel = null;
    let stagedFinishKasbonBtn = null;

    // Flow Switch Confirmation Modal DOM elements
    let flowSwitchModalElement = null;
    let flowSwitchMessageElement = null;
    let flowSwitchConfirmBtn = null;
    let flowSwitchCancelBtn = null;

    // Debt variables
    let stagedIsDebtToggle = null;
    let stagedDebtTermsContainer = null;
    let stagedPaymentTermSelect = null;
    let stagedMethodContainer = null;
    let stagedPaymentTermRetryBtn = null;
    let cachedPaymentTerms = null;

    // Cached data
    let cachedPaymentMethods = [];
    let selectedPaymentMethod = null;
    let canUsePaymentFlow = true;
    let canUseLunasFlow = true;
    let canUseKasbonFlow = true;
    let canSwitchFlow = true;
    let requiresKasbonApproval = false;
    let defaultFlow = 'full_payment';
    let isFlowLocked = false;
    let flowLockedMessage = null;
    let paymentFlowBlockedMessage = 'Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran.';

    // Reset button DOM element
    let stagedPaymentResetBtn = null;
    let listenersBound = false;

    // Task 3.1: Initialize state machine
    function initialize(config = {}) {
        console.log('[PosStagedPayment] Initializing with config:', config);

        state = States.IDLE;
        paymentChain = null;
        currentStageData = null;
        selectedPaymentMethod = null;
        currentImageToken = null;
        currentImageName = null;

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
        
        stagedPaymentImageContainer = document.getElementById('staged-payment-image-container');
        stagedPaymentImageUploadState = document.getElementById('staged-payment-image-upload-state');
        stagedPaymentImageFile = document.getElementById('staged-payment-image-file');
        stagedPaymentImageError = document.getElementById('staged-payment-image-error');
        stagedPaymentImageUploadingState = document.getElementById('staged-payment-image-uploading-state');
        stagedPaymentImageSuccessState = document.getElementById('staged-payment-image-success-state');
        stagedPaymentImageFilename = document.getElementById('staged-payment-image-filename');
        stagedPaymentImageRemoveBtn = document.getElementById('staged-payment-image-remove-btn');
        
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
        stagedPaymentTermRetryBtn = document.getElementById('staged-payment-term-retry');
        stagedMethodContainer = document.getElementById('staged-method-container');
        stagedPaymentResetBtn = document.getElementById('staged-payment-reset-btn');

        stagedFlowBadge = document.getElementById('staged-flow-badge');
        stagedSwitchFlowBtn = document.getElementById('staged-switch-flow-btn');
        stagedSwitchFlowText = document.getElementById('staged-switch-flow-text');
        stagedRemainderTitle = document.getElementById('staged-remainder-title');
        stagedAmountLabel = document.getElementById('staged-amount-label');
        stagedFinishKasbonBtn = document.getElementById('staged-finish-kasbon-btn');

        flowSwitchModalElement = document.getElementById('pos-flow-switch-modal');
        flowSwitchMessageElement = document.getElementById('pos-flow-switch-message');
        flowSwitchConfirmBtn = document.getElementById('pos-flow-switch-confirm-btn');
        flowSwitchCancelBtn = document.getElementById('pos-flow-switch-cancel-btn');

        canUsePaymentFlow = config.canUsePaymentFlow !== false;
        canUseLunasFlow = config.canUseLunasFlow !== undefined
            ? Boolean(config.canUseLunasFlow)
            : (config.canUseFullPaymentFlow !== undefined ? Boolean(config.canUseFullPaymentFlow) : true);
        canUseKasbonFlow = config.canUseKasbonFlow !== undefined
            ? Boolean(config.canUseKasbonFlow)
            : true;
        canSwitchFlow = config.canSwitchFlow !== undefined
            ? Boolean(config.canSwitchFlow)
            : (canUseLunasFlow && canUseKasbonFlow);
        requiresKasbonApproval = Boolean(config.requiresKasbonApproval);
        defaultFlow = config.defaultFlow
            || (canUseLunasFlow ? 'full_payment' : (canUseKasbonFlow ? 'kasbon' : 'full_payment'));
        currentFlow = defaultFlow;
        isFlowLocked = false;
        flowLockedMessage = null;
        paymentFlowBlockedMessage = config.paymentFlowBlockedMessage || paymentFlowBlockedMessage;

        if (stagedSwitchFlowBtn) {
            if (canSwitchFlow) {
                stagedSwitchFlowBtn.classList.remove('d-none');
                stagedSwitchFlowBtn.disabled = false;
            } else {
                stagedSwitchFlowBtn.classList.add('d-none');
                stagedSwitchFlowBtn.disabled = true;
            }
        }

        console.log('[PosStagedPayment] DOM elements cached:', {
            modalElement: !!stagedModalElement,
            methodSearchInput: !!stagedMethodSearchInput,
            paymentChainList: !!stagedPaymentChainList,
            submitButton: !!stagedSubmitButton,
        });

        if (!listenersBound) {
            setupEventListeners();

            // Task 5.1 & 5.2: Initialize formatters
            setupAmountInputFormatter();
            setupQuickAddButtons();
            listenersBound = true;
        }
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

        if (stagedPaymentImageFile) {
            stagedPaymentImageFile.addEventListener('change', handlePaymentImageUpload);
        }

        if (stagedPaymentImageRemoveBtn) {
            stagedPaymentImageRemoveBtn.addEventListener('click', handlePaymentImageRemove);
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
        if (stagedPaymentTermRetryBtn) {
            stagedPaymentTermRetryBtn.addEventListener('click', loadPaymentTerms);
        }

        if (stagedPaymentResetBtn) {
            stagedPaymentResetBtn.addEventListener('click', handleResetPaymentChain);
        }

        if (stagedSwitchFlowBtn) {
            stagedSwitchFlowBtn.addEventListener('click', () => {
                switchFlow(currentFlow === 'kasbon' ? 'full_payment' : 'kasbon');
            });
        }

        if (stagedFinishKasbonBtn) {
            stagedFinishKasbonBtn.addEventListener('click', handleFinishKasbon);
        }

        if (flowSwitchConfirmBtn) {
            flowSwitchConfirmBtn.addEventListener('click', confirmFlowSwitch);
        }
    }

    async function handleDebtToggle() {
        const isDebt = stagedIsDebtToggle && stagedIsDebtToggle.checked;
        if (isDebt) {
            if (!currentHasCustomer) {
                showError('Pilih pelanggan terlebih dahulu untuk menggunakan fitur Utang/Kasbon.');
                stagedIsDebtToggle.checked = false;
                // Re-evaluate without debt
                if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.add('d-none');
                updateStageValidation();
                return;
            }
            if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.remove('d-none');
            if (cachedPaymentTerms === null) await loadPaymentTerms();
        } else {
            if (stagedDebtTermsContainer) stagedDebtTermsContainer.classList.add('d-none');
        }
        updateStageValidation();
    }

    async function syncDebtState() {
        if (!currentCartToken || !paymentChain || paymentChain.remainder === undefined) return;

        const isDebt = (currentFlow === 'kasbon');
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
                    flow: currentFlow,
                    is_debt: isDebt,
                    payment_term_id: termId
                })
            });
        } catch (e) {
            console.error('[PosStagedPayment] Error syncing debt state:', e);
        }
    }

    // Task 5.1 & 5.2: Open modal with cart token and grand total
    async function openModal(cartToken, grandTotal, hasCustomer = true, customerName = '-', flow = null) {
        const requestedFlow = flow || defaultFlow;
        console.log('[PosStagedPayment] openModal called with:', { cartToken, grandTotal, hasCustomer, customerName, flow: requestedFlow });

        if (!ensurePaymentFlowAvailable()) {
            return;
        }

        if (!cartToken || grandTotal === null || grandTotal === undefined) {
            console.warn('[PosStagedPayment] Invalid parameters, returning');
            return;
        }

        currentHasCustomer = hasCustomer;
        currentCustomerName = customerName;

        // If same cart token already has an active chain
        if (paymentChain && currentCartToken === cartToken) {
            if (isFlowLocked) {
                await updateFlowUI();
                renderPaymentChain();
                updateRemainderDisplay();
                updateAmountHint();
                showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, true));
                if (stagedModalElement) $(stagedModalElement).modal('show');
                return;
            }

            const numericGrandTotal = Number(grandTotal) || 0;
            const previousTotal = Number(paymentChain.original_grand_total) || 0;
            const totalChanged = Math.abs(previousTotal - numericGrandTotal) > 0.001;

            if (flow && flow !== currentFlow) {
                if (!canSwitchFlow || !isFlowAccessible(flow)) {
                    showError(getFlowDeniedMessage(flow));
                    if (stagedModalElement) $(stagedModalElement).modal('show');
                    return;
                }
                // Cashier requested to switch flow (e.g. from Bayar Lunas to Kasbon or vice versa)
                // If dirty (staged payments, entered amount, evidence, edc), switchFlow confirms before resetting
                await switchFlow(flow, totalChanged ? numericGrandTotal : null);
                if (stagedModalElement) $(stagedModalElement).modal('show');
                return;
            }

            // Same flow: check if cart total changed
            if (totalChanged && numericGrandTotal > 0) {
                console.log(`[PosStagedPayment] Cart total changed from ${previousTotal} to ${numericGrandTotal}`);
                // Reconcile with the authoritative server chain
                const serverChain = await reconcileAuthoritativeChain(cartToken, numericGrandTotal, currentFlow);

                if (serverChain) {
                    paymentChain = {
                        cart_token: cartToken,
                        original_grand_total: Number(serverChain.original_grand_total) || numericGrandTotal,
                        remainder: (serverChain.remainder !== undefined && !isNaN(Number(serverChain.remainder))) ? Number(serverChain.remainder) : 0,
                        payments: serverChain.payments || paymentChain.payments || [],
                    };
                } else {
                    const totalStaged = (paymentChain.payments || []).reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                    paymentChain.original_grand_total = numericGrandTotal;
                    paymentChain.remainder = Math.round((numericGrandTotal - totalStaged) * 100) / 100;
                }

                if (!isDirty()) {
                    resetStageForm();
                }
            }

            // Reopening same flow: bypass reset, preserve progress
            state = States.SELECTING_METHOD;
            clearErrors();
            await updateFlowUI();
            renderPaymentChain();
            updateRemainderDisplay();
            updateAmountHint();
            if (stagedModalElement) $(stagedModalElement).modal('show');
            return;
        }

        state = States.SELECTING_METHOD;
        clearErrors();
        
        // Only reset checkout context if it's a completely new checkout (different token)
        if (currentCartToken && currentCartToken !== cartToken) {
            resetCheckoutContext();
        }
        
        currentCartToken = cartToken;
        currentFlow = requestedFlow;
        resetStageForm();

        currentFinalizeIdempotencyKey = null;

        // Try to recover payment chain from session
        const recovered = await checkReloadRecovery(cartToken, grandTotal);
        if (!recovered) {
            if (!isFlowAccessible(requestedFlow)) {
                showError(getFlowDeniedMessage(requestedFlow));
                return;
            }
            isFlowLocked = false;
            flowLockedMessage = null;
            currentFlow = requestedFlow;
            // Start fresh with provided grand total
            initializeNewPaymentChain(cartToken, grandTotal);
            await updateFlowUI();
        } else if (isFlowLocked) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, true));
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

            const numericGrandTotal = Number(grandTotal) || 0;
            const payments = data.payment_chain.payments || [];
            let originalTotal = data.payment_chain.original_grand_total ? Number(data.payment_chain.original_grand_total) : numericGrandTotal;
            let remainder = (data.payment_chain.remainder !== undefined && !isNaN(Number(data.payment_chain.remainder))) ? Number(data.payment_chain.remainder) : 0;

            if (numericGrandTotal > 0 && Math.abs(originalTotal - numericGrandTotal) > 0.001) {
                if (payments.length === 0) {
                    originalTotal = numericGrandTotal;
                    remainder = numericGrandTotal;
                } else {
                    const totalStaged = payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
                    originalTotal = numericGrandTotal;
                    remainder = Math.round((numericGrandTotal - totalStaged) * 100) / 100;
                }
            }

            paymentChain = {
                cart_token: cartToken,
                original_grand_total: originalTotal,
                remainder: remainder,
                payments: payments,
            };

            // Recover flow state
            if (data.payment_chain.flow) {
                currentFlow = data.payment_chain.flow;
            } else if (data.payment_chain.is_debt) {
                currentFlow = 'kasbon';
            } else {
                currentFlow = 'full_payment';
            }

            const chainFlowAccessible = (data.flow_accessible !== false) && isFlowAccessible(currentFlow);
            if (!chainFlowAccessible) {
                isFlowLocked = true;
                flowLockedMessage = data.flow_access_message || getFlowDeniedMessage(currentFlow, true);
            } else {
                isFlowLocked = false;
                flowLockedMessage = null;
            }

            if (currentFlow === 'kasbon') {
                await loadPaymentTerms();
                if (stagedPaymentTermSelect && data.payment_chain.payment_term_id) {
                    stagedPaymentTermSelect.value = data.payment_chain.payment_term_id;
                }
            }

            await updateFlowUI();
            renderPaymentChain();
            updateRemainderDisplay();
            if (isFlowLocked) {
                showError(flowLockedMessage);
            }
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
            if (payment.payment_image && payment.payment_image.original_name) {
                content += `<div style="font-size: 0.75rem; margin-top: 0.25rem; opacity: 0.9;"><i class="fas fa-file-image mr-1"></i> ${escapeHtml(payment.payment_image.original_name)}</div>`;
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

    function updatePaymentImageVisibility() {
        if (!stagedPaymentImageContainer) return;

        if (selectedPaymentMethod && !selectedPaymentMethod.is_cash) {
            stagedPaymentImageContainer.style.display = 'block';
        } else {
            stagedPaymentImageContainer.style.display = 'none';
            if (selectedPaymentMethod && selectedPaymentMethod.is_cash && currentImageToken) {
                handlePaymentImageRemove();
            }
        }
    }

    function resetPaymentImageState() {
        if (imageUploadAbortController) {
            try {
                imageUploadAbortController.abort();
            } catch (e) {}
            imageUploadAbortController = null;
        }
        isImageUploading = false;
        currentImageToken = null;
        currentImageName = null;
        if (stagedPaymentImageFile) stagedPaymentImageFile.value = '';
        if (stagedPaymentImageError) stagedPaymentImageError.textContent = '';
        if (stagedPaymentImageUploadState) stagedPaymentImageUploadState.classList.remove('d-none');
        if (stagedPaymentImageUploadingState) stagedPaymentImageUploadingState.classList.add('d-none');
        if (stagedPaymentImageSuccessState) {
            stagedPaymentImageSuccessState.classList.add('d-none');
            stagedPaymentImageSuccessState.classList.remove('d-flex');
        }
    }

    async function handlePaymentImageUpload(event) {
        if (state === States.PROCESSING) return;

        const file = event.target.files[0];
        if (!file) return;

        if (!['image/jpeg', 'image/png'].includes(file.type)) {
            if (stagedPaymentImageError) stagedPaymentImageError.textContent = 'Format harus JPEG/PNG';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            if (stagedPaymentImageError) stagedPaymentImageError.textContent = 'Ukuran maksimal 5MB';
            return;
        }

        if (stagedPaymentImageError) stagedPaymentImageError.textContent = '';
        if (stagedPaymentImageUploadState) stagedPaymentImageUploadState.classList.add('d-none');
        if (stagedPaymentImageUploadingState) stagedPaymentImageUploadingState.classList.remove('d-none');
        
        if (stagedSubmitButton) stagedSubmitButton.disabled = true;

        const formData = new FormData();
        formData.append('image', file);
        const cartToken = paymentChain?.cart_token || currentCartToken;
        formData.append('cart_token', cartToken);

        const uploadGeneration = activeGeneration;
        isImageUploading = true;
        if (imageUploadAbortController) {
            try {
                imageUploadAbortController.abort();
            } catch (e) {}
        }
        if (typeof AbortController !== 'undefined') {
            imageUploadAbortController = new AbortController();
        }

        try {
            const fetchOptions = {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json'
                },
                body: formData
            };
            if (imageUploadAbortController) {
                fetchOptions.signal = imageUploadAbortController.signal;
            }
            const response = await fetch('/pos/sell/payment-image', fetchOptions);

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Gagal mengunggah gambar');
            }

            // Invalidate if generation changed during upload (e.g. flow switched or reset)
            if (uploadGeneration !== activeGeneration) {
                console.log('[PosStagedPayment] Discarding stale image upload response from generation', uploadGeneration, 'current:', activeGeneration);
                if (data && data.token) {
                    deletePendingImage(data.token, cartToken);
                }
                return;
            }

            currentImageToken = data.token;
            currentImageName = data.original_name;

            if (stagedPaymentImageUploadingState) stagedPaymentImageUploadingState.classList.add('d-none');
            if (stagedPaymentImageSuccessState) {
                stagedPaymentImageSuccessState.classList.remove('d-none');
                stagedPaymentImageSuccessState.classList.add('d-flex');
            }
            if (stagedPaymentImageFilename) {
                stagedPaymentImageFilename.textContent = currentImageName;
            }
        } catch (error) {
            // Disregard errors if aborted or generation changed
            if (uploadGeneration !== activeGeneration || (error && error.name === 'AbortError')) {
                return;
            }

            if (stagedPaymentImageError) stagedPaymentImageError.textContent = error.message;
            if (stagedPaymentImageUploadingState) stagedPaymentImageUploadingState.classList.add('d-none');
            if (stagedPaymentImageUploadState) stagedPaymentImageUploadState.classList.remove('d-none');
            if (stagedPaymentImageFile) stagedPaymentImageFile.value = '';
        } finally {
            if (uploadGeneration === activeGeneration) {
                isImageUploading = false;
                imageUploadAbortController = null;
                updateStageValidation();
            }
        }
    }

    async function deletePendingImage(tokenToDelete, cartToken) {
        if (!tokenToDelete) return;

        try {
            await fetch('/pos/sell/payment-image', {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    token: tokenToDelete,
                    cart_token: cartToken
                })
            });
        } catch (e) {
            console.error('Failed to delete image', e);
        }
    }

    function handlePaymentImageRemove() {
        if (state === States.PROCESSING) return;
        if (!currentImageToken) return;

        const tokenToDelete = currentImageToken;
        const cartToken = paymentChain?.cart_token || currentCartToken;

        // Synchronously detach active form image state
        resetPaymentImageState();
        updateStageValidation();

        // Asynchronously delete the pending image without mutating active form state on completion
        deletePendingImage(tokenToDelete, cartToken);
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
        updatePaymentImageVisibility();
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
        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            if (stagedSubmitButton) {
                stagedSubmitButton.disabled = true;
            }
            return;
        }

        let isValid = true;
        const remainder = paymentChain?.remainder !== undefined ? paymentChain.remainder : 0;
        const isDebt = (currentFlow === 'kasbon');

        if (!isDebt && remainder <= 0) {
            isValid = true;
        } else {
            const amount = Number(stagedAmountInput?.dataset.rawValue || 0);

            if (isDebt) {
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
                } else {
                    // For Kasbon, entering down payment is optional; submit is disabled if amount is 0,
                    // but finish Kasbon button remains enabled to finalize with 0 down payment.
                    isValid = false;
                }
            } else {
                if (!selectedPaymentMethod) {
                    isValid = false;
                }

                if (amount <= 0) {
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

        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, isFlowLocked));
            return;
        }

        const remainder = paymentChain?.remainder !== undefined ? paymentChain.remainder : 0;
        const isDebt = (currentFlow === 'kasbon');
        const amount = Number(stagedAmountInput.dataset.rawValue || stagedAmountInput.value || 0);

        // If remainder is <= 0 on full payment, finalize checkout
        if (!isDebt && remainder <= 0) {
            showFinalConfirmation();
            return;
        }

        if (!validateBeforeSubmit()) return;

        if (isDebt && amount === 0) {
            handleFinishKasbon();
            return;
        }

        // Populate Confirmation Modal
        if (confirmMethodLabel) confirmMethodLabel.textContent = selectedPaymentMethod?.name || '-';
        if (confirmRemainingLabel) confirmRemainingLabel.textContent = formatPrice(remainder);
        if (confirmEnteredLabel) confirmEnteredLabel.textContent = formatPrice(amount);

        // Warning Logic and Alerts
        if (confirmAlertContainer) {
            confirmAlertContainer.className = 'alert'; // reset classes

            if (isDebt) {
                const totalAfterThis = ((paymentChain.payments || []).reduce((sum, p) => sum + Number(p.amount), 0)) + amount;
                const remainingDebt = paymentChain.original_grand_total - totalAfterThis;
                confirmAlertContainer.classList.add('alert-warning');
                confirmAlertContainer.textContent = `Uang Muka: ${formatPrice(amount)} (Sisa Kasbon: ${formatPrice(remainingDebt)})`;
            } else if (amount === remainder) {
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

        // Show Confirmation Modal
        if (confirmModalElement) {
            $(confirmModalElement).modal('show');
        }
    }

    // Task 4.2: Execute stage payment
    async function executeStagePayment(event) {
        if (event) event.preventDefault();

        if (confirmModalElement) {
            $(confirmModalElement).modal('hide');
        }

        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, isFlowLocked));
            return;
        }

        setProcessing(true);
        clearErrors();

        const requestGen = activeGeneration;

        try {
            const amount = Number(stagedAmountInput.dataset.rawValue || stagedAmountInput.value);
            const isDebt = (currentFlow === 'kasbon');
            const payload = {
                cart_token: paymentChain.cart_token,
                payment_method_id: selectedPaymentMethod.id,
                amount: amount,
                edc_reference: stagedEdcReferenceInput?.value.trim() || null,
                grand_total: paymentChain.original_grand_total,
                flow: currentFlow,
                is_debt: isDebt,
                payment_image_token: currentImageToken || null,
            };

            if (isDebt) {
                payload.payment_term_id = (stagedPaymentTermSelect && stagedPaymentTermSelect.value) ? stagedPaymentTermSelect.value : null;
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

            // Discard late / in-flight response if generation changed
            if (requestGen !== activeGeneration) {
                console.warn('[PosStagedPayment] Discarding late stage response because generation changed');
                return;
            }

            if (!response.ok) {
                showError(data.message || 'Gagal memproses pembayaran');
                return;
            }

            // Update payment chain with new payment
            paymentChain.payments = data.payment_chain.payments || [];
            paymentChain.remainder = data.remainder || 0;

            console.log('[PosStagedPayment] Updated chain:', { remainder: paymentChain.remainder, payments: paymentChain.payments.length });

            // Clear local active image state since ownership is transferred to the committed chain
            resetPaymentImageState();

            if (currentFlow === 'kasbon') {
                // Task 4.1: Adding a Kasbon stage NEVER forces completion!
                // Cashier stays in modal to add more down payments or click "Selesaikan Kasbon"
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
            } else if (data.remainder > 0) {
                // More payments needed for full payment
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
            } else {
                // Full payment completed
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
                showFinalConfirmation();
            }
        } catch (error) {
            console.error('[PosStagedPayment] Error:', error);
            showError('Terjadi kesalahan: ' + error.message);
        } finally {
            setProcessing(false);
        }
    }

    // Validate amount for method with Kasbon cumulative check
    function validateAmountForMethod(amount, remainder, method, isDebt) {
        if (!method) return false;

        // Debt validation rule - combined down payments must be strictly less than original grand total
        if (isDebt) {
            const grandTotal = paymentChain?.original_grand_total || remainder;
            const previousStaged = (paymentChain?.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
            if (previousStaged + amount >= grandTotal) {
                return false;
            }
            if (!method.is_cash && amount > remainder) {
                return false;
            }
            return true;
        }

        // Cash validation rule - amount >= remainder (allow overpayment)
        if (method.is_cash) {
            return amount >= remainder;
        }

        // Non-cash validation rule - amount <= remainder (no overpayment)
        return amount <= remainder;
    }

    // Task 4.4: Validate before payment submission
    function validateBeforeSubmit() {
        const amount = Number(stagedAmountInput?.dataset.rawValue || stagedAmountInput?.value || 0);
        const remainder = paymentChain?.remainder || 0;
        const isDebt = (currentFlow === 'kasbon');

        if (!isDebt && amount <= 0) {
            showError('Jumlah pembayaran harus lebih dari 0');
            return false;
        }

        if (isDebt && amount < 0) {
            showError('Jumlah pembayaran tidak boleh negatif');
            return false;
        }

        if (isDebt) {
            if (!currentHasCustomer) {
                showError('Pilih pelanggan terlebih dahulu untuk transaksi Kasbon.');
                return false;
            }
        }

        if (selectedPaymentMethod && amount > 0) {
            if (!validateAmountForMethod(amount, remainder, selectedPaymentMethod, isDebt)) {
                if (isDebt) {
                    showError(`Total uang muka Kasbon tidak boleh sama dengan atau melebihi total belanja ${formatPrice(paymentChain?.original_grand_total || remainder)}. Silakan beralih ke Bayar Lunas.`);
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
        updatePaymentImageVisibility();
        resetPaymentImageState();
        updateAmountHint();
        updateStageValidation();
        if (stagedMethodSearchInput) stagedMethodSearchInput.focus();
    }

    // Reset full checkout context (debt state, lifecycle acknowledgement, etc)
    function resetCheckoutContext() {
        if (stagedPaymentTermSelect) stagedPaymentTermSelect.value = '';
        isLifecycleAcknowledged = false;
        updateFlowUI();
    }

    // Check if there is dirty input or staged payment data
    function isDirty() {
        if (paymentChain && Array.isArray(paymentChain.payments) && paymentChain.payments.length > 0) {
            return true;
        }
        if (stagedAmountInput) {
            const raw = stagedAmountInput.dataset?.rawValue || stagedAmountInput.value || '';
            const cleaned = String(raw).replace(/[^0-9.]/g, '');
            if (cleaned !== '' && Number(cleaned) > 0) {
                return true;
            }
        }
        if (isImageUploading || currentImageToken) {
            return true;
        }
        if (stagedEdcReferenceInput && stagedEdcReferenceInput.value.trim() !== '') {
            return true;
        }
        if (currentFlow === 'kasbon' && stagedPaymentTermSelect && stagedPaymentTermSelect.value) {
            return true;
        }
        return false;
    }

    // Reconcile payment chain with authoritative server session
    async function reconcileAuthoritativeChain(cartToken, grandTotal, flow) {
        try {
            const response = await fetch('/pos/sell/checkout/reconcile-payment-chain', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    cart_token: cartToken,
                    grand_total: grandTotal,
                    flow: flow || currentFlow,
                })
            });

            if (!response.ok) return null;
            const data = await response.json();
            return data.payment_chain || null;
        } catch (e) {
            console.error('[PosStagedPayment] Failed to reconcile server chain:', e);
            return null;
        }
    }

    // Switch flow with dirty confirmation and authoritative reset
    async function switchFlow(targetFlow, targetGrandTotal = null) {
        if (state === States.PROCESSING) {
            showError('Operasi pembayaran sedang berlangsung, harap tunggu hingga selesai.');
            return;
        }

        if (!targetFlow || targetFlow === currentFlow) {
            return;
        }

        if (!canSwitchFlow || isFlowLocked || !isFlowAccessible(targetFlow)) {
            showError(isFlowLocked ? (flowLockedMessage || getFlowDeniedMessage(currentFlow, true)) : getFlowDeniedMessage(targetFlow));
            return;
        }

        if (targetFlow === 'kasbon' && !currentHasCustomer) {
            showError('Pilih pelanggan terlebih dahulu untuk menggunakan fitur Kasbon.');
            return;
        }

        if (isDirty()) {
            pendingTargetFlow = targetFlow;
            pendingTargetGrandTotal = targetGrandTotal;
            if (flowSwitchMessageElement) {
                if (targetFlow === 'kasbon') {
                    flowSwitchMessageElement.textContent = 'Pembayaran yang sudah dimasukkan akan dibatalkan. Anda akan beralih ke alur Kasbon (Piutang). Lanjutkan?';
                } else {
                    flowSwitchMessageElement.textContent = 'Uang muka kasbon dan jangka waktu yang dipilih akan dibatalkan. Anda akan beralih ke alur Bayar Lunas. Lanjutkan?';
                }
            }
            if (flowSwitchModalElement) {
                $(flowSwitchModalElement).modal('show');
            }
        } else {
            await executeAuthoritativeReset(targetFlow, targetGrandTotal);
        }
    }

    // Confirm flow switch from modal
    async function confirmFlowSwitch() {
        if (flowSwitchModalElement) {
            $(flowSwitchModalElement).modal('hide');
        }

        if (pendingTargetFlow) {
            const target = pendingTargetFlow;
            const targetTotal = pendingTargetGrandTotal;
            pendingTargetFlow = null;
            pendingTargetGrandTotal = null;
            await executeAuthoritativeReset(target, targetTotal);
        }
    }

    // Execute authoritative server reset under cart-scope
    async function executeAuthoritativeReset(targetFlow, targetGrandTotal = null) {
        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, true));
            return false;
        }

        if (targetFlow && !isFlowAccessible(targetFlow)) {
            showError(getFlowDeniedMessage(targetFlow));
            return false;
        }

        setProcessing(true);
        clearErrors();

        try {
            if (currentCartToken) {
                const resetBody = {
                    cart_token: currentCartToken,
                };
                if (targetFlow) {
                    resetBody.target_flow = targetFlow;
                }

                const response = await fetch('/pos/sell/checkout/payment-chain', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(resetBody),
                });

                if (!response.ok) {
                    let errorMessage = 'Gagal mereset pembayaran. Silakan coba lagi.';
                    try {
                        const data = await response.json();
                        errorMessage = data.message || errorMessage;
                    } catch (e) {}
                    showError(errorMessage);
                    setProcessing(false);
                    return false;
                }
            }

            // Invalidate any in-flight responses
            activeGeneration++;

            // Abort and clear pending image upload
            if (imageUploadAbortController) {
                try {
                    imageUploadAbortController.abort();
                } catch (e) {}
                imageUploadAbortController = null;
            }
            isImageUploading = false;

            // Clear local state
            const originalTotal = targetGrandTotal || paymentChain?.original_grand_total || 0;
            paymentChain = null;
            currentStageData = null;
            resetPaymentImageState();
            resetStageForm();
            if (stagedPaymentTermSelect) stagedPaymentTermSelect.value = '';
            currentFinalizeIdempotencyKey = null;

            if (targetFlow) {
                currentFlow = targetFlow;
                initializeNewPaymentChain(currentCartToken, originalTotal);
                await updateFlowUI();
            }

            setProcessing(false);
            return true;
        } catch (error) {
            console.error('[PosStagedPayment] Failed to execute authoritative reset:', error);
            showError('Gagal mereset pembayaran. Silakan coba lagi.');
            setProcessing(false);
            return false;
        }
    }

    // Handle payment chain reset button with confirmation
    async function handleResetPaymentChain() {
        if (!paymentChain || state === States.PROCESSING) {
            return;
        }

        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, true));
            return;
        }

        if (!confirm('Anda yakin ingin menghapus semua pembayaran yang sudah diproses? Tindakan ini tidak dapat dibatalkan.')) {
            return;
        }

        const success = await executeAuthoritativeReset(currentFlow);
        if (success && stagedModalElement) {
            $(stagedModalElement).modal('hide');
        }
    }

    // Handle explicit Kasbon completion (0 or multiple down payments)
    function handleFinishKasbon(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();

        if (state === States.PROCESSING) return;

        if (isFlowLocked || !isFlowAccessible('kasbon')) {
            showError(flowLockedMessage || getFlowDeniedMessage('kasbon', isFlowLocked));
            return;
        }

        if (!currentHasCustomer) {
            showError('Pilih pelanggan terlebih dahulu untuk transaksi Kasbon.');
            return;
        }

        if (!stagedPaymentTermSelect || !stagedPaymentTermSelect.value) {
            showError('Pilih jangka waktu (payment term) terlebih dahulu.');
            if (stagedPaymentTermSelect && typeof stagedPaymentTermSelect.focus === 'function') {
                stagedPaymentTermSelect.focus();
            }
            return;
        }

        const unsubmittedAmount = Number(stagedAmountInput?.dataset?.rawValue || stagedAmountInput?.value || 0);
        if (unsubmittedAmount > 0) {
            showError('Terdapat nominal pembayaran yang belum ditambahkan. Klik Tambah Uang Muka terlebih dahulu atau kosongkan kolom nominal.');
            return;
        }

        showFinalConfirmation();
    }

    // Update UI elements based on currentFlow
    async function updateFlowUI() {
        const isKasbon = (currentFlow === 'kasbon');

        if (stagedFlowBadge) {
            stagedFlowBadge.className = isKasbon ? 'badge badge-danger px-2 py-1 ml-2' : 'badge badge-primary px-2 py-1 ml-2';
            stagedFlowBadge.textContent = isKasbon
                ? (requiresKasbonApproval ? 'Kasbon · Perlu Persetujuan' : 'Kasbon (Piutang)')
                : 'Bayar Lunas';
        }

        if (stagedSwitchFlowBtn) {
            const canShowSwitch = Boolean(canSwitchFlow && !isFlowLocked);
            if (canShowSwitch) {
                stagedSwitchFlowBtn.classList.remove('d-none');
                stagedSwitchFlowBtn.disabled = false;
            } else {
                stagedSwitchFlowBtn.classList.add('d-none');
                stagedSwitchFlowBtn.disabled = true;
            }
        }

        if (stagedSwitchFlowText) {
            stagedSwitchFlowText.textContent = isKasbon ? 'Ganti ke Bayar Lunas' : 'Ganti ke Kasbon';
        }

        if (stagedRemainderTitle) {
            stagedRemainderTitle.textContent = isKasbon ? 'Sisa Kasbon:' : 'Sisa Pembayaran:';
        }

        if (stagedAmountLabel) {
            stagedAmountLabel.textContent = isKasbon ? 'Jumlah Uang Muka (Rp) (Opsional)' : 'Jumlah Pembayaran (Rp)';
        }

        if (stagedDebtTermsContainer) {
            if (isKasbon) {
                stagedDebtTermsContainer.classList.remove('d-none');
                if (cachedPaymentTerms === null) {
                    await loadPaymentTerms();
                }
            } else {
                stagedDebtTermsContainer.classList.add('d-none');
            }
        }

        if (stagedFinishKasbonBtn) {
            if (isKasbon) {
                stagedFinishKasbonBtn.classList.remove('d-none');
            } else {
                stagedFinishKasbonBtn.classList.add('d-none');
            }
            stagedFinishKasbonBtn.disabled = isFlowLocked;
        }

        if (stagedMethodSearchInput) stagedMethodSearchInput.disabled = isFlowLocked;
        if (stagedAmountInput) stagedAmountInput.disabled = isFlowLocked;
        if (stagedEdcReferenceInput) stagedEdcReferenceInput.disabled = isFlowLocked;
        if (stagedPaymentTermSelect && isFlowLocked) stagedPaymentTermSelect.disabled = true;
        if (stagedPaymentResetBtn) {
            stagedPaymentResetBtn.disabled = isFlowLocked;
            stagedPaymentResetBtn.style.opacity = isFlowLocked ? '0.5' : '1';
            stagedPaymentResetBtn.style.cursor = isFlowLocked ? 'not-allowed' : 'pointer';
            stagedPaymentResetBtn.style.pointerEvents = isFlowLocked ? 'none' : 'auto';
        }

        if (stagedSubmitButton) {
            stagedSubmitButton.textContent = isKasbon ? 'Tambah Uang Muka' : 'Lanjut Pembayaran';
        }

        updateStageValidation();
    }

    // Task 3.4: Handle modal lock during processing - disable all dismiss and reset controls
    function setProcessing(isProcessing) {
        state = isProcessing ? States.PROCESSING : States.SELECTING_METHOD;

        if (stagedProcessingSpinner) {
            stagedProcessingSpinner.style.display = isProcessing ? 'block' : 'none';
        }

        if (stagedMethodSearchInput) stagedMethodSearchInput.disabled = isProcessing;
        if (stagedAmountInput) stagedAmountInput.disabled = isProcessing;
        if (stagedEdcReferenceInput) stagedEdcReferenceInput.disabled = isProcessing;
        if (stagedSubmitButton) stagedSubmitButton.disabled = isProcessing;
        if (stagedPaymentImageFile) stagedPaymentImageFile.disabled = isProcessing;
        if (stagedPaymentImageRemoveBtn) {
            stagedPaymentImageRemoveBtn.disabled = isProcessing;
            stagedPaymentImageRemoveBtn.style.opacity = isProcessing ? '0.5' : '1';
            stagedPaymentImageRemoveBtn.style.cursor = isProcessing ? 'not-allowed' : 'pointer';
            stagedPaymentImageRemoveBtn.style.pointerEvents = isProcessing ? 'none' : 'auto';
        }

        // Disable all dismiss controls (header close ×, footer Batal)
        const dismissControls = stagedModalElement?.querySelectorAll('[data-dismiss="modal"]');
        if (dismissControls) {
            dismissControls.forEach(control => {
                control.disabled = isProcessing;
                control.style.opacity = isProcessing ? '0.5' : '1';
                control.style.cursor = isProcessing ? 'not-allowed' : 'pointer';
                control.style.pointerEvents = isProcessing ? 'none' : 'auto';
            });
        }

        // Disable reset control
        if (stagedPaymentResetBtn) {
            stagedPaymentResetBtn.disabled = isProcessing;
            stagedPaymentResetBtn.style.opacity = isProcessing ? '0.5' : '1';
            stagedPaymentResetBtn.style.cursor = isProcessing ? 'not-allowed' : 'pointer';
            stagedPaymentResetBtn.style.pointerEvents = isProcessing ? 'none' : 'auto';
        }
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

        const isDebt = (currentFlow === 'kasbon');
        const totalPaid = (paymentChain.payments || []).reduce((sum, p) => sum + (Number(p.amount) || 0), 0);

        if (finalGrandTotalLabel) finalGrandTotalLabel.textContent = formatPrice(paymentChain.original_grand_total);
        if (finalPaidAmountLabel) finalPaidAmountLabel.textContent = formatPrice(totalPaid);

        if (isDebt) {
            if (finalChangeContainer) finalChangeContainer.classList.add('d-none');
            if (finalDebtContainer) finalDebtContainer.classList.remove('d-none');
            if (finalDebtDetailsContainer) finalDebtDetailsContainer.classList.remove('d-none');
            
            const remainingDebt = Math.max(0, paymentChain.original_grand_total - totalPaid);
            if (finalDebtLabel) finalDebtLabel.textContent = formatPrice(remainingDebt);
            
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
            
            const change = Math.max(0, totalPaid - paymentChain.original_grand_total);
            if (finalChangeLabel) finalChangeLabel.textContent = formatPrice(change);
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

        if (isFlowLocked || !isFlowAccessible(currentFlow)) {
            showError(flowLockedMessage || getFlowDeniedMessage(currentFlow, isFlowLocked));
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
            const isDebt = (currentFlow === 'kasbon');
            const payload = {
                cart_token: paymentChain.cart_token,
                idempotency_key: currentFinalizeIdempotencyKey,
                acknowledge_lifecycle_warning: isLifecycleAcknowledged,
                flow: currentFlow,
                is_debt: isDebt,
            };

            if (isDebt) {
                payload.payment_term_id = (stagedPaymentTermSelect && stagedPaymentTermSelect.value) ? stagedPaymentTermSelect.value : null;
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
                    const helper = (typeof window !== 'undefined' && window.BundleLifecycleWarning)
                        || (typeof BundleLifecycleWarning !== 'undefined' ? BundleLifecycleWarning : null);

                    if (data.code === 'BUNDLE_LIFECYCLE_WARNING' || data.warning || data.details?.warning) {
                        if (!helper || typeof helper.resolveLifecycleWarning !== 'function' || typeof helper.buildLifecycleWarningModalHtml !== 'function') {
                            console.error('BundleLifecycleWarning helper is unavailable.');
                            throw new Error('Gagal memverifikasi status paket: modul peringatan tidak tersedia.');
                        }

                        const warningData = helper.resolveLifecycleWarning(data);
                        if (warningData && (Array.isArray(warningData.items) || data.code === 'BUNDLE_LIFECYCLE_WARNING')) {
                            const modalHtml = helper.buildLifecycleWarningModalHtml(
                                warningData,
                                'Terdapat perubahan status pada paket produk dalam transaksi ini. Lanjutkan untuk tetap menggunakan komposisi yang tersimpan.',
                                'Apakah Anda ingin melanjutkan transaksi dengan komposisi yang tersimpan?'
                            );

                            if (typeof Swal !== 'undefined') {
                                const result = await Swal.fire({
                                    icon: 'warning',
                                    title: 'Peringatan Status Paket',
                                    html: modalHtml,
                                    showCancelButton: true,
                                    confirmButtonText: 'Lanjutkan Transaksi',
                                    cancelButtonText: 'Batal',
                                });

                                if (result.isConfirmed) {
                                    isLifecycleAcknowledged = true;
                                    payload.acknowledge_lifecycle_warning = true;
                                    return performFinalize(token);
                                } else {
                                    throw new Error('Konfirmasi status paket dibatalkan.');
                                }
                            } else {
                                console.error('SweetAlert is not available for lifecycle warning modal.');
                                throw new Error('Validasi status paket memerlukan konfirmasi modal.');
                            }
                        }
                    }

                    if (response.status !== 403 && (data.code === 'APPROVAL_REQUIRED' || (data.message && data.message.includes('otorisasi')))) {
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
        if (stagedPaymentTermSelect) {
            stagedPaymentTermSelect.innerHTML = '<option value="">-- Memuat Jangka Waktu... --</option>';
            stagedPaymentTermSelect.disabled = true;
        }
        if (stagedPaymentTermRetryBtn) stagedPaymentTermRetryBtn.classList.add('d-none');
        clearErrors();

        try {
            const response = await fetch('/pos/sell/payment-terms/search', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });

            // Check HTTP status - non-2xx responses are treated as load failures
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: Gagal memuat jangka waktu pembayaran.`);
            }

            // Validate response payload
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                throw new Error('Format data jangka waktu pembayaran tidak valid.');
            }

            if (!data || !Array.isArray(data.terms)) {
                throw new Error('Format data jangka waktu pembayaran tidak valid.');
            }

            // Validate each term entry has required fields
            const validTerms = data.terms.filter(term => {
                return term &&
                    typeof term === 'object' &&
                    term.id != null &&
                    typeof term.name === 'string' &&
                    typeof term.longevity === 'number' &&
                    term.longevity >= 0;
            });

            if (validTerms.length !== data.terms.length) {
                throw new Error('Beberapa data jangka waktu pembayaran tidak valid.');
            }

            // Successfully loaded terms
            cachedPaymentTerms = validTerms;

            if (stagedPaymentTermSelect) {
                if (cachedPaymentTerms.length === 0) {
                    // Empty successful result - display "No terms available"
                    stagedPaymentTermSelect.innerHTML = '<option value="">-- Tidak ada Jangka Waktu tersedia --</option>';
                    stagedPaymentTermSelect.disabled = true;
                } else {
                    // Populate with returned terms
                    stagedPaymentTermSelect.innerHTML = '<option value="">-- Pilih Jangka Waktu --</option>';
                    cachedPaymentTerms.forEach(term => {
                        const opt = document.createElement('option');
                        opt.value = term.id;
                        opt.textContent = `${term.name} (${term.longevity} Hari)`;
                        stagedPaymentTermSelect.appendChild(opt);
                    });
                    stagedPaymentTermSelect.disabled = false;
                }
            }
        } catch (error) {
            console.error('Failed to load payment terms:', error);

            // Do NOT cache failures - distinguish from successful empty result
            cachedPaymentTerms = null;

            if (stagedPaymentTermSelect) {
                stagedPaymentTermSelect.innerHTML = '<option value="">-- Gagal memuat Jangka Waktu --</option>';
                stagedPaymentTermSelect.disabled = true;
            }

            // Show retry button to allow retry without page reload
            if (stagedPaymentTermRetryBtn) stagedPaymentTermRetryBtn.classList.remove('d-none');

            // Route error to staged-modal error surface
            showError('Gagal memuat jangka waktu pembayaran. Silakan coba lagi.');
        } finally {
            updateStageValidation();
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

    function isFlowAccessible(flow) {
        if (flow === 'kasbon') {
            return Boolean(canUseKasbonFlow);
        }

        return Boolean(canUseLunasFlow);
    }

    function getFlowDeniedMessage(flow, isRecovery = false) {
        if (flow === 'kasbon') {
            return isRecovery
                ? 'Rantai pembayaran Kasbon tersimpan pada sesi ini, tetapi Anda tidak lagi memiliki izin untuk melanjutkan atau mereset alur Kasbon.'
                : 'Anda tidak memiliki izin untuk memproses checkout Kasbon (Piutang).';
        }

        return isRecovery
            ? 'Rantai pembayaran Bayar Lunas tersimpan pada sesi ini, tetapi Anda tidak lagi memiliki izin untuk melanjutkan atau mereset alur Bayar Lunas.'
            : 'Anda tidak memiliki izin untuk memproses checkout Bayar Lunas.';
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
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
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
        switchFlow,
        isDirty,
        isFlowAccessible,
        canSwitchFlow: () => canSwitchFlow,
        isFlowLocked: () => isFlowLocked,
        getCurrentFlow: () => currentFlow,
        getActiveGeneration: () => activeGeneration,
        getPaymentChain: () => paymentChain,
        getCurrentImageToken: () => currentImageToken,
        isImageUploading: () => isImageUploading,
        handleFinishKasbon,
        executeAuthoritativeReset,
        setLifecycleAcknowledged: function (acknowledged) {
            isLifecycleAcknowledged = !!acknowledged;
        },
        setPaymentMethods: function (methods) {
            cachedPaymentMethods = methods || [];
        },
    };
})();
