/*
 * Stock Transfer workflow v3 approval workspace helpers (DOM-free).
 *
 * The per-product stock map is rendered by the server from
 * TransferV3AllocationService::sourceOptions(), which only includes
 * `tax`/`non_tax` when the approver may view system stock. These helpers
 * never add details that are not in the map.
 *
 * Loaded inline by Modules/Adjustment/Resources/views/transfers/v3/approval.blade.php
 * and by tests/js/transfer-v3-approval-allocation.test.mjs through node:vm.
 */
(function (root) {
    'use strict';

    /**
     * @param {Object<string, {available: number, tax?: number, non_tax?: number}>} stock
     * @param {string|number|null} locationId
     * @returns {string}
     */
    function stockText(stock, locationId) {
        if (locationId === null || locationId === undefined || locationId === '') {
            return '';
        }
        var entry = stock ? stock[String(locationId)] : null;
        if (!entry) {
            return '';
        }
        var text = 'Stok tersedia: ' + entry.available;
        if (entry.tax !== undefined && entry.non_tax !== undefined) {
            text += ' (pajak ' + entry.tax + ', non-pajak ' + entry.non_tax + ')';
        }
        return text;
    }

    /** Rewrites the row index in a `rows[N][field]` input name. */
    function renumber(name, index) {
        return String(name).replace(/rows\[\d+\]/, 'rows[' + index + ']');
    }

    /**
     * Runs `callback` once deferred scripts have executed. The CoreUI modal
     * jQuery plugin is registered synchronously when resources/js/app.js
     * (a Vite `type="module"` script, therefore deferred) evaluates, and
     * deferred/module scripts always run before DOMContentLoaded. Inline
     * page scripts run earlier, during parsing, so they must wait for it.
     */
    function whenScriptsReady(doc, callback) {
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function hasModalPlugin($) {
        return Boolean($ && $.fn && typeof $.fn.modal === 'function');
    }

    /**
     * Shows the approval summary in the CoreUI modal when the plugin is
     * available; otherwise reveals the inline fallback (same content and
     * confirmation form) with visible feedback. Never confirms anything.
     *
     * @returns {'modal'|'fallback'}
     */
    function showSummary(options) {
        var $ = options.jQuery;
        if (hasModalPlugin($) && options.modal) {
            try {
                $(options.modal).modal('show');
                return 'modal';
            } catch (error) {
                // fall through to the inline summary
            }
        }
        if (options.fallback) {
            options.fallback.hidden = false;
            if (typeof options.fallback.focus === 'function') {
                options.fallback.focus();
            }
        }
        return 'fallback';
    }

    root.V3TransferApproval = {
        stockText: stockText,
        renumber: renumber,
        whenScriptsReady: whenScriptsReady,
        hasModalPlugin: hasModalPlugin,
        showSummary: showSummary,
    };
})(typeof window !== 'undefined' ? window : globalThis);
