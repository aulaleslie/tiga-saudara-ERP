/**
 * Shared real-time financial input formatter.
 *
 * Enhances any input marked with [data-financial-amount] (payment amount inputs also use the
 * legacy [data-payment-amount] marker, both are treated identically) to:
 *  - continuously display an Indonesian-grouped value ("." thousands, "," decimal) while the
 *    operator types -- never showing a raw unformatted number, even while focused
 *  - accept "." as the operator-entered decimal key; it renders as ","
 *  - keep an exact canonical decimal string (e.g. "120000.23") completely separate from the
 *    display text (e.g. "120.000,23") at all times
 *  - accept only digits plus at most one operator-entered "." (decimal key); anything else
 *    (letters, a second ".", a comma, whitespace, a sign, or already-localized pasted text such
 *    as "120.000,23") is silently rejected as a whole operation: no error class, no reset, no
 *    alert, and the prior value/selection/caret are left untouched
 *  - preserve natural Backspace/Delete/selection-replace/caret navigation across the boundaries
 *    introduced by inserted thousands separators, by mapping caret position through logical
 *    digit/decimal-point tokens rather than raw DOM offsets
 *  - preserve an incomplete decimal state such as "120000." (displayed "120.000,") so the
 *    operator can keep entering fractional digits
 *
 * Canonical value contract (unchanged from the legacy focus/blur helper):
 *   FinancialInput.getCanonicalValue(el) -> string ('' | numeric string) or null when invalid
 *   FinancialInput.isValid(el) -> boolean
 *   FinancialInput.setCanonicalValue(el, value) -> void (renders the formatted display)
 *   FinancialInput.formatDisplay(canonical) -> string
 *   FinancialInput.enhance(el) -> void (idempotent)
 *   FinancialInput.init(context) -> void
 *   FinancialInput.validateScope(scope) -> boolean
 *   FinancialInput.INVALID_CLASS -> string
 *
 * `PaymentAmountInput` (public/js/payment-amount-input.js) is a thin compatibility alias of this
 * module retained so existing payment views keep working unchanged.
 */
(function (global, $) {
    'use strict';

    var INVALID_CLASS = 'is-invalid';
    var MARKERS = '[data-financial-amount], [data-payment-amount]';

    var ENHANCED_KEY = 'financialAmountEnhanced';
    var CANONICAL_KEY = 'financialAmountCanonical';

    var CANONICAL_PATTERN = /^\d+(\.\d*)?$/;

    // ------------------------------------------------------------------
    // Canonical <-> display helpers
    // ------------------------------------------------------------------

    /**
     * Groups an all-digit integer string with "." every three digits from the right.
     */
    function groupInteger(digits) {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /**
     * Renders a canonical string (possibly ending in a trailing "." to represent an
     * incomplete decimal entry) as its Indonesian-grouped display text.
     */
    function formatDisplay(canonical) {
        if (canonical === '' || canonical === null || canonical === undefined) {
            return '';
        }

        var str = String(canonical);
        var dotIndex = str.indexOf('.');

        if (dotIndex === -1) {
            return groupInteger(str);
        }

        var intPart = str.slice(0, dotIndex);
        var fracPart = str.slice(dotIndex + 1);

        if (intPart === '') {
            intPart = '0';
        }

        return groupInteger(intPart) + ',' + fracPart;
    }

    /**
     * Validates and normalizes a canonical candidate string. Returns null when invalid.
     * Valid forms: '', '0', '120000', '120000.', '120000.23'. A canonical value is never
     * negative and contains only digits plus at most one ".".
     */
    function normalizeCanonical(candidate) {
        if (candidate === '' ) {
            return '';
        }
        if (candidate === null || candidate === undefined) {
            return null;
        }

        var str = String(candidate);

        if (str === '.') {
            // A lone decimal point with no leading digit is not itself a valid accepted
            // candidate on its own (there must be at least an implicit "0" before it); the
            // insertion logic always supplies the digits typed so far, so this only guards
            // direct calls.
            return null;
        }

        if (!/^\d*\.?\d*$/.test(str) || (str.match(/\./g) || []).length > 1) {
            return null;
        }

        return str;
    }

    /**
     * Strips a trailing "." (incomplete decimal editing state) for submission/canonical-read
     * purposes, per the "normalizes to whole value" contract.
     */
    function finalizeCanonical(canonical) {
        if (canonical === '' || canonical === null || canonical === undefined) {
            return canonical;
        }
        if (canonical.charAt(canonical.length - 1) === '.') {
            return canonical.slice(0, -1) === '' ? '0' : canonical.slice(0, -1);
        }
        return canonical;
    }

    // ------------------------------------------------------------------
    // Logical caret token mapping
    // ------------------------------------------------------------------

    /**
     * Converts a display-text caret offset into a "logical index": the count of canonical
     * tokens (digits, plus the decimal point itself as one token) that precede the caret,
     * ignoring grouping "." separators which are not part of the canonical value.
     */
    function displayOffsetToLogicalIndex(display, offset) {
        var logical = 0;
        for (var i = 0; i < offset && i < display.length; i++) {
            var ch = display.charAt(i);
            if (ch === '.') {
                // grouping separator, not a logical token
                continue;
            }
            logical++;
        }
        return logical;
    }

    /**
     * Converts a logical index (count of canonical tokens, where the decimal comma counts as
     * one token) back into a caret offset within the freshly rendered display string.
     */
    function logicalIndexToDisplayOffset(display, logicalIndex) {
        if (logicalIndex <= 0) {
            return 0;
        }
        var seen = 0;
        for (var i = 0; i < display.length; i++) {
            var ch = display.charAt(i);
            if (ch !== '.') {
                seen++;
            }
            if (seen >= logicalIndex) {
                return i + 1;
            }
        }
        return display.length;
    }

    // ------------------------------------------------------------------
    // Element state
    // ------------------------------------------------------------------

    function getCanonicalState(el) {
        var $el = $(el);
        var stored = $el.data(CANONICAL_KEY);
        return stored === undefined ? '' : stored;
    }

    function setCanonicalState(el, canonical) {
        $(el).data(CANONICAL_KEY, canonical);
    }

    function render(el, canonical, logicalCaret) {
        var $el = $(el);
        var display = formatDisplay(canonical);

        setCanonicalState(el, canonical);
        $el.val(display);
        $el.removeClass(INVALID_CLASS);

        if (typeof logicalCaret === 'number' && el.setSelectionRange) {
            var offset = logicalIndexToDisplayOffset(display, logicalCaret);
            try {
                el.setSelectionRange(offset, offset);
            } catch (e) {
                // Some input types do not support selection ranges; ignore.
            }
        }

        // Dispatched as a real, bubbling native CustomEvent (not only via jQuery's internal
        // event system) so that both native addEventListener('financial-amount:change', ...)
        // consumers and jQuery .on('financial-amount:change', ...) consumers are notified. jQuery
        // listens through native addEventListener internally, so a native dispatch reaches both.
        if (typeof CustomEvent === 'function') {
            el.dispatchEvent(new CustomEvent('financial-amount:change', { bubbles: true }));
        } else if ($el.trigger) {
            $el.trigger('financial-amount:change');
        }
    }

    /**
     * Builds the canonical candidate that would result from replacing the logical token range
     * [logicalStart, logicalEnd) of the current canonical value with `insertText` (already
     * filtered to only digits and at most one ".").
     *
     * Returns { canonical, caret } on success, or null when the resulting candidate would not
     * match the digits-plus-one-dot grammar (whole operation rejected).
     */
    function buildCandidate(currentCanonical, logicalStart, logicalEnd, insertText) {
        var tokens = String(currentCanonical || '').split('');

        if (logicalStart < 0) logicalStart = 0;
        if (logicalEnd < logicalStart) logicalEnd = logicalStart;
        if (logicalStart > tokens.length) logicalStart = tokens.length;
        if (logicalEnd > tokens.length) logicalEnd = tokens.length;

        var before = tokens.slice(0, logicalStart).join('');
        var after = tokens.slice(logicalEnd).join('');
        var candidate = before + insertText + after;

        var normalized = normalizeCanonical(candidate);
        if (normalized === null) {
            return null;
        }

        return {
            canonical: normalized,
            caret: logicalStart + insertText.length
        };
    }

    /**
     * Filters raw inserted text down to only characters this grammar ever accepts (digits and
     * "."), without yet checking whole-candidate validity (e.g. a second "."). Returns null
     * immediately when any disallowed character (letter, whitespace, comma, sign, exponent,
     * etc.) is present anywhere in the insertion -- the whole operation is rejected, never
     * partially accepted.
     */
    function filterInsertion(text) {
        if (text === '' || text === null || text === undefined) {
            return '';
        }
        if (!/^[0-9.]*$/.test(text)) {
            return null;
        }
        return text;
    }

    // ------------------------------------------------------------------
    // Selection helpers
    // ------------------------------------------------------------------

    function getSelection(el) {
        var start = typeof el.selectionStart === 'number' ? el.selectionStart : (el.value || '').length;
        var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
        return { start: start, end: end };
    }

    // ------------------------------------------------------------------
    // Core edit application
    // ------------------------------------------------------------------

    /**
     * Attempts to apply an edit described in *display*-offset terms (as delivered by
     * beforeinput/keydown/paste) against the field's current canonical state. On acceptance,
     * re-renders the field and returns true. On rejection, leaves everything untouched and
     * returns false.
     */
    function applyEdit(el, displaySelStart, displaySelEnd, rawInsertText) {
        var currentCanonical = getCanonicalState(el);
        var currentDisplay = formatDisplay(currentCanonical);

        var filtered = filterInsertion(rawInsertText);
        if (filtered === null) {
            return false;
        }

        var logicalStart = displayOffsetToLogicalIndex(currentDisplay, displaySelStart);
        var logicalEnd = displayOffsetToLogicalIndex(currentDisplay, displaySelEnd);

        var result = buildCandidate(currentCanonical, logicalStart, logicalEnd, filtered);
        if (result === null) {
            return false;
        }

        render(el, result.canonical, result.caret);
        return true;
    }

    /**
     * Handles a plain deletion (Backspace/Delete with an empty insert text) expressed in
     * logical-token terms, since a collapsed selection needs to consume one extra logical
     * token in the deletion direction.
     */
    function applyDeletion(el, displaySelStart, displaySelEnd, direction) {
        var currentCanonical = getCanonicalState(el);
        var currentDisplay = formatDisplay(currentCanonical);

        var logicalStart = displayOffsetToLogicalIndex(currentDisplay, displaySelStart);
        var logicalEnd = displayOffsetToLogicalIndex(currentDisplay, displaySelEnd);

        if (logicalStart === logicalEnd) {
            if (direction === 'backward') {
                logicalStart = Math.max(0, logicalStart - 1);
            } else {
                logicalEnd = Math.min(currentCanonical.length, logicalEnd + 1);
            }
        }

        var result = buildCandidate(currentCanonical, logicalStart, logicalEnd, '');
        if (result === null) {
            return false;
        }

        render(el, result.canonical, result.caret);
        return true;
    }

    // ------------------------------------------------------------------
    // Event wiring
    // ------------------------------------------------------------------

    function handleBeforeInput(nativeEvent) {
        var el = this;
        var type = nativeEvent && nativeEvent.inputType;

        if (!nativeEvent || typeof nativeEvent.preventDefault !== 'function') {
            return;
        }

        var sel = getSelection(el);

        if (type === 'deleteContentBackward') {
            nativeEvent.preventDefault();
            applyDeletion(el, sel.start, sel.end, 'backward');
            return;
        }

        if (type === 'deleteContentForward') {
            nativeEvent.preventDefault();
            applyDeletion(el, sel.start, sel.end, 'forward');
            return;
        }

        if (type === 'insertText' || type === 'insertFromPaste' || type === 'insertFromDrop' || type === 'insertCompositionText') {
            nativeEvent.preventDefault();
            var text = nativeEvent.data;
            if (text === null || text === undefined) {
                if (nativeEvent.dataTransfer && typeof nativeEvent.dataTransfer.getData === 'function') {
                    text = nativeEvent.dataTransfer.getData('text');
                } else {
                    text = '';
                }
            }
            applyEdit(el, sel.start, sel.end, text);
            return;
        }

        // Other input types (e.g. deleteByCut, historyUndo, formatBold) are not part of this
        // grammar's supported operations; let them proceed to the input-event reconciliation
        // fallback below, which will keep the field consistent with the canonical model.
    }

    /**
     * Fallback reconciliation for environments/events where beforeinput could not be gated
     * (autofill, IME composition end, browsers without beforeinput support, or paste events
     * that fire only a plain `input`). Compares the DOM's current raw value against the last
     * rendered display and derives the equivalent insertion, then re-applies the same gate.
     * If the browser already mutated the DOM to something the grammar would reject, the field
     * is restored to the last accepted display.
     */
    function handleInputFallback() {
        var el = this;
        var $el = $(el);
        var currentCanonical = getCanonicalState(el);
        var lastDisplay = formatDisplay(currentCanonical);
        var domValue = el.value;

        if (domValue === lastDisplay) {
            return;
        }

        // Compute a naive diff: common prefix / suffix between lastDisplay and domValue.
        var prefix = 0;
        var maxPrefix = Math.min(lastDisplay.length, domValue.length);
        while (prefix < maxPrefix && lastDisplay.charAt(prefix) === domValue.charAt(prefix)) {
            prefix++;
        }

        var suffixLast = lastDisplay.length;
        var suffixDom = domValue.length;
        while (suffixLast > prefix && suffixDom > prefix && lastDisplay.charAt(suffixLast - 1) === domValue.charAt(suffixDom - 1)) {
            suffixLast--;
            suffixDom--;
        }

        var removedFromDisplay = lastDisplay.slice(prefix, suffixLast);
        var insertedText = domValue.slice(prefix, suffixDom);

        var accepted;
        if (insertedText === '' && removedFromDisplay !== '') {
            accepted = applyDeletion(el, prefix, suffixLast, 'backward');
        } else {
            accepted = applyEdit(el, prefix, suffixLast, insertedText);
        }

        if (!accepted) {
            // Whole operation rejected: restore the prior accepted display/canonical exactly,
            // caret included (best-effort at the same logical position).
            var caretLogical = displayOffsetToLogicalIndex(lastDisplay, prefix);
            render(el, currentCanonical, caretLogical);
        }
    }

    function handleFocus() {
        // Real-time display never changes on focus; nothing to do beyond leaving the field as-is.
    }

    function handleBlur() {
        // Real-time display never changes on blur; nothing to do beyond leaving the field as-is.
    }

    function handlePaste(nativeEvent) {
        // Only used as a fallback when beforeinput is unavailable/unsupported for paste; when
        // beforeinput handles insertFromPaste this listener's preventDefault is a no-op because
        // the paste was already gated. We defensively gate here too for older browsers.
        if (typeof global.InputEvent === 'function' && 'inputType' in global.InputEvent.prototype) {
            // beforeinput with inputType support exists; let it govern paste exclusively.
            return;
        }

        if (!nativeEvent || !nativeEvent.clipboardData || typeof nativeEvent.preventDefault !== 'function') {
            return;
        }

        var el = this;
        var text = nativeEvent.clipboardData.getData('text');
        var sel = getSelection(el);

        nativeEvent.preventDefault();
        applyEdit(el, sel.start, sel.end, text);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    function isValid(el) {
        return getCanonicalValue(el) !== null;
    }

    function getCanonicalValue(el) {
        var $el = $(el);
        var stored = $el.data(CANONICAL_KEY);
        if (stored === undefined) {
            return normalizeCanonical($el.val());
        }
        return finalizeCanonical(stored) === undefined ? null : finalizeCanonical(stored);
    }

    function normalizeProgrammaticValue(value) {
        var raw = String(value === null || value === undefined ? '' : value).trim();
        if (raw === '') {
            return '';
        }
        if (!CANONICAL_PATTERN.test(raw)) {
            return '';
        }
        return raw;
    }

    function setCanonicalValue(el, value) {
        var normalized = normalizeProgrammaticValue(value);
        render(el, normalized);
    }

    /**
     * Parses an initial/server-rendered field value into a canonical string. Accepts a plain
     * canonical numeric string (e.g. "120000.23", the normal case, including exact fractional
     * precision such as "1000.999") or, as a compatibility fallback for legacy localized
     * old-input values, converts it to canonical form.
     *
     * A comma unambiguously identifies a localized value (canonical values never contain one).
     * Without a comma, a value is treated as canonical as-is -- a bare "." is always the
     * canonical decimal point here, never a thousands separator, so "1000.999" is never
     * misread as a grouped integer.
     */
    function parseInitialValue(raw) {
        var value = String(raw === null || raw === undefined ? '' : raw).trim();
        if (value === '') {
            return '';
        }

        if (value.indexOf(',') !== -1) {
            // Looks like a localized display value; normalize it to canonical.
            var normalized = value.replace(/\./g, '').replace(',', '.');
            return CANONICAL_PATTERN.test(normalized) ? normalized : '';
        }

        return CANONICAL_PATTERN.test(value) ? value : '';
    }

    function enhance(el) {
        var $el = $(el);

        if ($el.data(ENHANCED_KEY)) {
            return;
        }

        $el.data(ENHANCED_KEY, true);
        $el.attr('inputmode', 'decimal');

        var initialCanonical = parseInitialValue($el.val());
        render(el, initialCanonical);

        $el.on('beforeinput', handleBeforeInput);
        $el.on('input', handleInputFallback);
        $el.on('paste', handlePaste);
        $el.on('focus', handleFocus);
        $el.on('blur', handleBlur);
    }

    function init(context) {
        var $scope = context ? $(context) : $(document);
        $scope.find(MARKERS).each(function () {
            enhance(this);
        });
    }

    function validateScope(scope) {
        var allValid = true;
        $(scope).find(MARKERS).addBack(MARKERS).each(function () {
            if (!isValid(this)) {
                $(this).addClass(INVALID_CLASS);
                allValid = false;
            }
        });
        return allValid;
    }

    var api = {
        init: init,
        enhance: enhance,
        formatDisplay: formatDisplay,
        getCanonicalValue: getCanonicalValue,
        setCanonicalValue: setCanonicalValue,
        isValid: isValid,
        validateScope: validateScope,
        INVALID_CLASS: INVALID_CLASS,
        // exposed for tests / advanced integrations
        _internal: {
            applyEdit: applyEdit,
            applyDeletion: applyDeletion,
            displayOffsetToLogicalIndex: displayOffsetToLogicalIndex,
            logicalIndexToDisplayOffset: logicalIndexToDisplayOffset,
            normalizeCanonical: normalizeCanonical,
            finalizeCanonical: finalizeCanonical
        }
    };

    global.FinancialInput = api;

    $(function () {
        init(document);
    });
})(window, jQuery);
