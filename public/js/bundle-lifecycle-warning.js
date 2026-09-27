(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        module.exports.default = module.exports;
    } else if (root) {
        root.BundleLifecycleWarning = factory();
    } else if (typeof globalThis !== 'undefined') {
        globalThis.BundleLifecycleWarning = factory();
    }
}(typeof self !== 'undefined' ? self : (typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this)), function () {
    'use strict';

    /**
     * Resolves lifecycle warning data from an error/response object in priority order:
     * 1. error.details?.warning
     * 2. error.warning
     * 3. root error only if it already contains warning items
     *
     * @param {Object} error
     * @returns {Object|null}
     */
    function resolveLifecycleWarning(error) {
        if (!error || typeof error !== 'object') {
            return null;
        }

        if (error.details && error.details.warning && Array.isArray(error.details.warning.items) && error.details.warning.items.length > 0) {
            return error.details.warning;
        }

        if (error.warning && Array.isArray(error.warning.items) && error.warning.items.length > 0) {
            return error.warning;
        }

        if (Array.isArray(error.items) && error.items.length > 0) {
            return error;
        }

        if (error.details && error.details.warning) {
            return error.details.warning;
        }

        if (error.warning) {
            return error.warning;
        }

        return null;
    }

    /**
     * Safely escapes HTML special characters in dynamic values.
     *
     * @param {string|any} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Builds safe list item HTML from warning item objects.
     * Escapes dynamic labels and reasons/messages while preserving structural <li> and <strong> tags.
     *
     * @param {Array} items
     * @returns {string}
     */
    function buildLifecycleWarningItemsHtml(items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }

        return items.map(function (item) {
            const rawReasons = Array.isArray(item.reasons) ? item.reasons.join(', ') : (item.reason || '');
            const rawLabel = item.line_label || item.product_name || item.bundle_name || 'Paket';
            const rawMsg = item.message ? item.message : rawReasons;
            const escapedLabel = escapeHtml(rawLabel);
            const escapedMsg = rawMsg ? ': ' + escapeHtml(rawMsg) : '';
            return '<li><strong>' + escapedLabel + '</strong>' + escapedMsg + '</li>';
        }).join('');
    }

    /**
     * Builds safe SweetAlert modal HTML container.
     *
     * @param {Object} warningData
     * @param {string} [defaultMessage]
     * @param {string} [promptText]
     * @returns {string}
     */
    function buildLifecycleWarningModalHtml(warningData, defaultMessage, promptText) {
        const rawMessage = (warningData && warningData.message) ? warningData.message : (defaultMessage || 'Terdapat perubahan status pada paket produk dalam transaksi ini.');
        const rawPrompt = promptText || 'Apakah Anda ingin melanjutkan transaksi dengan komposisi yang tersimpan?';
        const items = (warningData && warningData.items) ? warningData.items : [];
        const escapedMessage = escapeHtml(rawMessage);
        const escapedPrompt = escapeHtml(rawPrompt);
        const itemsList = buildLifecycleWarningItemsHtml(items);

        return '<div class="text-left"><p>' + escapedMessage + '</p><ul class="small mb-0 text-muted">' + itemsList + '</ul><p class="mt-2 mb-0">' + escapedPrompt + '</p></div>';
    }

    /**
     * Finds the exact matching form for a given lifecycle warning target.
     *
     * @param {Document|HTMLElement} rootElement
     * @param {Object} warningData
     * @returns {HTMLFormElement|null}
     */
    function findTargetForm(rootElement, warningData) {
        if (!rootElement || !warningData || typeof warningData !== 'object') {
            return null;
        }

        const isDispatchApproval = warningData.target_type === 'dispatch_approval';
        const isStoreDispatch = warningData.target_type === 'store_dispatch';

        if (isDispatchApproval && warningData.dispatch_id) {
            return rootElement.querySelector('form[data-dispatch-approval-id="' + warningData.dispatch_id + '"]')
                || rootElement.querySelector('form[action$="/dispatches/' + warningData.dispatch_id + '/approve"]');
        }

        if (warningData.target_type === 'sale_approval' && warningData.sale_id) {
            const statusVal = warningData.status || 'APPROVED';
            return rootElement.querySelector('form[data-sale-approval-id="' + warningData.sale_id + '"][data-status="' + statusVal + '"]')
                || rootElement.querySelector('form[data-sale-approval-id="' + warningData.sale_id + '"]')
                || rootElement.querySelector('form[action$="/sales/' + warningData.sale_id + '/status"]');
        }

        if (isStoreDispatch && warningData.sale_id) {
            return rootElement.querySelector('form[data-store-dispatch-id="' + warningData.sale_id + '"]')
                || rootElement.querySelector('form[action$="/sales/' + warningData.sale_id + '/dispatch"]');
        }

        return null;
    }

    /**
     * Applies acknowledge_lifecycle_warning=1 to a form and submits it.
     *
     * @param {HTMLFormElement} form
     */
    function applyAcknowledgementAndSubmit(form) {
        if (!form) return;
        let ackInput = form.querySelector('input[name="acknowledge_lifecycle_warning"]');
        if (!ackInput) {
            const doc = form.ownerDocument || (typeof document !== 'undefined' ? document : null);
            if (doc && typeof doc.createElement === 'function') {
                ackInput = doc.createElement('input');
                ackInput.type = 'hidden';
                ackInput.name = 'acknowledge_lifecycle_warning';
                form.appendChild(ackInput);
            }
        }
        if (ackInput) {
            ackInput.value = '1';
        }
        form.submit();
    }

    /**
     * Handles lifecycle warning modal presentation and form retry for Sales and Dispatch actions.
     *
     * @param {Object} warningData
     * @param {Object} [options]
     * @returns {Promise<boolean>} Resolves true if confirmed and submitted, false if cancelled/unhandled
     */
    async function handleSalesLifecycleWarning(warningData, options) {
        if (!warningData || typeof warningData !== 'object') {
            return false;
        }

        const doc = (options && options.document) || (typeof document !== 'undefined' ? document : null);
        const swal = (options && options.swal) || (typeof Swal !== 'undefined' ? Swal : null);

        if (!swal) {
            console.error('SweetAlert is not available for lifecycle warning modal.');
            return false;
        }

        const isDispatchApproval = warningData.target_type === 'dispatch_approval';
        const isStoreDispatch = warningData.target_type === 'store_dispatch';

        const confirmBtnText = isStoreDispatch
            ? 'Lanjutkan Pengiriman'
            : (isDispatchApproval ? 'Lanjutkan Persetujuan Pengiriman' : 'Lanjutkan Persetujuan');

        const promptText = isStoreDispatch
            ? 'Apakah Anda ingin melanjutkan pengiriman menggunakan komposisi yang tersimpan?'
            : (isDispatchApproval
                ? 'Apakah Anda ingin melanjutkan persetujuan pengiriman menggunakan komposisi yang tersimpan?'
                : 'Apakah Anda ingin melanjutkan persetujuan dengan komposisi yang tersimpan?');

        const defaultMsg = 'Terdapat perubahan status pada paket produk dalam penjualan ini.';
        const modalHtml = buildLifecycleWarningModalHtml(warningData, defaultMsg, promptText);

        const result = await swal.fire({
            icon: 'warning',
            title: 'Peringatan Status Paket',
            html: modalHtml,
            showCancelButton: true,
            confirmButtonText: confirmBtnText,
            cancelButtonText: 'Batal',
        });

        if (!result || !result.isConfirmed) {
            return false;
        }

        if (!doc) {
            return false;
        }

        const form = findTargetForm(doc, warningData);
        if (form) {
            applyAcknowledgementAndSubmit(form);
            return true;
        }

        // Fallback: dynamically construct and submit exact form
        const csrfToken = (doc.querySelector('meta[name="csrf-token"]')?.content) || (options && options.csrfToken) || '';
        const dynamicForm = doc.createElement('form');
        dynamicForm.method = 'POST';

        const tokenInput = doc.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrfToken;
        dynamicForm.appendChild(tokenInput);

        const ackInput = doc.createElement('input');
        ackInput.type = 'hidden';
        ackInput.name = 'acknowledge_lifecycle_warning';
        ackInput.value = '1';
        dynamicForm.appendChild(ackInput);

        if (isDispatchApproval && warningData.dispatch_id) {
            dynamicForm.action = '/dispatches/' + warningData.dispatch_id + '/approve';
        } else if (warningData.target_type === 'sale_approval' && warningData.sale_id) {
            dynamicForm.action = '/sales/' + warningData.sale_id + '/status';
            const methodInput = doc.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'PATCH';
            dynamicForm.appendChild(methodInput);

            const statusInput = doc.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'status';
            statusInput.value = warningData.status || 'APPROVED';
            dynamicForm.appendChild(statusInput);
        } else if (isStoreDispatch && warningData.sale_id) {
            dynamicForm.action = '/sales/' + warningData.sale_id + '/dispatch';
        }

        doc.body.appendChild(dynamicForm);
        dynamicForm.submit();
        return true;
    }

    var BundleLifecycleWarning = {
        resolveLifecycleWarning: resolveLifecycleWarning,
        escapeHtml: escapeHtml,
        buildLifecycleWarningItemsHtml: buildLifecycleWarningItemsHtml,
        buildLifecycleWarningModalHtml: buildLifecycleWarningModalHtml,
        findTargetForm: findTargetForm,
        applyAcknowledgementAndSubmit: applyAcknowledgementAndSubmit,
        handleSalesLifecycleWarning: handleSalesLifecycleWarning,
    };

    return BundleLifecycleWarning;
}));
