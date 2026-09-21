/**
 * Reproducible check for public/js/payment-amount-input.js.
 *
 * No test framework is wired into this repo for JS, so this is a minimal
 * assert-based script driven by a small dependency-free fake-jQuery harness
 * (tests/js/lib/mini-jquery.cjs) that actually exercises the real focus/blur/
 * input handlers and DOM-visible state (value/classes), not just the pure
 * parsing helpers.
 *
 * Run with: node tests/js/payment-amount-input.test.cjs
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { makeElement, wrap, createFakeJQuery } = require('./lib/mini-jquery.cjs');

global.window = {};
global.document = {};
global.jQuery = createFakeJQuery();

require(path.join(__dirname, '..', '..', 'public', 'js', 'payment-amount-input.js'));

const P = global.window.PaymentAmountInput;
let passed = 0;

function check(label, actual, expected) {
    assert.strictEqual(actual, expected, label + ': expected ' + JSON.stringify(expected) + ' got ' + JSON.stringify(actual));
    passed++;
}

function newField(initialValue) {
    var el = makeElement('input');
    el.value = initialValue === undefined ? '' : initialValue;
    P.enhance(el);
    return el;
}

function focus(el) {
    wrap(el).trigger('focus');
}

function blur(el) {
    wrap(el).trigger('blur');
}

function type(el, text) {
    el.value = text;
    wrap(el).trigger('input');
}

function isInvalid(el) {
    return !!el._classes[P.INVALID_CLASS];
}

// --- Pure formatting/parsing scenarios (spec scenarios) ---

check('format whole', P.formatDisplay('1250000'), '1.250.000');
check('format fractional', P.formatDisplay('1250000.5'), '1.250.000,50');
check('format 1500.10', P.formatDisplay('1500.10'), '1.500,10');
check('format 1500', P.formatDisplay('1500'), '1.500');

// --- Initialization from server-rendered value ---

{
    var el = newField('1250000');
    check('init: blurred display for whole value', el.value, '1.250.000');
    check('init: not invalid', isInvalid(el), false);
}

{
    var el = newField('1250000.5');
    check('init: blurred display for fractional value', el.value, '1.250.000,50');
}

{
    var el = newField('');
    check('init: empty stays empty', el.value, '');
    check('init: empty is valid', P.getCanonicalValue(el), '');
}

// --- Focus reveals canonical, blur re-localizes (spec scenario) ---

{
    var el = newField('1250000');
    focus(el);
    check('focus: reveals raw canonical', el.value, '1250000');
    blur(el);
    check('blur: re-localizes', el.value, '1.250.000');
    focus(el);
    check('focus again: raw canonical restored', el.value, '1250000');
}

{
    var el = newField('1250000.5');
    blur(el); // already blurred at init; explicit blur is a no-op here but exercises the handler
    focus(el);
    check('focus: fractional raw canonical', el.value, '1250000.5');
    blur(el);
    check('blur: fractional localized', el.value, '1.250.000,50');
}

// --- Arbitrary canonical precision is preserved independently from the 2dp display ---

{
    var el = newField('1000');
    focus(el);
    type(el, '1000.999');
    check('typing 1000.999 while focused is valid', isInvalid(el), false);
    check('full precision is canonical while focused', P.getCanonicalValue(el), '1000.999');

    blur(el);
    check('blur rounds only the display to two decimals', el.value, '1.001,00');
    check('blur preserves full canonical precision', P.getCanonicalValue(el), '1000.999');

    focus(el);
    check('focus restores every entered fractional digit', el.value, '1000.999');
    check('restored arbitrary-precision value remains canonical', P.getCanonicalValue(el), '1000.999');
}

{
    var el = newField('1.23456789');
    check('initial arbitrary precision displays rounded to 2dp', el.value, '1,23');
    check('initial arbitrary precision remains canonical', P.getCanonicalValue(el), '1.23456789');
    focus(el);
    check('initial arbitrary precision is fully restored on focus', el.value, '1.23456789');
}

// --- Regression: invalid text must survive being focused, not get erased ---

{
    var el = newField('100000');
    blur(el);
    focus(el);
    type(el, '1000.99x');
    blur(el);
    check('invalid non-empty blur is flagged', isInvalid(el), true);
    check('invalid text is preserved on blur, not erased', el.value, '1000.99x');

    focus(el);
    check('focusing an invalid field preserves the invalid text', el.value, '1000.99x');
    check('focusing an invalid field keeps it flagged invalid', isInvalid(el), true);
    check('invalid field has no canonical fallback', P.getCanonicalValue(el), null);
    check('validateScope rejects invalid field after refocus', P.validateScope([el]), false);
}

// --- Regression: negative amounts are rejected ---

{
    var el = newField('');
    focus(el);
    type(el, '-100');
    check('negative canonical input is invalid', P.getCanonicalValue(el), null);
    check('negative canonical input is flagged', isInvalid(el), true);
}

{
    var el = newField('-1.000,50');
    check('negative initial localized input is rejected', el.value, '');
}

// --- More than two decimals remain accepted canonically ---

{
    var el = newField('');
    focus(el);
    type(el, '1.005');
    check('3+ canonical decimals accepted while focused', P.getCanonicalValue(el), '1.005');
    blur(el);
    check('3+ decimals render at two display places', el.value, '1,00');
    check('3+ decimals remain unrounded canonically', P.getCanonicalValue(el), '1.005');
}

// --- validateScope() flags every invalid enhanced field within a scope and returns false ---

{
    var goodA = newField('100');
    var badB = newField('100');
    focus(badB);
    type(badB, 'xyz');
    blur(badB);
    var goodC = newField('50');

    var scopeElements = [goodA, badB, goodC];
    var ok = P.validateScope(scopeElements);

    check('validateScope returns false when any field is invalid', ok, false);
    check('validateScope flags the invalid field', isInvalid(badB), true);
    check('validateScope does not flag valid fields', isInvalid(goodA), false);
    check('validateScope does not flag valid fields (2)', isInvalid(goodC), false);
}

{
    var a = newField('100');
    var b = newField('200.50');
    var ok = P.validateScope([a, b]);
    check('validateScope returns true when all fields are valid', ok, true);
}

// --- setCanonicalValue(): programmatic set (e.g. clamping to a max) renders blurred display ---

{
    var el = newField('999999');
    P.setCanonicalValue(el, '500000.9876');
    check('setCanonicalValue renders rounded localized display', el.value, '500.000,99');
    check('setCanonicalValue clears invalid state', isInvalid(el), false);
    check('setCanonicalValue retains full canonical precision', P.getCanonicalValue(el), '500000.9876');
}

{
    var el = newField('0');
    P.setCanonicalValue(el, -50);
    check('setCanonicalValue rejects a negative programmatic value as empty', el.value, '');
}

console.log('OK: ' + passed + ' assertions passed');
