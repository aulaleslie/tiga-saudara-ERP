/**
 * POS Session Modal Handlers
 * Handles admin force-close and supervisor finalization modal interactions
 */

const NON_TERMINAL_LABEL = 'Non-Terminal';

function initializeModals() {
    console.log('[POS Session] Initializing modals');

    // Initialize finalize modal
    const finalizeModal = document.getElementById('finalizeModal');
    console.log('[POS Session] Finalize modal found:', !!finalizeModal);

    if (finalizeModal) {
        // Track the button that triggered the modal
        let finalizeModalTriggerButton = null;

        // Capture click events on finalize buttons to store the trigger button
        document.addEventListener('click', function(e) {
            if (e.target.closest('[data-bs-target="#finalizeModal"], [data-target="#finalizeModal"]')) {
                finalizeModalTriggerButton = e.target.closest('button');
            }
        });

        // Listen to both Bootstrap and CoreUI modal events for compatibility
        const onFinalizeShow = function (event) {
            console.log('[POS Session] Finalize modal showing', event.type);
            const button = event.relatedTarget || finalizeModalTriggerButton;
            const sessionId = button?.dataset?.sessionId;
            const sessionCode = button?.dataset?.sessionCode || NON_TERMINAL_LABEL;

            console.log('[POS Session] Finalize trigger', { sessionId, sessionCode, buttonDataset: button?.dataset });

            if (!sessionId) {
                console.warn('[POS Session] Missing session id on finalize trigger');
                return;
            }

            // Fetch session data and populate modal
            fetchSessionData(sessionId, function (session) {
                populateFinalizeModal(session, sessionCode);
            });
        };

        // Listen to both Bootstrap 5 and CoreUI events
        finalizeModal.addEventListener('show.bs.modal', onFinalizeShow);
        finalizeModal.addEventListener('show.coreui.modal', onFinalizeShow);

        // Handle form submission
        const finalizeForm = document.getElementById('finalizeForm');
        if (finalizeForm) {
            finalizeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitFinalize(finalizeModal);
            });
        }

        // Real-time variance calculation
        const actualCashInput = document.getElementById('actualCashReceived');
        if (actualCashInput) {
            actualCashInput.addEventListener('input', function () {
                calculateVariance();
            });
        }
    }

    // Initialize standard close modal
    const closeModal = document.getElementById('closeModal');
    console.log('[POS Session] Close modal found:', !!closeModal);

    if (closeModal) {
        // Track the button that triggered the modal
        let closeModalTriggerButton = null;

        // Capture click events on close buttons to store the trigger button
        document.addEventListener('click', function(e) {
            if (e.target.closest('[data-bs-target="#closeModal"], [data-target="#closeModal"]')) {
                closeModalTriggerButton = e.target.closest('button');
            }
        });

        // Listen to both Bootstrap and CoreUI modal events for compatibility
        const onCloseShow = function (event) {
            console.log('[POS Session] Close modal showing', event.type);
            const button = event.relatedTarget || closeModalTriggerButton;
            const sessionId = button?.dataset?.sessionId;
            const form = document.getElementById('closeSessionForm');

            console.log('[POS Session] Close trigger', { sessionId, buttonDataset: button?.dataset });

            if (form) {
                form.dataset.sessionId = sessionId;
            }
        };

        // Listen to both Bootstrap 5 and CoreUI events
        closeModal.addEventListener('show.bs.modal', onCloseShow);
        closeModal.addEventListener('show.coreui.modal', onCloseShow);

        // Handle form submission
        const closeForm = document.getElementById('closeSessionForm');
        if (closeForm) {
            closeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitClose(closeModal);
            });
        }
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeModals);
} else {
    // DOM already loaded
    initializeModals();
}

/**
 * Fetch session data from summary endpoint
 */
function fetchSessionData(sessionId, callback) {
    console.log('[POS Session] Fetching session summary', { sessionId });

    fetch(`/pos/sessions/${sessionId}/summary`, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            console.log('[POS Session] Summary response', {
                status: response.status,
                statusText: response.statusText,
                contentType: response.headers.get('content-type')
            });

            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.message || `HTTP ${response.status}: ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('[POS Session] Summary data loaded', { sessionId });
            callback(data);
        })
        .catch(error => {
            console.error('[POS Session] Summary fetch error', error);
            showToast('Gagal mengambil data sesi', 'error');
        });
}

/**
 * Populate finalize modal with session data
 */
function populateFinalizeModal(session, fallbackSessionCode = NON_TERMINAL_LABEL) {
    document.getElementById('finalizeSessionCode').textContent = session.terminal?.code || fallbackSessionCode || NON_TERMINAL_LABEL;
    document.getElementById('finalizeCashierName').textContent = session.cashier?.name || '-';
    document.getElementById('finalizeOpeningFloat').textContent = formatCurrency(session.opening_float_total);

    // Calculate totals from checkouts
    const totalSales = calculateTotalSales(session.checkouts);
    const cashSales = calculateCashSales(session.checkouts);
    const safeDrops = calculateSafeDrops(session.cashEvents);

    document.getElementById('finalizeTotalSales').textContent = formatCurrency(totalSales);
    document.getElementById('finalizeCashSales').textContent = formatCurrency(cashSales);
    document.getElementById('finalizeCashSalesAmount').textContent = formatCurrency(cashSales);
    document.getElementById('finalizeSafeDrops').textContent = formatCurrency(safeDrops);

    // Calculate expected cash
    const expectedCash = calculateExpectedCash(session);
    document.getElementById('finalizeExpectedCash').textContent = formatCurrency(expectedCash);
    document.getElementById('finalizeVarianceThreshold').textContent = formatCurrency(session.terminal?.policy?.close_variance_approval_threshold || 0);

    // Store session ID and expected cash for calculations
    const form = document.getElementById('finalizeForm');
    form.dataset.sessionId = session.id;
    form.dataset.expectedCash = expectedCash;

    // Reset override section
    document.getElementById('supervisorOverrideSection').style.display = 'none';
    document.getElementById('overrideErrorAlert').style.display = 'none';
    document.getElementById('supervisorIdentifier').value = '';
    document.getElementById('supervisorPassword').value = '';

    // Reset form
    form.reset();
    calculateVariance();
}

/**
 * Calculate total sales from checkouts
 */
function calculateTotalSales(checkouts) {
    if (!Array.isArray(checkouts)) return 0;
    return checkouts.reduce((total, checkout) => {
        return total + (parseFloat(checkout.grand_total) || 0);
    }, 0);
}

/**
 * Calculate cash sales (only from cash payment method checkouts)
 */
function calculateCashSales(checkouts) {
    if (!Array.isArray(checkouts)) return 0;
    return checkouts.reduce((total, checkout) => {
        if (checkout.payment_method?.is_cash) {
            return total + (parseFloat(checkout.grand_total) || 0);
        }
        return total;
    }, 0);
}

/**
 * Calculate total safe drops (withdrawals)
 */
function calculateSafeDrops(cashEvents) {
    if (!Array.isArray(cashEvents)) return 0;
    return cashEvents.reduce((total, event) => {
        if (event.event_type === 'SAFE_DROP_OUT') {
            return total + (parseFloat(event.amount) || 0);
        }
        return total;
    }, 0);
}

/**
 * Calculate expected cash total
 */
function calculateExpectedCash(session) {
    const opening = parseFloat(session.opening_float_total) || 0;
    const cashSales = calculateCashSales(session.checkouts);
    const safeDrops = calculateSafeDrops(session.cashEvents);
    return opening + cashSales - safeDrops;
}

/**
 * Real-time variance calculation
 */
function calculateVariance() {
    const actualCash = parseFloat(document.getElementById('actualCashReceived').value) || 0;
    const form = document.getElementById('finalizeForm');
    const expectedCash = parseFloat(form.dataset.expectedCash) || 0;
    const variance = actualCash - expectedCash;

    const varianceElement = document.getElementById('finalizeVarianceAmount');
    const varianceWarning = document.getElementById('varianceWarning');
    const thresholdText = document.getElementById('finalizeVarianceThreshold').textContent;
    const threshold = parseFloat(thresholdText.replace(/[^0-9,.-]/g, '').replace(',', '.')) || 0;

    varianceElement.textContent = formatCurrency(variance);

    // Color code the variance
    if (Math.abs(variance) > threshold) {
        varianceElement.classList.remove('text-success');
        varianceElement.classList.add('text-danger');
        varianceWarning.style.display = 'block';
    } else {
        varianceElement.classList.remove('text-danger');
        varianceElement.classList.add('text-success');
        varianceWarning.style.display = 'none';
    }
}

/**
 * Submit standard close form
 */
function submitClose(modal) {
    const form = document.getElementById('closeSessionForm');
    const sessionId = form.dataset.sessionId;
    const reason = document.getElementById('close_reason').value;

    console.log('[POS Session] Closing session', {
        sessionId,
        reason: reason || '(none)',
        timestamp: new Date().toISOString()
    });

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Memproses...';

    const formData = new FormData();
    if (reason) formData.append('reason', reason);

    fetch(`/pos/sessions/${sessionId}/close`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: formData,
    })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.message || 'Gagal menutup sesi');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('[POS Session] Close successful', data);
            showToast('Sesi berhasil ditutup', 'success');
            // Hide modal using native Bootstrap/CoreUI API
            try {
                // Try Bootstrap 5 API first
                if (window.bootstrap && window.bootstrap.Modal) {
                    const bsModal = window.bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                } else {
                    // Fallback: dispatch a custom hide event
                    const hideEvent = new Event('hide.coreui.modal', { bubbles: true });
                    modal.dispatchEvent(hideEvent);
                }
            } catch (e) {
                console.error('[POS Session] Error hiding modal:', e);
            }
            // Reload table after short delay
            setTimeout(() => location.reload(), 1000);
        })
        .catch(error => {
            console.error('[POS Session] Close error', error);
            showToast(error.message || 'Gagal menutup sesi', 'error');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
}

/**
 * Submit finalize form
 */
function submitFinalize(modal) {
    const form = document.getElementById('finalizeForm');
    const sessionId = form.dataset.sessionId;
    const actualCash = document.getElementById('actualCashReceived').value;
    const notes = document.getElementById('finalizeNotes').value;

    // Override credentials (if visible)
    const supervisorSection = document.getElementById('supervisorOverrideSection');
    const supervisorIdentifier = document.getElementById('supervisorIdentifier').value;
    const supervisorPassword = document.getElementById('supervisorPassword').value;

    if (!actualCash) {
        console.warn('[POS Session] Finalize attempt without actual cash amount');
        showToast('Masukkan jumlah kas aktual', 'warning');
        return;
    }

    console.log('[POS Session] Finalizing session', {
        sessionId,
        actualCash: parseFloat(actualCash),
        timestamp: new Date().toISOString(),
        isOverride: supervisorSection.style.display !== 'none'
    });

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Memproses...';

    // Clear previous override error
    document.getElementById('overrideErrorAlert').style.display = 'none';

    const formData = new FormData();
    formData.append('actual_cash_received', parseFloat(actualCash));
    if (notes) formData.append('notes', notes);

    if (supervisorSection.style.display !== 'none') {
        if (!supervisorIdentifier || !supervisorPassword) {
            showToast('Kredensial supervisor wajib diisi untuk override', 'warning');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            return;
        }
        formData.append('supervisor_identifier', supervisorIdentifier);
        formData.append('supervisor_password', supervisorPassword);
    }

    fetch(`/pos/sessions/${sessionId}/finalize`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: formData,
    })
        .then(response => {
            console.log('[POS Session] Finalize response', {
                status: response.status,
                statusText: response.statusText,
                contentType: response.headers.get('content-type')
            });

            if (response.status === 422) {
                return response.json().then(data => {
                    if (data.requires_variance_approval) {
                        // Unhide override section
                        supervisorSection.style.display = 'block';
                        document.getElementById('supervisorIdentifier').focus();

                        throw new Error(
                            `Varians melebihi batas (${formatCurrency(data.variance_total)}). ` +
                            `Otorisasi supervisor diperlukan untuk melanjutkan.`
                        );
                    }

                    // Specific error for override attempt (DomainException from backend)
                    if (supervisorSection.style.display !== 'none' && data.message) {
                        const errorAlert = document.getElementById('overrideErrorAlert');
                        errorAlert.textContent = data.message;
                        errorAlert.style.display = 'block';
                        throw new Error(data.message);
                    }

                    throw new Error(data.message || 'Finalisasi gagal');
                });
            }
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.message || 'Finalisasi gagal');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('[POS Session] Finalize successful', data);
            showToast('Sesi berhasil ditfinalizekan', 'success');
            // Hide modal using native Bootstrap/CoreUI API
            try {
                // Try Bootstrap 5 API first
                if (window.bootstrap && window.bootstrap.Modal) {
                    const bsModal = window.bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                } else {
                    // Fallback: dispatch a custom hide event
                    const hideEvent = new Event('hide.coreui.modal', { bubbles: true });
                    modal.dispatchEvent(hideEvent);
                }
            } catch (e) {
                console.error('[POS Session] Error hiding modal:', e);
            }
            // Reload table after short delay
            setTimeout(() => location.reload(), 1000);
        })
        .catch(error => {
            console.error('[POS Session] Finalize error', error);
            // Don't show redundant toast if it's already shown in the alert or if it's a variance block
            if (supervisorSection.style.display === 'none' || !error.message.includes('Otorisasi supervisor')) {
                showToast(error.message || 'Gagal menfinalisasi sesi', 'error');
            }
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
}

/**
 * Helper function to show toast notifications
 */
function showToast(message, type = 'info') {
    // Check if there's a toast container, if not create one
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.style.position = 'fixed';
        toastContainer.style.top = '20px';
        toastContainer.style.right = '20px';
        toastContainer.style.zIndex = '9999';
        document.body.appendChild(toastContainer);
    }

    const alertClass = {
        'success': 'alert-success',
        'error': 'alert-danger',
        'warning': 'alert-warning',
        'info': 'alert-info',
    }[type] || 'alert-info';

    const toast = document.createElement('div');
    toast.className = `alert ${alertClass} alert-dismissible fade show`;
    toast.role = 'alert';
    toast.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;

    toastContainer.appendChild(toast);

    // Auto dismiss after 5 seconds
    setTimeout(() => {
        const bsAlert = new bootstrap.Alert(toast);
        bsAlert.close();
    }, 5000);
}

/**
 * Format currency value
 */
function formatCurrency(value) {
    const number = parseFloat(value) || 0;
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(number);
}

/**
 * Format date and time
 */
function formatDateTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('id-ID', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

/**
 * Calculate duration between two dates
 */
function calculateDuration(startDate, endDate) {
    const diffMs = endDate - startDate;
    const diffMins = Math.floor(diffMs / 60000);
    const hours = Math.floor(diffMins / 60);
    const mins = diffMins % 60;

    if (hours === 0) return `${mins} menit`;
    if (mins === 0) return `${hours} jam`;
    return `${hours} jam ${mins} menit`;
}
