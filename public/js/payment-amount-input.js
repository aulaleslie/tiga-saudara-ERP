/**
 * Compatibility alias for the shared real-time financial input formatter.
 *
 * This file previously implemented a standalone focus/blur payment-amount formatter. It now
 * delegates entirely to public/js/financial-input.js (loaded first) so the five existing
 * Sales/Purchase/POS payment views -- which reference the `PaymentAmountInput` global and mark
 * their fields with `[data-payment-amount]` -- keep working unchanged, now with real-time
 * formatting instead of focus/blur-only formatting.
 *
 * `[data-payment-amount]` is recognized directly by the shared formatter's marker selector, so
 * no extra wiring is required here beyond exposing the `PaymentAmountInput` name.
 */
(function (global) {
    'use strict';

    if (!global.FinancialInput) {
        throw new Error('payment-amount-input.js requires financial-input.js to be loaded first.');
    }

    global.PaymentAmountInput = global.FinancialInput;
})(window);
