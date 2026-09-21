/**
 * Shared payment-amount input behavior.
 *
 * Enhances any input marked with [data-payment-amount] to:
 *  - keep a canonical decimal value (unrestricted fractional precision, non-negative) independent
 *    from its localized display
 *  - show the raw canonical number while focused (here "." is the decimal separator)
 *  - show an Indonesian-localized value while blurred (thousand separator ".", decimal ",")
 *    with exactly two decimals only when the amount is fractional
 *  - reject non-empty invalid edits instead of silently coercing them to zero or a stale value
 *
 * Parsing is context-aware: a field tracks whether it is currently showing its focused
 * (canonical) text or its blurred (localized) text via a `data-payment-amount-state`
 * attribute, so "100.999" is never ambiguous between the two representations.
 *
 * While focused, the canonical value is derived from the live edit. After a successful blur,
 * the full canonical value is retained separately because the visible display is rounded to
 * 2 decimals and therefore cannot safely be used as the submission source of truth.
 *
 * Other scripts read/set the canonical value via:
 *   PaymentAmountInput.getCanonicalValue(el) -> string ('' | numeric string) or null when invalid
 *   PaymentAmountInput.isValid(el) -> boolean
 *   PaymentAmountInput.setCanonicalValue(el, value) -> void (renders blurred display)
 */
(function (global, $) {
    'use strict';

    var INVALID_CLASS = 'is-invalid';
    var STATE_ATTR = 'paymentAmountState';
    var STATE_FOCUSED = 'focused';
    var STATE_BLURRED = 'blurred';

    var CANONICAL_VALUE_KEY = 'paymentAmountCanonicalValue';
    var CANONICAL_PATTERN = /^\d+(\.\d+)?$/;
    var LOCALIZED_PATTERN = /^\d{1,3}(\.\d{3})*(,\d+)?$/;

    function parseCanonical(value) {
        if (!CANONICAL_PATTERN.test(value)) {
            return null;
        }
        return isNaN(parseFloat(value)) ? null : value;
    }

    function parseLocalized(value) {
        if (!LOCALIZED_PATTERN.test(value)) {
            return null;
        }
        var normalized = value.replace(/\./g, '').replace(',', '.');
        return isNaN(parseFloat(normalized)) ? null : normalized;
    }

    /**
     * Parses raw text into a canonical decimal string given the representation it is in.
     * state: STATE_FOCUSED -> raw canonical text (e.g. "1000.999")
     *        STATE_BLURRED -> localized text (e.g. "1.250.000,50")
     * Returns '' for empty input, null when non-empty and invalid. Negative values are rejected.
     */
    function parseByState(raw, state) {
        if (raw === null || raw === undefined) {
            return null;
        }

        var value = String(raw).trim();

        if (value === '') {
            return '';
        }

        if (value.charAt(0) === '-') {
            return null;
        }

        return state === STATE_FOCUSED ? parseCanonical(value) : parseLocalized(value);
    }

    function formatDisplay(canonical) {
        if (canonical === '' || canonical === null || canonical === undefined) {
            return '';
        }

        var num = parseFloat(canonical);
        if (isNaN(num)) {
            return '';
        }

        // Whether decimals are shown is based on the canonical value, not the rounded
        // two-decimal display. For example, 1.005 displays as 1,00 rather than hiding
        // the decimal suffix merely because its rounded cents are zero.
        var isFractional = num !== Math.trunc(num);
        var fixed = num.toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        if (!isFractional) {
            return parts[0];
        }

        return parts[0] + ',' + parts[1];
    }

    function getState($el) {
        return $el.data(STATE_ATTR) === STATE_FOCUSED ? STATE_FOCUSED : STATE_BLURRED;
    }

    /**
     * Always re-derives from the live visible text, interpreted according to whether the
     * field is currently focused (canonical text) or blurred (localized text). Returns null
     * when the current text is a non-empty value that cannot be normalized (i.e. invalid).
     */
    function getCanonicalValue(el) {
        var $el = $(el);
        if (getState($el) === STATE_FOCUSED) {
            return parseByState($el.val(), STATE_FOCUSED);
        }

        var stored = $el.data(CANONICAL_VALUE_KEY);
        return stored === undefined ? parseByState($el.val(), STATE_BLURRED) : stored;
    }

    function isValid(el) {
        return getCanonicalValue(el) !== null;
    }

    /**
     * Used for programmatic sets (e.g. clamping to a max) where the caller supplies a
     * canonical non-negative number or numeric string. Full precision is retained; only the
     * blurred display is rounded to 2dp.
     */
    function normalizeProgrammaticValue(value) {
        var raw = String(value === null || value === undefined ? '' : value).trim();
        if (!CANONICAL_PATTERN.test(raw)) {
            return '';
        }
        return raw;
    }

    function setCanonicalValue(el, value) {
        var $el = $(el);
        var normalized = normalizeProgrammaticValue(value);

        $el.data(STATE_ATTR, STATE_BLURRED);
        $el.data(CANONICAL_VALUE_KEY, normalized);
        $el.removeClass(INVALID_CLASS);
        $el.val(formatDisplay(normalized));
    }

    /**
     * Strips insignificant trailing fractional zeros (e.g. "1250000.500" -> "1250000.5"),
     * per the focused-editing contract. Leaves other values untouched.
     */
    function trimTrailingFractionalZero(canonical) {
        return canonical.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    }

    function handleFocus() {
        var $el = $(this);

        if (getState($el) === STATE_FOCUSED) {
            // Already in the focused representation (e.g. re-focusing after an unsuccessful
            // blur left an invalid canonical edit in place). Re-parsing this text as localized
            // would misinterpret it — leave it exactly as the operator left it.
            $el.select();
            return;
        }

        var canonical = $el.data(CANONICAL_VALUE_KEY);
        if (canonical === undefined) {
            canonical = parseByState($el.val(), STATE_BLURRED);
        }

        $el.data(STATE_ATTR, STATE_FOCUSED);

        if (canonical === null) {
            // Preserve the invalid text as-is; do not erase what the operator sees.
            $el.addClass(INVALID_CLASS);
            $el.select();
            return;
        }

        $el.val(canonical === '' ? '' : trimTrailingFractionalZero(canonical));
        $el.select();
    }

    function handleBlur() {
        var $el = $(this);
        var normalized = parseByState($el.val(), STATE_FOCUSED);

        if (normalized === null) {
            // Keep interpreting the text as a focused (canonical) edit until it is corrected,
            // so an invalid entry can never be silently reinterpreted as valid localized text
            // (e.g. "100.999" typed as a canonical amount must not become 100999 on blur).
            $el.addClass(INVALID_CLASS);
            return;
        }

        $el.data(STATE_ATTR, STATE_BLURRED);
        $el.data(CANONICAL_VALUE_KEY, normalized);
        $el.removeClass(INVALID_CLASS);
        $el.val(formatDisplay(normalized));
    }

    function handleInput() {
        var $el = $(this);
        if (parseByState($el.val(), getState($el)) === null) {
            $el.addClass(INVALID_CLASS);
        } else {
            $el.removeClass(INVALID_CLASS);
        }
    }

    function enhance(el) {
        var $el = $(el);

        if ($el.data('paymentAmountEnhanced')) {
            return;
        }

        $el.data('paymentAmountEnhanced', true);
        $el.attr('inputmode', 'decimal');
        $el.data(STATE_ATTR, STATE_BLURRED);

        // Initial/old-input values are normally canonical. A comma unambiguously identifies
        // a localized value, which is retained as a compatibility fallback.
        var initialRaw = $el.val();
        var initialCanonical = String(initialRaw).indexOf(',') !== -1
            ? parseByState(initialRaw, STATE_BLURRED)
            : parseByState(initialRaw, STATE_FOCUSED);
        setCanonicalValue(el, initialCanonical === null ? '' : initialCanonical);

        $el.on('focus', handleFocus);
        $el.on('blur', handleBlur);
        $el.on('input', handleInput);
    }

    function init(context) {
        var $scope = context ? $(context) : $(document);
        $scope.find('[data-payment-amount]').each(function () {
            enhance(this);
        });
    }

    /**
     * Validates every enhanced field within scope, marking invalid ones, and returns
     * whether all of them held a valid (possibly empty) canonical value.
     */
    function validateScope(scope) {
        var allValid = true;
        $(scope).find('[data-payment-amount]').addBack('[data-payment-amount]').each(function () {
            if (!isValid(this)) {
                $(this).addClass(INVALID_CLASS);
                allValid = false;
            }
        });
        return allValid;
    }

    global.PaymentAmountInput = {
        init: init,
        enhance: enhance,
        formatDisplay: formatDisplay,
        getCanonicalValue: getCanonicalValue,
        setCanonicalValue: setCanonicalValue,
        isValid: isValid,
        validateScope: validateScope,
        INVALID_CLASS: INVALID_CLASS
    };

    $(function () {
        init(document);
    });
})(window, jQuery);
