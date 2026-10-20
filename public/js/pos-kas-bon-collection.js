/**
 * POS Kas Bon Debt Collection Module
 * Handles candidate discovery, current-bill review, isolated payment state, and printable receipt.
 */

window.PosKasBonCollection = (function () {
    'use strict';

    // State
    let currentPage = 1;
    let lastPage = 1;
    let currentSearch = '';
    let searchDebounceTimer = null;
    let currentTransactionId = null;
    let currentCandidate = null;
    let currentReview = null;
    let availableMethods = [];
    let selectedMethods = [];
    let amountToPay = 0;
    let lastReceiptUrl = null;
    let isSubmitting = false;
    let currentAttemptIdempotencyKey = null;

    let methodRowSequence = 0;
    function generateClientId() {
        return 'm_row_' + (++methodRowSequence) + '_' + Date.now();
    }

    let uploadRequestSequence = 0;
    const pendingUploads = new Map(); // uploadId -> { controller, clientId, trxId }
    const activeUploadByClient = new Map(); // clientId -> uploadId

    function hasPendingUploads() {
        return pendingUploads.size > 0;
    }

    function startUploadRequest(clientId, trxId, controller) {
        // If an upload was already in flight for this method row, abort and remove it
        if (activeUploadByClient.has(clientId)) {
            const prevUploadId = activeUploadByClient.get(clientId);
            cancelUploadRequest(prevUploadId);
        }

        const uploadId = 'up_' + (++uploadRequestSequence) + '_' + Date.now();
        pendingUploads.set(uploadId, { controller, clientId, trxId });
        activeUploadByClient.set(clientId, uploadId);
        return uploadId;
    }

    function cancelUploadRequest(uploadId) {
        if (pendingUploads.has(uploadId)) {
            const entry = pendingUploads.get(uploadId);
            pendingUploads.delete(uploadId);
            if (activeUploadByClient.get(entry.clientId) === uploadId) {
                activeUploadByClient.delete(entry.clientId);
            }
            try { entry.controller.abort(); } catch (_) {}
        }
    }

    function finishUploadRequest(uploadId) {
        if (pendingUploads.has(uploadId)) {
            const entry = pendingUploads.get(uploadId);
            pendingUploads.delete(uploadId);
            if (activeUploadByClient.get(entry.clientId) === uploadId) {
                activeUploadByClient.delete(entry.clientId);
            }
        }
    }

    function abortUploadsForClient(clientId) {
        for (const [uploadId, entry] of pendingUploads.entries()) {
            if (entry.clientId === clientId) {
                cancelUploadRequest(uploadId);
            }
        }
        activeUploadByClient.delete(clientId);
    }

    function cleanupUploadedImage(token, cartToken) {
        if (!token) return;
        fetch('/pos/sell/payment-image', {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                token: token,
                cart_token: cartToken
            })
        }).catch(err => console.error('Error cleaning up uploaded image:', err));
    }

    function abortAllPendingUploads() {
        for (const [uploadId, entry] of pendingUploads.entries()) {
            try { entry.controller.abort(); } catch (_) {}
        }
        pendingUploads.clear();
        activeUploadByClient.clear();
    }

    // Helper: Format Rupiah with 2-decimal support
    function formatRp(val) {
        if (val === null || val === undefined || val === '') return 'Rp0';
        const num = Number(val);
        if (isNaN(num)) return 'Rp0';

        if (window.FinancialInput && typeof window.FinancialInput.formatDisplay === 'function') {
            const hasFraction = Math.abs(num - Math.round(num)) > 0.0001;
            const canonicalStr = hasFraction ? num.toFixed(2) : String(Math.round(num));
            return 'Rp' + window.FinancialInput.formatDisplay(canonicalStr);
        }

        const hasFraction = Math.abs(num - Math.round(num)) > 0.0001;
        const parts = (hasFraction ? num.toFixed(2) : String(Math.round(num))).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return 'Rp' + parts.join(',');
    }

    // Helper: Convert numeric value to canonical decimal string for FinancialInput
    function toCanonicalAmount(val) {
        if (val === null || val === undefined || val === '') return '';
        const num = Number(val);
        if (isNaN(num)) return '';
        if (num <= 0) return '';
        return Math.abs(num - Math.round(num)) > 0.0001 ? num.toFixed(2) : String(Math.round(num));
    }

    // Helper: Format raw numeric string for display in inputs
    function formatDisplayAmount(val) {
        if (val === null || val === undefined || val === '') return '';
        const num = Number(val);
        if (isNaN(num)) return '';
        if (window.FinancialInput && typeof window.FinancialInput.formatDisplay === 'function') {
            const hasFraction = Math.abs(num - Math.round(num)) > 0.0001;
            const canonicalStr = hasFraction ? num.toFixed(2) : String(Math.round(num));
            return window.FinancialInput.formatDisplay(canonicalStr);
        }
        const hasFraction = Math.abs(num - Math.round(num)) > 0.0001;
        const parts = (hasFraction ? num.toFixed(2) : String(Math.round(num))).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return parts.join(',');
    }

    // Helper: Parse Rupiah / numeric string or input element to float (retaining decimals)
    function parseRp(val) {
        if (typeof val === 'number') return val;
        if (!val) return 0;
        if ((typeof HTMLElement !== 'undefined' && val instanceof HTMLElement) || (val && typeof val === 'object' && 'tagName' in val)) {
            if (window.FinancialInput && typeof window.FinancialInput.getCanonicalValue === 'function') {
                const canonical = window.FinancialInput.getCanonicalValue(val);
                if (canonical !== null && canonical !== '') {
                    return parseFloat(canonical) || 0;
                }
            }
            val = val.value;
        }
        const str = val.toString().trim();
        // If both '.' and ',' exist, '.' is thousands, ',' is decimal (e.g. 1.000,50)
        if (str.includes('.') && str.includes(',')) {
            const normalized = str.replace(/\./g, '').replace(',', '.').replace(/[^0-9.-]/g, '');
            return parseFloat(normalized) || 0;
        }
        // If only ',' exists, it is decimal separator (e.g. 1000,50)
        if (str.includes(',')) {
            const normalized = str.replace(',', '.').replace(/[^0-9.-]/g, '');
            return parseFloat(normalized) || 0;
        }
        // If only '.' exists:
        if (str.includes('.')) {
            const dotParts = str.split('.');
            if (dotParts.length > 2) {
                return parseFloat(str.replace(/\./g, '').replace(/[^0-9.-]/g, '')) || 0;
            }
            if (dotParts.length === 2 && dotParts[1].length === 3) {
                return parseFloat(str.replace(/\./g, '').replace(/[^0-9.-]/g, '')) || 0;
            }
            return parseFloat(str.replace(/[^0-9.-]/g, '')) || 0;
        }
        const cleaned = str.replace(/[^0-9.-]/g, '');
        return parseFloat(cleaned) || 0;
    }

    // CSRF token
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Fetch JSON with CSRF
    async function apiRequest(url, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = getCsrfToken();
            if (data) {
                options.body = JSON.stringify(data);
            }
        }

        const res = await fetch(url, options);
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            const err = new Error(json.message || 'Permintaan gagal diproses.');
            err.status = res.status;
            err.errors = json.errors || {};
            throw err;
        }
        return json;
    }

    // Switch view in modal
    function showView(viewName) {
        const views = {
            'candidates': document.getElementById('pos-kb-candidates-view'),
            'review': document.getElementById('pos-kb-review-view'),
            'success': document.getElementById('pos-kb-success-view'),
        };

        for (const [key, el] of Object.entries(views)) {
            if (el) {
                if (key === viewName) {
                    el.classList.remove('d-none');
                } else {
                    el.classList.add('d-none');
                }
            }
        }

        const footer = document.getElementById('pos-kb-modal-footer');
        if (footer) {
            if (viewName === 'success') {
                footer.classList.add('d-none');
            } else {
                footer.classList.remove('d-none');
            }
        }
    }

    // Reset module state
    function resetState() {
        abortAllPendingUploads();
        currentTransactionId = null;
        currentCandidate = null;
        currentReview = null;
        selectedMethods = [];
        amountToPay = 0;
        lastReceiptUrl = null;
        isSubmitting = false;
        currentAttemptIdempotencyKey = null;

        const globalAlert = document.getElementById('pos-kb-global-alert');
        if (globalAlert) {
            globalAlert.classList.add('d-none');
            globalAlert.innerText = '';
        }

        const reviewAlert = document.getElementById('pos-kb-review-error');
        if (reviewAlert) {
            reviewAlert.classList.add('d-none');
        }
    }

    // Open modal
    async function openModal() {
        resetState();
        showView('candidates');
        $('#pos-kas-bon-modal').modal('show');
        await loadPaymentMethods();
        await loadCandidates(1);
    }

    // Load available payment methods (non-debt only)
    async function loadPaymentMethods() {
        if (availableMethods.length > 0) return;
        try {
            const res = await apiRequest('/pos/kas-bon/payment-methods');
            if (res && res.methods) {
                availableMethods = res.methods;
                populateMethodSelect();
            }
        } catch (e) {
            console.error('Error loading payment methods:', e);
        }
    }

    function populateMethodSelect() {
        const select = document.getElementById('pos-kb-method-select');
        if (!select) return;
        select.innerHTML = '<option value="">-- Pilih Metode Pembayaran --</option>';
        availableMethods.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.name + (m.is_cash ? ' (Tunai)' : '');
            opt.dataset.isCash = m.is_cash ? '1' : '0';
            opt.dataset.requiresReference = m.requires_reference ? '1' : '0';
            select.appendChild(opt);
        });
    }

    // Load candidates with pagination & search
    async function loadCandidates(page = 1) {
        const tbody = document.getElementById('pos-kb-candidates-body');
        const countInfo = document.getElementById('pos-kb-candidate-count');
        const prevBtn = document.getElementById('pos-kb-prev-page');
        const nextBtn = document.getElementById('pos-kb-next-page');
        const pageInfo = document.getElementById('pos-kb-pagination-info');

        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary mr-1" role="status"></div> Memuat daftar Kas Bon...</td></tr>`;
        }

        try {
            const searchVal = document.getElementById('pos-kb-search-input')?.value.trim() || '';
            currentSearch = searchVal;
            const params = new URLSearchParams({
                page: page,
                per_page: 10,
            });
            if (searchVal) {
                params.append('search', searchVal);
            }

            const res = await apiRequest(`/pos/kas-bon/candidates?${params.toString()}`);
            currentPage = res.current_page || 1;
            lastPage = res.last_page || 1;

            if (countInfo) {
                countInfo.textContent = `Total: ${res.total || 0} transaksi`;
            }
            if (pageInfo) {
                pageInfo.textContent = `Halaman ${currentPage} dari ${lastPage} (${res.total || 0} total)`;
            }

            if (prevBtn) prevBtn.disabled = (currentPage <= 1);
            if (nextBtn) nextBtn.disabled = (currentPage >= lastPage);

            renderCandidates(res.data || []);
        } catch (e) {
            console.error('Error loading candidates:', e);
            if (tbody) {
                tbody.innerHTML = `<tr><td colspan="8" class="text-center py-3 text-danger">Gagal memuat data: ${e.message}</td></tr>`;
            }
        }
    }

    function renderCandidates(items) {
        const tbody = document.getElementById('pos-kb-candidates-body');
        if (!tbody) return;

        if (!items || items.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada transaksi Kas Bon yang memerlukan pembayaran.</td></tr>`;
            return;
        }

        tbody.innerHTML = '';
        items.forEach(item => {
            const trxId = item.pos_transaction_id || item.id;
            const alreadyPaid = item.effective_paid ?? item.already_paid ?? 0;
            const remainingDebt = item.live_due ?? item.remaining_debt ?? 0;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="font-weight-bold">${escapeHtml(item.code || '-')}</td>
                <td>${escapeHtml(item.customer_name || '-')}</td>
                <td class="text-muted small">${escapeHtml(item.notes || '-')}</td>
                <td class="text-right">${formatRp(item.original_total)}</td>
                <td class="text-right font-weight-bold">${formatRp(item.current_total)}</td>
                <td class="text-right text-success">${formatRp(alreadyPaid)}</td>
                <td class="text-right text-danger font-weight-bold">${formatRp(remainingDebt)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-warning font-weight-bold js-kb-select-btn" data-id="${trxId}">
                        Pilih <i class="bi bi-chevron-right"></i>
                    </button>
                </td>
            `;

            tr.querySelector('.js-kb-select-btn')?.addEventListener('click', () => {
                selectCandidate(trxId);
            });

            tbody.appendChild(tr);
        });
    }

    // Select candidate and load review
    async function selectCandidate(transactionId) {
        abortAllPendingUploads();
        currentTransactionId = transactionId;
        showView('review');

        const reviewError = document.getElementById('pos-kb-review-error');
        if (reviewError) reviewError.classList.add('d-none');

        await loadReview(transactionId);
    }

    // Load review
    async function loadReview(transactionId) {
        if (transactionId) currentTransactionId = transactionId;
        try {
            const res = await apiRequest(`/pos/kas-bon/${transactionId}/review`);
            currentReview = res.data;
            renderReviewData(currentReview);
        } catch (e) {
            console.error('Error loading review:', e);
            showReviewError(e.message || 'Gagal memuat rincian tagihan.');
        }
    }

    function showReviewError(msg) {
        const reviewError = document.getElementById('pos-kb-review-error');
        const errorMsg = document.getElementById('pos-kb-review-error-msg');
        if (reviewError && errorMsg) {
            errorMsg.textContent = msg;
            reviewError.classList.remove('d-none');
        }
    }

    function renderReviewData(data) {
        document.getElementById('pos-kb-review-trx-code').textContent = data.code || `TRX #${currentTransactionId}`;
        document.getElementById('pos-kb-detail-customer').textContent = data.customer?.customer_name || data.customer?.name || '-';
        document.getElementById('pos-kb-detail-receipt').textContent = data.receipt_number || '-';
        document.getElementById('pos-kb-detail-note').textContent = data.notes || '-';

        // Render Items table
        const tbody = document.getElementById('pos-kb-items-body');
        if (tbody) {
            tbody.innerHTML = '';
            const lines = data.lines || [];
            if (lines.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Tidak ada rincian item.</td></tr>';
            } else {
                lines.forEach(l => {
                    let adjustmentHtml = '';
                    if (l.adjustments && Array.isArray(l.adjustments) && l.adjustments.length > 0) {
                        adjustmentHtml = `<div class="small text-warning mt-1"><i class="bi bi-info-circle mr-1"></i>${l.adjustments.map(a => escapeHtml(a)).join('; ')}</div>`;
                    } else if (typeof l.adjustments === 'string' && l.adjustments) {
                        adjustmentHtml = `<div class="small text-warning mt-1"><i class="bi bi-info-circle mr-1"></i>${escapeHtml(l.adjustments)}</div>`;
                    }

                    const qty = l.quantity ?? l.qty ?? 0;
                    const unitPrice = l.unit_price ?? l.price ?? 0;
                    const subtotal = l.subtotal ?? l.sub_total ?? 0;
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div>${escapeHtml(l.product_name || '-')}</div>
                            ${adjustmentHtml}
                        </td>
                        <td class="text-right">${qty}</td>
                        <td class="text-right">${formatRp(unitPrice)}</td>
                        <td class="text-right text-muted">${l.discount_amount > 0 ? '-' + formatRp(l.discount_amount) : '-'}</td>
                        <td class="text-right font-weight-bold">${formatRp(subtotal)}</td>
                    `;
                    tbody.appendChild(row);
                });
            }
        }

        // Totals
        document.getElementById('pos-kb-summary-total').textContent = formatRp(data.current_total || 0);
        document.getElementById('pos-kb-summary-paid').textContent = formatRp(data.effective_paid || 0);
        document.getElementById('pos-kb-summary-due').textContent = formatRp(data.live_due || 0);

        // Reset payment form with default = live_due
        const liveDue = Number(data.live_due) || 0;
        amountToPay = liveDue;
        currentAttemptIdempotencyKey = null;

        // Initialize default method (Tunai if available)
        selectedMethods = [];
        const cashMethod = availableMethods.find(m => m.is_cash);
        if (cashMethod && liveDue > 0) {
            selectedMethods.push({
                client_id: generateClientId(),
                payment_method_id: cashMethod.id,
                name: cashMethod.name,
                is_cash: true,
                requires_reference: !!cashMethod.requires_reference,
                amount: liveDue,
                tender_amount: liveDue,
                note: '',
                reference: '',
            });
        }
        renderMethodsTable();
        calculatePaymentSummary();
    }

    // Sync current DOM inputs to selectedMethods state before re-rendering or modifying methods
    function syncCurrentDomToState() {
        const tbody = document.getElementById('pos-kb-methods-body');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr[data-client-id]');
        rows.forEach(tr => {
            const clientId = tr.getAttribute('data-client-id');
            const m = selectedMethods.find(item => item.client_id === clientId);
            if (!m) return;

            const amtInput = tr.querySelector('.js-method-amount');
            if (amtInput) {
                m.amount = parseRp(amtInput);
                m.tender_amount = m.amount;
            }

            const noteInput = tr.querySelector('.js-method-note');
            if (noteInput) {
                m.note = noteInput.value;
            }

            const refInput = tr.querySelector('.js-method-reference');
            if (refInput) {
                m.reference = refInput.value;
            }
        });
    }

    let isRenderingMethods = false;

    // Render selected methods
    function renderMethodsTable() {
        const tbody = document.getElementById('pos-kb-methods-body');
        if (!tbody) return;

        isRenderingMethods = true;
        try {
            tbody.innerHTML = '';
            if (selectedMethods.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center py-2 text-muted small">Belum ada metode pembayaran ditambahkan.</td></tr>`;
                return;
            }

            selectedMethods.forEach((m) => {
                const tr = document.createElement('tr');
                tr.setAttribute('data-client-id', m.client_id || '');
                const initialAmtCanonical = toCanonicalAmount(m.amount);

                tr.innerHTML = `
                    <td class="align-middle">
                        <strong>${escapeHtml(m.name)}</strong>
                        ${m.is_cash ? '<span class="badge badge-success ml-1">Tunai</span>' : ''}
                    </td>
                    <td class="align-middle">
                        <input type="text" class="form-control form-control-sm js-method-amount font-weight-bold" 
                               data-client-id="${m.client_id}" value="${initialAmtCanonical}" inputmode="numeric" data-financial-amount>
                    </td>
                    <td class="align-middle">
                        ${m.is_cash ? `
                            <input type="text" class="form-control form-control-sm js-method-note" 
                                   data-client-id="${m.client_id}" value="${escapeHtml(m.note || '')}" placeholder="Keterangan">
                        ` : `
                            <input type="text" class="form-control form-control-sm js-method-note mb-1" 
                                   data-client-id="${m.client_id}" value="${escapeHtml(m.note || '')}" placeholder="Keterangan">
                            <input type="text" class="form-control form-control-sm js-method-reference mb-1" 
                                   data-client-id="${m.client_id}" value="${escapeHtml(m.reference || '')}" 
                                   placeholder="Nomor Referensi${m.requires_reference ? ' *' : ''}">
                            <div class="method-attachment-control" data-client-id="${m.client_id}">
                                ${m.attachment_token ? `
                                    <div class="d-flex align-items-center justify-content-between p-1 bg-light border rounded small" style="font-size: 11px;">
                                        <span class="text-truncate text-success font-weight-bold mr-1" title="${escapeHtml(m.attachment_name || 'Bukti')}">
                                            <i class="bi bi-file-earmark-image mr-1"></i>${escapeHtml(m.attachment_name || 'Bukti')}
                                        </span>
                                        <button type="button" class="btn btn-xs text-danger p-0 js-btn-remove-attachment" data-client-id="${m.client_id}" title="Hapus Bukti">
                                            <i class="bi bi-x-circle-fill"></i>
                                        </button>
                                    </div>
                                ` : `
                                    <div class="d-flex align-items-center">
                                        <input type="file" class="d-none js-method-file-input" data-client-id="${m.client_id}" accept="image/jpeg,image/png,image/jpg">
                                        <button type="button" class="btn btn-xs btn-outline-secondary js-btn-upload-attachment" data-client-id="${m.client_id}" style="font-size: 11px; padding: 2px 6px;">
                                            <i class="bi bi-paperclip mr-1"></i>+ Bukti Foto
                                        </button>
                                        <span class="small text-muted ml-1 js-attachment-status" data-client-id="${m.client_id}" style="font-size: 11px;"></span>
                                    </div>
                                `}
                            </div>
                        `}
                    </td>
                    <td class="align-middle text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger js-remove-method" data-client-id="${m.client_id}" title="Hapus">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </td>
                `;

                // Amount input listener
                const amtInput = tr.querySelector('.js-method-amount');
                const handleAmtChange = (e) => {
                    if (isRenderingMethods) return;
                    currentAttemptIdempotencyKey = null;
                    const parsed = parseRp(e.target);
                    m.amount = parsed;
                    m.tender_amount = parsed;
                    calculatePaymentSummary();
                };
                amtInput?.addEventListener('input', handleAmtChange);
                amtInput?.addEventListener('financial-amount:change', handleAmtChange);
                amtInput?.addEventListener('blur', () => {
                    if (isRenderingMethods) return;
                    calculatePaymentSummary();
                });

                // Note input listener (free text for every method)
                const noteInput = tr.querySelector('.js-method-note');
                noteInput?.addEventListener('input', (e) => {
                    if (isRenderingMethods) return;
                    currentAttemptIdempotencyKey = null;
                    m.note = e.target.value;
                });

                // Reference input listener (separate text input for non-cash)
                const refInput = tr.querySelector('.js-method-reference');
                refInput?.addEventListener('input', (e) => {
                    if (isRenderingMethods) return;
                    currentAttemptIdempotencyKey = null;
                    m.reference = e.target.value;
                    calculatePaymentSummary();
                });

                // Upload attachment click
                tr.querySelector('.js-btn-upload-attachment')?.addEventListener('click', () => {
                    tr.querySelector('.js-method-file-input')?.click();
                });

                // Attachment file input change
                tr.querySelector('.js-method-file-input')?.addEventListener('change', async (e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;

                    if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
                        alert('Hanya gambar JPEG atau PNG yang diperbolehkan.');
                        e.target.value = '';
                        return;
                    }

                    if (file.size > 5 * 1024 * 1024) {
                        alert('Ukuran gambar tidak boleh melebihi 5 MB.');
                        e.target.value = '';
                        return;
                    }

                    const targetClientId = m.client_id;
                    const targetTrxId = currentTransactionId;
                    const abortController = new AbortController();
                    const currentUploadId = startUploadRequest(targetClientId, targetTrxId, abortController);
                    calculatePaymentSummary();

                    const statusEl = tr.querySelector('.js-attachment-status');
                    if (statusEl) statusEl.textContent = 'Mengunggah...';

                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('cart_token', 'kas-bon-' + (targetTrxId || 'payment'));

                    try {
                        const response = await fetch('/pos/sell/payment-image', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': getCsrfToken(),
                                'Accept': 'application/json'
                            },
                            body: formData,
                            signal: abortController.signal
                        });

                        const data = await response.json();
                        if (!response.ok) {
                            throw new Error(data.message || 'Gagal mengunggah bukti pembayaran.');
                        }

                        // Check if superseded by another upload on this row or if transaction switched
                        if (targetTrxId !== currentTransactionId || activeUploadByClient.get(targetClientId) !== currentUploadId) {
                            if (data.token) {
                                cleanupUploadedImage(data.token, 'kas-bon-' + targetTrxId);
                            }
                            return;
                        }

                        // Check if method row was removed while uploading
                        const targetMethod = selectedMethods.find(item => item.client_id === targetClientId);
                        if (!targetMethod) {
                            if (data.token) {
                                cleanupUploadedImage(data.token, 'kas-bon-' + targetTrxId);
                            }
                            return;
                        }

                        syncCurrentDomToState();
                        targetMethod.attachment = data.token;
                        targetMethod.attachment_token = data.token;
                        targetMethod.attachment_name = data.original_name || file.name;
                        currentAttemptIdempotencyKey = null;
                        renderMethodsTable();
                    } catch (uploadErr) {
                        if (uploadErr.name === 'AbortError') {
                            return;
                        }
                        console.error('Upload error:', uploadErr);
                        alert(uploadErr.message || 'Gagal mengunggah bukti pembayaran.');
                        if (statusEl) statusEl.textContent = '';
                    } finally {
                        finishUploadRequest(currentUploadId);
                        calculatePaymentSummary();
                    }
                });

                // Remove attachment
                tr.querySelector('.js-btn-remove-attachment')?.addEventListener('click', async () => {
                    syncCurrentDomToState();
                    const targetClientId = m.client_id;
                    const currentMethod = selectedMethods.find(item => item.client_id === targetClientId) || m;
                    const tokenToDelete = currentMethod.attachment_token;
                    delete currentMethod.attachment;
                    delete currentMethod.attachment_token;
                    delete currentMethod.attachment_name;
                    currentAttemptIdempotencyKey = null;
                    renderMethodsTable();

                    if (tokenToDelete) {
                        cleanupUploadedImage(tokenToDelete, 'kas-bon-' + (currentTransactionId || 'payment'));
                    }
                });

                // Remove method listener
                tr.querySelector('.js-remove-method')?.addEventListener('click', () => {
                    syncCurrentDomToState();
                    currentAttemptIdempotencyKey = null;
                    const targetClientId = m.client_id;

                    abortUploadsForClient(targetClientId);

                    if (m.attachment_token) {
                        cleanupUploadedImage(m.attachment_token, 'kas-bon-' + (currentTransactionId || 'payment'));
                    }

                    selectedMethods = selectedMethods.filter(item => item.client_id !== targetClientId);
                    renderMethodsTable();
                });

                tbody.appendChild(tr);
            });

            // Initialize / enhance FinancialInput on rendered rows
            if (window.FinancialInput && typeof window.FinancialInput.init === 'function') {
                window.FinancialInput.init(tbody);
                // Explicitly set canonical values on enhanced elements so display and internal canonical state match exactly
                selectedMethods.forEach(m => {
                    const tr = tbody.querySelector(`tr[data-client-id="${m.client_id}"]`);
                    if (!tr) return;
                    const amtInput = tr.querySelector('.js-method-amount');
                    if (amtInput && typeof window.FinancialInput.setCanonicalValue === 'function') {
                        window.FinancialInput.setCanonicalValue(amtInput, toCanonicalAmount(m.amount));
                    }
                });
            } else {
                // Fallback for environment without FinancialInput
                selectedMethods.forEach(m => {
                    const tr = tbody.querySelector(`tr[data-client-id="${m.client_id}"]`);
                    if (!tr) return;
                    const amtInput = tr.querySelector('.js-method-amount');
                    if (amtInput) amtInput.value = formatDisplayAmount(m.amount);
                });
            }
        } finally {
            isRenderingMethods = false;
        }

        calculatePaymentSummary();
    }

    // Real-time payment calculation & button state
    function calculatePaymentSummary() {
        let derivedPaymentAmount = 0;

        selectedMethods.forEach(m => {
            const amt = Number(m.amount) || 0;
            derivedPaymentAmount += amt;
        });

        amountToPay = Math.round(derivedPaymentAmount * 100) / 100;
        const liveDue = Number(currentReview?.live_due) || 0;
        const remainingDebt = Math.max(0, Math.round((liveDue - amountToPay) * 100) / 100);

        const totalPaymentEl = document.getElementById('pos-kb-calc-total-payment');
        if (totalPaymentEl) totalPaymentEl.textContent = formatRp(amountToPay);

        const remainingDebtEl = document.getElementById('pos-kb-calc-remaining-debt');
        if (remainingDebtEl) {
            if (amountToPay > (liveDue + 0.005)) {
                remainingDebtEl.textContent = formatRp(0) + ' (Melebihi hutang)';
                remainingDebtEl.className = 'text-danger font-weight-bold';
            } else {
                remainingDebtEl.textContent = formatRp(remainingDebt);
                remainingDebtEl.className = 'font-weight-bold';
            }
        }

        const cashTenderEl = document.getElementById('pos-kb-calc-cash-tender');
        if (cashTenderEl) cashTenderEl.textContent = formatRp(0);

        const changeEl = document.getElementById('pos-kb-calc-change');
        if (changeEl) changeEl.textContent = formatRp(0);

        // Validation for submit button
        const submitBtn = document.getElementById('pos-kb-submit-payment');
        let isValid = true;

        if (amountToPay <= 0.005 || amountToPay > (liveDue + 0.005)) {
            isValid = false;
        }
        if (selectedMethods.length === 0) {
            isValid = false;
        }
        selectedMethods.forEach(m => {
            const amt = Number(m.amount) || 0;
            if (amt <= 0.005) {
                isValid = false;
            }
            if (m.requires_reference && (!m.reference || !m.reference.trim())) {
                isValid = false;
            }
        });

        if (submitBtn) {
            if (hasPendingUploads()) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Mengunggah Bukti...';
            } else if (isSubmitting) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Memproses Pembayaran...';
            } else {
                submitBtn.disabled = !isValid;
                if (!submitBtn.innerHTML.includes('Coba Lagi')) {
                    submitBtn.innerHTML = '<i class="bi bi-check-circle mr-1"></i> Proses Pembayaran Kas Bon';
                }
            }
        }
    }

    // Submit payment
    async function submitPayment() {
        if (isSubmitting) return;
        if (hasPendingUploads()) {
            alert('Harap tunggu hingga proses unggah bukti pembayaran selesai.');
            return;
        }

        syncCurrentDomToState();
        calculatePaymentSummary();

        const globalAlert = document.getElementById('pos-kb-global-alert');
        if (globalAlert) globalAlert.classList.add('d-none');

        const submitBtn = document.getElementById('pos-kb-submit-payment');
        isSubmitting = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Memproses Pembayaran...';
        }

        try {
            if (!currentAttemptIdempotencyKey) {
                currentAttemptIdempotencyKey = 'kb-col-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
            }
            const methodsPayload = selectedMethods.map(m => ({
                payment_method_id: m.payment_method_id,
                amount: m.amount,
                tender_amount: m.amount,
                note: m.note ? m.note.trim() : null,
                reference: m.reference ? m.reference.trim() : null,
                attachment: m.attachment || m.attachment_token || null,
            }));

            const payload = {
                fingerprint: currentReview.fingerprint,
                amount_to_pay: amountToPay,
                methods: methodsPayload,
                idempotency_key: currentAttemptIdempotencyKey,
                note: document.getElementById('pos-kb-input-note')?.value.trim() || null,
            };

            const res = await apiRequest(`/pos/kas-bon/${currentTransactionId}/collect`, 'POST', payload);

            // Payment succeeded!
            currentAttemptIdempotencyKey = null;
            showSuccessView(res);
        } catch (err) {
            console.error('Settlement error:', err);
            // Check if fingerprint or stale error
            if (err.message && (err.message.includes('fingerprint') || err.message.includes('kedaluwarsa') || err.message.includes('berubah'))) {
                currentAttemptIdempotencyKey = null;
                showReviewError(err.message + ' Silakan muat ulang tagihan.');
            } else {
                if (globalAlert) {
                    globalAlert.textContent = err.message || 'Terjadi kesalahan saat memproses pembayaran.';
                    globalAlert.classList.remove('d-none');
                }
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="bi bi-arrow-repeat mr-1"></i> Coba Lagi Pembayaran';
                }
            }
        } finally {
            isSubmitting = false;
            if (submitBtn && !submitBtn.innerHTML.includes('Coba Lagi')) {
                submitBtn.innerHTML = '<i class="bi bi-check-circle mr-1"></i> Proses Pembayaran Kas Bon';
            }
            calculatePaymentSummary();
        }
    }

    // Show success view
    function showSuccessView(res) {
        showView('success');
        lastReceiptUrl = res.receipt_url;

        document.getElementById('pos-kb-res-ref').textContent = res.reference || '-';
        document.getElementById('pos-kb-res-amount').textContent = formatRp(res.amount_collected);
        
        const changeRow = document.getElementById('pos-kb-res-change-row');
        if (res.change_total > 0) {
            if (changeRow) changeRow.classList.remove('d-none');
            document.getElementById('pos-kb-res-change').textContent = formatRp(res.change_total);
        } else {
            if (changeRow) changeRow.classList.add('d-none');
        }

        document.getElementById('pos-kb-res-remaining').textContent = formatRp(res.remaining_debt);

        // Dispatch collection event for session balance observers
        window.dispatchEvent(new CustomEvent('pos:kas-bon-collected', {
            detail: res
        }));
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Attach listeners on DOM ready
    function init() {
        // Open modal buttons
        document.getElementById('pos-btn-bayar-kas-bon')?.addEventListener('click', openModal);
        document.getElementById('pos-menu-bayar-kas-bon')?.addEventListener('click', openModal);

        // Search input
        const searchInput = document.getElementById('pos-kb-search-input');
        searchInput?.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                loadCandidates(1);
            }, 300);
        });

        // Search clear
        document.getElementById('pos-kb-search-clear')?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            loadCandidates(1);
        });

        // Refresh candidates
        document.getElementById('pos-kb-refresh-candidates')?.addEventListener('click', () => {
            loadCandidates(currentPage);
        });

        // Pagination buttons
        document.getElementById('pos-kb-prev-page')?.addEventListener('click', () => {
            if (currentPage > 1) loadCandidates(currentPage - 1);
        });
        document.getElementById('pos-kb-next-page')?.addEventListener('click', () => {
            if (currentPage < lastPage) loadCandidates(currentPage + 1);
        });

        // Back to candidates button
        document.getElementById('pos-kb-back-to-candidates')?.addEventListener('click', () => {
            showView('candidates');
            loadCandidates(currentPage);
        });

        // Reload review button
        document.getElementById('pos-kb-reload-review')?.addEventListener('click', () => {
            if (currentTransactionId) loadReview(currentTransactionId);
        });
        document.getElementById('pos-kb-review-error-refresh')?.addEventListener('click', () => {
            if (currentTransactionId) loadReview(currentTransactionId);
        });

        // Note input listener
        document.getElementById('pos-kb-input-note')?.addEventListener('input', () => {
            currentAttemptIdempotencyKey = null;
        });

        // Add method button
        document.getElementById('pos-kb-add-method-btn')?.addEventListener('click', () => {
            currentAttemptIdempotencyKey = null;
            const select = document.getElementById('pos-kb-method-select');
            if (!select || !select.value) return;

            const methodId = parseInt(select.value, 10);
            const found = availableMethods.find(m => m.id === methodId);
            if (!found) return;

            // Check if already added
            if (selectedMethods.some(m => m.payment_method_id === methodId)) {
                alert('Metode ini sudah ditambahkan.');
                return;
            }

            // Sync current inputs from DOM to state FIRST
            syncCurrentDomToState();

            // Calculate unallocated amount based on live due
            const liveDue = Number(currentReview?.live_due) || 0;
            let currentAllocated = selectedMethods.reduce((sum, m) => sum + (Number(m.amount) || 0), 0);
            let needed = Math.max(0, Math.round((liveDue - currentAllocated) * 100) / 100);

            selectedMethods.push({
                client_id: generateClientId(),
                payment_method_id: found.id,
                name: found.name,
                is_cash: !!found.is_cash,
                requires_reference: !!found.requires_reference,
                amount: needed,
                tender_amount: needed,
                note: '',
                reference: '',
            });

            renderMethodsTable();
            select.value = '';
        });

        // Submit payment button
        document.getElementById('pos-kb-submit-payment')?.addEventListener('click', submitPayment);

        // Print receipt button
        document.getElementById('pos-kb-btn-print-receipt')?.addEventListener('click', () => {
            if (lastReceiptUrl) {
                window.open(lastReceiptUrl, '_blank');
            }
        });

        // Finish button
        document.getElementById('pos-kb-btn-finish')?.addEventListener('click', () => {
            $('#pos-kas-bon-modal').modal('hide');
            resetState();
        });

        // Modal hidden reset
        $('#pos-kas-bon-modal').on('hidden.bs.modal', function () {
            resetState();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        openModal: openModal,
        loadCandidates: loadCandidates,
        selectCandidate: selectCandidate,
        loadReview: loadReview,
        resetState: resetState,
    };
})();
