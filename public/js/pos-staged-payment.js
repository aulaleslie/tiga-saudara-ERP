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

    // DOM elements
    let stagedModalElement = null;
    let stagedMethodSearchInput = null;
    let stagedMethodResultsDropdown = null;
    let stagedPaymentChainList = null;
    let stagedRemainderLabel = null;
    let stagedAmountInput = null;
    let stagedEdcReferenceInput = null;
    let stagedEdcReferenceContainer = null;
    let stagedSubmitButton = null;
    let stagedProcessingSpinner = null;
    let stagedErrorAlert = null;

    // Cached data
    let cachedPaymentMethods = [];
    let selectedPaymentMethod = null;

    // Task 3.1: Initialize state machine
    function initialize(config = {}) {
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
        stagedEdcReferenceInput = config.edcRefInput || document.getElementById('staged-edc-reference');
        stagedEdcReferenceContainer = config.edcRefContainer || document.getElementById('staged-edc-reference-container');
        stagedSubmitButton = config.submitButton || document.getElementById('staged-payment-submit');
        stagedProcessingSpinner = config.spinner || document.getElementById('staged-payment-spinner');
        stagedErrorAlert = config.errorAlert || document.getElementById('staged-payment-error');

        setupEventListeners();
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
            stagedEdcReferenceInput.addEventListener('input', validateEdcReferenceRealtime);
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

    // Task 5.1 & 5.2: Open modal and check for reload recovery
    async function openModal(saleId) {
        if (!saleId) return;

        currentSaleId = saleId;
        state = States.SELECTING_METHOD;
        clearErrors();

        // Try to recover payment chain from session
        const recovered = await checkReloadRecovery(saleId);
        if (!recovered) {
            // Start fresh
            await initializeNewPaymentChain(saleId);
        }

        if (stagedModalElement) {
            $(stagedModalElement).modal('show');
        }
    }

    // Task 5.3: Check and recover payment chain from session
    async function checkReloadRecovery(saleId) {
        try {
            const response = await fetch('/api/pos/sell/checkout/payment-chain', {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ sale_id: saleId }),
            });

            if (!response.ok) return false;

            const data = await response.json();
            if (!data.has_chain) return false;

            // Recover payment chain from session
            paymentChain = {
                sale_id: data.sale_id,
                remainder: data.remainder,
                total_committed: data.total_committed,
                payments: data.payment_chain || [],
            };

            renderPaymentChain();
            updateRemainderDisplay();
            return true;
        } catch (error) {
            console.error('Reload recovery error:', error);
            return false;
        }
    }

    // Initialize fresh payment chain for new transaction
    async function initializeNewPaymentChain(saleId) {
        try {
            // Fetch sale to get due_amount
            const response = await fetch(`/api/sales/${saleId}`, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });

            if (!response.ok) throw new Error('Failed to load sale');

            const sale = await response.json();
            const dueAmount = Number(sale.due_amount || 0);

            paymentChain = {
                sale_id: saleId,
                remainder: dueAmount,
                total_committed: 0,
                payments: [],
            };

            renderPaymentChain();
            updateRemainderDisplay();
        } catch (error) {
            showError('Gagal memuat data transaksi: ' + error.message);
        }
    }

    // Task 3.4: Render payment chain UI (list of committed payments)
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
            item.className = 'badge badge-success mr-2 mb-2 p-2';
            item.innerHTML = `
                ✓ <strong>${escapeHtml(payment.method_name)}</strong>
                ${formatPrice(payment.amount)}
                ${payment.edc_reference ? ` (${escapeHtml(payment.edc_reference)})` : ''}
            `;
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

    // Task 3.6: Real-time EDC reference validation
    async function validateEdcReferenceRealtime(event) {
        const reference = event.target.value.trim();
        if (!reference) return;

        try {
            const response = await fetch('/api/pos/sell/checkout/validate-edc-reference', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ reference }),
            });

            const data = await response.json();
            if (!data.valid) {
                stagedEdcReferenceInput.classList.add('is-invalid');
            } else {
                stagedEdcReferenceInput.classList.remove('is-invalid');
            }
        } catch (error) {
            console.error('EDC reference validation error:', error);
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
        if (stagedAmountInput) {
            stagedAmountInput.focus();
            stagedAmountInput.value = '';
        }
        updateStageValidation();
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
    }

    // Task 4.1 & 4.2: Validate current stage
    function updateStageValidation() {
        let isValid = true;

        if (!selectedPaymentMethod) {
            isValid = false;
        }

        const amount = Number(stagedAmountInput?.value || 0);
        if (amount <= 0) {
            isValid = false;
        }

        if (selectedPaymentMethod && !selectedPaymentMethod.is_cash && selectedPaymentMethod.requires_reference) {
            const reference = stagedEdcReferenceInput?.value.trim() || '';
            if (!reference) {
                isValid = false;
            }
        }

        if (stagedSubmitButton) {
            stagedSubmitButton.disabled = !isValid;
        }
    }

    // Task 4.1 & 4.3: Submit stage payment
    async function submitStagePayment(event) {
        event.preventDefault();

        if (!validateBeforeSubmit()) return;

        setProcessing(true);
        clearErrors();

        try {
            const amount = Number(stagedAmountInput.value);
            const payload = {
                sale_id: currentSaleId,
                payment_method_id: selectedPaymentMethod.id,
                amount: amount,
                edc_reference: stagedEdcReferenceInput?.value.trim() || null,
                idempotency_key: generateIdempotencyKey(),
            };

            const response = await fetch('/api/pos/sell/checkout/stage-payment', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();

            if (!data.success) {
                showError(data.message || 'Gagal memproses pembayaran');
                return;
            }

            // Task 4.3: Update payment chain with new payment
            paymentChain.payments = data.payment_chain || [];
            paymentChain.remainder = data.remainder || 0;
            paymentChain.total_committed = data.total_committed || 0;

            // Task 4.3 & 4.4: Check remainder and proceed
            if (data.remainder > 0) {
                // More payments needed
                resetStageForm();
                renderPaymentChain();
                updateRemainderDisplay();
            } else if (data.remainder === 0) {
                // Payment complete
                handlePaymentComplete(data.overpayment);
            } else {
                // Overpayment - show change
                handlePaymentComplete(Math.abs(data.remainder));
            }
        } catch (error) {
            showError('Terjadi kesalahan: ' + error.message);
        } finally {
            setProcessing(false);
        }
    }

    // Task 4.4: Validate before payment submission
    function validateBeforeSubmit() {
        const amount = Number(stagedAmountInput?.value || 0);
        const remainder = paymentChain?.remainder || 0;

        if (amount <= 0) {
            showError('Jumlah pembayaran harus lebih dari 0');
            return false;
        }

        if (amount > remainder && remainder > 0) {
            showError(`Jumlah pembayaran tidak boleh lebih dari sisa ${formatPrice(remainder)}`);
            return false;
        }

        if (selectedPaymentMethod && !selectedPaymentMethod.is_cash && selectedPaymentMethod.requires_reference) {
            const reference = stagedEdcReferenceInput?.value.trim() || '';
            if (!reference) {
                showError('Nomor referensi EDC wajib diisi');
                return false;
            }

            if (!/^[a-zA-Z0-9]{1,20}$/.test(reference)) {
                showError('Format nomor referensi tidak valid');
                return false;
            }
        }

        return true;
    }

    // Reset form for next stage
    function resetStageForm() {
        selectedPaymentMethod = null;
        if (stagedMethodSearchInput) stagedMethodSearchInput.value = '';
        if (stagedAmountInput) stagedAmountInput.value = '';
        if (stagedEdcReferenceInput) stagedEdcReferenceInput.value = '';
        updateEdcReferenceVisibility();
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

    // Task 6.5: Handle payment completion
    function handlePaymentComplete(changeAmount) {
        state = States.COMPLETE;

        if (stagedModalElement) {
            $(stagedModalElement).modal('hide');
        }

        // Show gratitude modal with change amount
        showGratitudeModal(changeAmount);
    }

    // Task 6.4 & 6.5: Show gratitude modal
    function showGratitudeModal(changeAmount) {
        const modal = document.getElementById('pos-gratitude-modal');
        if (!modal) return;

        const changeLabel = modal.querySelector('#gratitude-change-amount');
        if (changeLabel) {
            if (changeAmount > 0) {
                changeLabel.textContent = `Kembalian: ${formatPrice(changeAmount)}`;
            } else {
                changeLabel.textContent = '';
            }
        }

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
        try {
            // This should fetch available payment methods for the current setting
            // Assuming there's an endpoint or a cached list
            if (typeof window.POS_PAYMENT_METHODS !== 'undefined') {
                cachedPaymentMethods = window.POS_PAYMENT_METHODS;
                return;
            }

            // Fallback: try to load from API
            const response = await fetch('/api/payment-methods', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });

            if (response.ok) {
                const data = await response.json();
                cachedPaymentMethods = data.methods || [];
            }
        } catch (error) {
            console.error('Failed to load payment methods:', error);
        }
    }

    // Helper: Show error message
    function showError(message) {
        if (stagedErrorAlert) {
            stagedErrorAlert.textContent = message;
            stagedErrorAlert.classList.remove('d-none');
        }
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

    // Public API
    return {
        initialize,
        openModal,
        loadPaymentMethods,
        printReceipt,
        setPaymentMethods: function (methods) {
            cachedPaymentMethods = methods || [];
        },
    };
})();
