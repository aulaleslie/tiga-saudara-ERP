/**
 * Reproducible check for public/js/financial-input.js (the real-time formatter) plus its
 * public/js/payment-amount-input.js compatibility alias.
 *
 * No test framework is wired into this repo for JS, so this is a minimal assert-based script
 * driven by a small dependency-free fake-jQuery harness (tests/js/lib/mini-jquery.cjs) that
 * exercises the real beforeinput/input/paste handlers and DOM-visible state (value, selection,
 * classes), not just the pure parsing helpers.
 *
 * Run with: node tests/js/financial-input.test.cjs
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { makeElement, wrap, createFakeJQuery, makeInputEvent, makePasteEvent } = require('./lib/mini-jquery.cjs');

global.window = {};
global.document = {};
global.jQuery = createFakeJQuery();

require(path.join(__dirname, '..', '..', 'public', 'js', 'financial-input.js'));
require(path.join(__dirname, '..', '..', 'public', 'js', 'payment-amount-input.js'));

const F = global.window.FinancialInput;
const P = global.window.PaymentAmountInput;
let passed = 0;

function check(label, actual, expected) {
    assert.strictEqual(actual, expected, label + ': expected ' + JSON.stringify(expected) + ' got ' + JSON.stringify(actual));
    passed++;
}

function newField(initialValue) {
    var el = makeElement('input');
    el.value = initialValue === undefined ? '' : initialValue;
    F.enhance(el);
    return el;
}

function isInvalid(el) {
    return !!el._classes[F.INVALID_CLASS];
}

/** Simulates typing text at the given caret (collapsed selection) via beforeinput. */
function typeAt(el, caret, text) {
    el.selectionStart = caret;
    el.selectionEnd = caret;
    var evt = makeInputEvent('insertText', text);
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

/** Simulates replacing the given selection range via beforeinput. */
function replaceSelection(el, start, end, text) {
    el.selectionStart = start;
    el.selectionEnd = end;
    var evt = makeInputEvent('insertText', text);
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

function backspaceAt(el, caret) {
    el.selectionStart = caret;
    el.selectionEnd = caret;
    var evt = makeInputEvent('deleteContentBackward');
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

function deleteForwardAt(el, caret) {
    el.selectionStart = caret;
    el.selectionEnd = caret;
    var evt = makeInputEvent('deleteContentForward');
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

function backspaceSelection(el, start, end) {
    el.selectionStart = start;
    el.selectionEnd = end;
    var evt = makeInputEvent('deleteContentBackward');
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

function pasteAt(el, start, end, text) {
    el.selectionStart = start;
    el.selectionEnd = end;
    var evt = makeInputEvent('insertFromPaste', text);
    wrap(el).trigger('beforeinput', evt);
    return evt;
}

// ============================================================
// 1.3 Progressive grouping + exact fractional rendering
// ============================================================

check('format whole', F.formatDisplay('1250000'), '1.250.000');
check('format fractional exact', F.formatDisplay('120000.23'), '120.000,23');
check('format trailing zero preserved', F.formatDisplay('1250000.50'), '1.250.000,50');
check('format more than 2 fractional digits', F.formatDisplay('1000.999'), '1.000,999');
check('format incomplete decimal', F.formatDisplay('120000.'), '120.000,');
check('format empty', F.formatDisplay(''), '');

{
    var el = newField('120000.23');
    check('1.3: init canonical', F.getCanonicalValue(el), '120000.23');
    check('1.3: init display', el.value, '120.000,23');
}

{
    // Regression: a server-rendered value with exactly 3 fractional digits (e.g. "1000.999")
    // must NOT be misread as a legacy localized/grouped value during enhance()-time
    // initialization. Only a comma unambiguously identifies localized text; a bare "." is
    // always the canonical decimal point here.
    var el = newField('1000.999');
    check('init: three-decimal canonical value is not corrupted', F.getCanonicalValue(el), '1000.999');
    check('init: three-decimal display', el.value, '1.000,999');
}

// ============================================================
// 1.2 Initial canonical rendering + programmatic get/set
// ============================================================

{
    var el = newField('1250000');
    check('init: whole value renders grouped', el.value, '1.250.000');
    check('init: canonical matches', F.getCanonicalValue(el), '1250000');
}

{
    var el = newField('');
    check('init: empty stays empty', el.value, '');
    check('init: empty canonical', F.getCanonicalValue(el), '');
}

{
    var el = newField('1000');
    F.setCanonicalValue(el, '500000.9876');
    check('setCanonicalValue renders full precision (no 2dp rounding)', el.value, '500.000,9876');
    check('setCanonicalValue canonical', F.getCanonicalValue(el), '500000.9876');
    check('setCanonicalValue clears invalid state', isInvalid(el), false);
}

{
    var el = newField('0');
    F.setCanonicalValue(el, -50);
    check('setCanonicalValue rejects a negative programmatic value as empty', el.value, '');
}

// ============================================================
// Real-time behavior: display never waits for blur (spec scenarios)
// ============================================================

function typeSequence(el, text) {
    for (var i = 0; i < text.length; i++) {
        typeAt(el, el.selectionStart, text.charAt(i));
    }
}

{
    var el = newField('');
    typeSequence(el, '1250000');
    check('real-time: whole amount formats as digits are typed', el.value, '1.250.000');
    check('real-time: canonical while typing', F.getCanonicalValue(el), '1250000');

    wrap(el).trigger('focus');
    check('focus does not reveal raw text', el.value, '1.250.000');
    wrap(el).trigger('blur');
    check('blur does not change display', el.value, '1.250.000');
    check('blur does not change canonical', F.getCanonicalValue(el), '1250000');
}

{
    // Operator types "." as the decimal key; it renders as ",".
    var el = newField('');
    typeSequence(el, '120000.');
    check('incomplete decimal displays trailing comma', el.value, '120.000,');
    check('incomplete decimal canonical retains trailing dot', F.getCanonicalValue(el), '120000');
    typeSequence(el, '23');
    check('completed decimal display', el.value, '120.000,23');
    check('completed decimal canonical', F.getCanonicalValue(el), '120000.23');
}

// ============================================================
// 1.4 Digits-plus-one-dot gate: silent whole-operation rejection
// ============================================================

{
    var el = newField('120000.23');
    var before = el.value;
    var beforeCaret = { start: 5, end: 5 };
    el.selectionStart = beforeCaret.start;
    el.selectionEnd = beforeCaret.end;

    var evt = typeAt(el, 5, '.'); // second "."
    check('second decimal point: display unchanged', el.value, before);
    check('second decimal point: canonical unchanged', F.getCanonicalValue(el), '120000.23');
    check('second decimal point: caret unchanged', el.selectionStart, 5);
    check('second decimal point: no invalid class', isInvalid(el), false);
}

{
    var el = newField('120000.23');
    var before = el.value;
    typeAt(el, 3, 'x');
    check('letter insertion: display unchanged', el.value, before);
    check('letter insertion: canonical unchanged', F.getCanonicalValue(el), '120000.23');
    check('letter insertion: no invalid class', isInvalid(el), false);
}

{
    var el = newField('120000.23');
    var before = el.value;
    typeAt(el, 3, ' ');
    check('whitespace insertion rejected', el.value, before);
    typeAt(el, 3, ',');
    check('comma insertion rejected', el.value, before);
    typeAt(el, 3, '-');
    check('sign insertion rejected', el.value, before);
    typeAt(el, 3, 'e');
    check('exponent marker insertion rejected', el.value, before);
}

{
    // Paste of already-localized text (e.g. "120.000,23") is rejected -- the grammar only
    // permits digits and one "." decimal key, matching what the operator types.
    var el = newField('');
    pasteAt(el, 0, 0, '120.000,23');
    check('paste of localized text is rejected entirely', el.value, '');
    check('paste of localized text: canonical unchanged', F.getCanonicalValue(el), '');
}

{
    // Valid paste: digits with at most one "." decimal separator.
    var el = newField('');
    pasteAt(el, 0, 0, '120000.23');
    check('paste of digits+one dot is accepted', el.value, '120.000,23');
    check('paste of digits+one dot canonical', F.getCanonicalValue(el), '120000.23');
}

{
    // Pasting "1.5" after canonical "100" appends those tokens (digits then the decimal point
    // then more digits) onto the existing canonical string: "100" + "1.5" -> "1001.5".
    var el = newField('100');
    pasteAt(el, 3, 3, '1.5'); // one dot in the pasted text is fine (still digits-plus-one-dot)
    check('paste appending fractional part: canonical', F.getCanonicalValue(el), '1001.5');
    check('paste appending fractional part: display', el.value, '1.001,5');
}

{
    var el = newField('100.5');
    var before = el.value;
    pasteAt(el, 0, el.value.length, '1..5'); // two dots: whole paste rejected
    check('paste with two dots rejected entirely', el.value, before);
}

// ============================================================
// 1.5 Logical caret/selection mapping across regrouping boundaries
// ============================================================

{
    // "1000" -> typing "0" before the grouping separator boundary.
    var el = newField('100');
    check('pre: display', el.value, '100');
    typeAt(el, 3, '0'); // append -> "1000" -> displays "1.000"
    check('after append crossing grouping boundary: display', el.value, '1.000');
    check('after append: canonical', F.getCanonicalValue(el), '1000');
    // caret should land after all 4 digits, i.e. logical index 4 -> display offset 5 (with the ".").
    check('after append: caret placed after inserted digit (display offset)', el.selectionStart, 5);
}

{
    // Insert in the middle of a grouped display, before the separator.
    var el = newField('12000'); // displays "12.000"
    check('pre-mid display', el.value, '12.000');
    // Insert '9' right after the leading "1" (display offset 1, before the "2").
    typeAt(el, 1, '9');
    check('mid-insert before separator: canonical', F.getCanonicalValue(el), '192000');
    check('mid-insert before separator: display', el.value, '192.000');
}

{
    // Backspace across a grouping-separator boundary removes the preceding digit, not the dot.
    var el = newField('1000'); // displays "1.000"
    var groupDot = el.value.indexOf('.');
    check('sanity: grouping dot present', groupDot > -1, true);
    // Caret right after the grouping dot (start of "000"), backspacing should remove the "1"
    // digit before the dot, not delete the dot itself as a token.
    backspaceAt(el, groupDot + 1);
    // Caret sat right after the grouping dot (a display-only separator, not a logical token), so
    // Backspace removes the preceding logical digit "1", leaving the remaining "000" digits
    // (canonical does not strip leading zeros mid-edit) rather than deleting the dot itself.
    check('backspace across grouping boundary: canonical', F.getCanonicalValue(el), '000');
    check('backspace across grouping boundary: display', el.value, '000');
}

{
    var el = newField('1000'); // "1.000"
    backspaceAt(el, el.value.length); // remove last digit
    check('backspace at end: canonical', F.getCanonicalValue(el), '100');
    check('backspace at end: display', el.value, '100');
}

{
    var el = newField('120000.23'); // "120.000,23"
    // Delete forward from just before the comma removes the comma's *canonical* token (the
    // decimal point itself), merging the fractional digits back into the integer part.
    var commaIdx = el.value.indexOf(',');
    deleteForwardAt(el, commaIdx);
    check('delete-forward on decimal point merges fraction into integer', F.getCanonicalValue(el), '12000023');
}

{
    var el = newField('120000.23'); // "120.000,23"
    // Selection-replace: select "000,23" and replace with "5".
    var start = el.value.indexOf('0', 4); // somewhere in the grouped zeros
    var full = el.value;
    replaceSelection(el, 4, full.length, '5');
    check('selection replace produces valid regrouped canonical', /^\d+(\.\d+)?$/.test(F.getCanonicalValue(el)), true);
}

{
    // Navigation (collapsed selection, no insert/delete) must never alter canonical/display.
    var el = newField('120000.23');
    var before = el.value;
    el.selectionStart = 2;
    el.selectionEnd = 2;
    check('caret navigation alone does not change display', el.value, before);
    check('caret navigation alone does not change canonical', F.getCanonicalValue(el), '120000.23');
}

// ============================================================
// Submission / finalize semantics: trailing "." normalizes to whole value
// ============================================================

{
    var el = newField('');
    typeSequence(el, '1200.');
    check('incomplete decimal display before finalize', el.value, '1.200,');
    // Canonical read for submission purposes must resolve to whole value 1200, not "1200.".
    var canonical = F.getCanonicalValue(el);
    check('incomplete decimal finalized canonical has no trailing dot', canonical.indexOf('.'), -1);
    check('incomplete decimal finalized canonical value', canonical, '1200');
}

// ============================================================
// validateScope / isValid compatibility
// ============================================================

{
    var a = newField('100');
    var b = newField('200.50');
    check('validateScope: all valid returns true', F.validateScope([a, b]), true);
}

// ============================================================
// Idempotent enhance()
// ============================================================

{
    var el = newField('500');
    var beforeVal = el.value;
    F.enhance(el); // second call must be a no-op
    check('enhance is idempotent: display unchanged', el.value, beforeVal);
    check('enhance is idempotent: canonical unchanged', F.getCanonicalValue(el), '500');
}

// ============================================================
// Real-time 'financial-amount:change' notification reaches both native and jQuery consumers
// ============================================================

{
    // Regression: accepted edits render by cancelling beforeinput, so native 'input' never
    // fires on that path. Integrations (e.g. unit-configuration's hidden-input sync, which
    // subscribes via native addEventListener) and jQuery-delegated totals (which subscribe via
    // .on()) both depend on 'financial-amount:change' actually reaching them.
    var el = newField('100');

    var nativeCalls = 0;
    el.addEventListener('financial-amount:change', function () {
        nativeCalls++;
    });

    var jqueryCalls = 0;
    wrap(el).on('financial-amount:change', function () {
        jqueryCalls++;
    });

    typeAt(el, 3, '5'); // "100" -> "1005"
    check('financial-amount:change reaches native addEventListener consumer', nativeCalls, 1);
    check('financial-amount:change reaches jQuery .on() consumer', jqueryCalls, 1);
    check('typed edit applied: canonical', F.getCanonicalValue(el), '1005');

    // A rejected edit (whole operation silently ignored) must not fire the change event.
    typeAt(el, 0, 'a');
    check('rejected edit does not fire financial-amount:change (native)', nativeCalls, 1);
    check('rejected edit does not fire financial-amount:change (jQuery)', jqueryCalls, 1);
}

// ============================================================
// PaymentAmountInput compatibility alias (payment views keep working unchanged)
// ============================================================

{
    check('PaymentAmountInput aliases FinancialInput', P, F);

    var el = makeElement('input');
    el.setAttribute = el.setAttribute || function () {};
    // Simulate the [data-payment-amount] marker attribute presence is not checked by enhance()
    // directly (callers invoke enhance()/init() themselves) -- verify enhance()/get/set still work
    // through the compatibility alias.
    el.value = '1250000';
    P.enhance(el);
    check('compat alias enhance(): display', el.value, '1.250.000');
    check('compat alias getCanonicalValue()', P.getCanonicalValue(el), '1250000');

    P.setCanonicalValue(el, '2000');
    check('compat alias setCanonicalValue()', el.value, '2.000');

    check('compat alias exposes INVALID_CLASS', P.INVALID_CLASS, F.INVALID_CLASS);
}

console.log('OK: ' + passed + ' assertions passed');
