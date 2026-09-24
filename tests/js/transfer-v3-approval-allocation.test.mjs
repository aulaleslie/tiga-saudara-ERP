// Unit tests for the Stock Transfer v3 approval workspace helpers.
// Run: node --test tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const context = { globalThis: {} };
context.globalThis = context;
vm.runInNewContext(readFileSync(new URL('../../resources/js/transfer/v3-approval-allocation.js', import.meta.url), 'utf8'), context);
const { stockText, renumber } = context.V3TransferApproval;

test('stock text shows availability only when the map has no tax split', () => {
    const map = { 7: { available: 10 } };
    assert.equal(stockText(map, 7), 'Stok tersedia: 10');
    assert.equal(stockText(map, '7'), 'Stok tersedia: 10');
});

test('stock text includes the tax split only when the server supplied it', () => {
    const map = { 7: { available: 10, tax: 4, non_tax: 6 } };
    assert.equal(stockText(map, 7), 'Stok tersedia: 10 (pajak 4, non-pajak 6)');
    assert.equal(stockText({ 7: { available: 10, tax: 4 } }, 7), 'Stok tersedia: 10', 'partial split is not shown');
});

test('stock text is empty for no selection or unknown source', () => {
    const map = { 7: { available: 10 } };
    assert.equal(stockText(map, ''), '');
    assert.equal(stockText(map, null), '');
    assert.equal(stockText(map, 99), '');
    assert.equal(stockText(null, 7), '');
});

test('renumber rewrites only the row index of cloned inputs', () => {
    assert.equal(renumber('rows[0][source_location_id]', 5), 'rows[5][source_location_id]');
    assert.equal(renumber('rows[12][quantity]', 13), 'rows[13][quantity]');
    assert.equal(renumber('serial_destinations[3:4]', 9), 'serial_destinations[3:4]');
});

// ---------------------------------------------------------------------------
// Summary modal readiness and fallback (?review=1).
// ---------------------------------------------------------------------------
const { whenScriptsReady, hasModalPlugin, showSummary } = context.V3TransferApproval;

function fakeDocument(readyState) {
    const listeners = [];
    return {
        readyState,
        addEventListener: (type, cb, opts) => listeners.push({ type, cb, opts }),
        fire(type) { listeners.filter((l) => l.type === type).forEach((l) => l.cb()); },
        listeners,
    };
}

function fakeJQuery({ withPlugin = true, throws = false } = {}) {
    const calls = [];
    const $ = (el) => ({ modal: (arg) => { if (throws) { throw new Error('boom'); } calls.push([el, arg]); } });
    $.fn = withPlugin ? { modal() {} } : {};
    return { $, calls };
}

function fakeFallback() {
    return { hidden: true, focused: false, focus() { this.focused = true; } };
}

test('while parsing, the summary waits for DOMContentLoaded (after deferred module scripts run)', () => {
    const doc = fakeDocument('loading');
    let ran = 0;
    whenScriptsReady(doc, () => { ran += 1; });
    assert.equal(ran, 0, 'not run during parsing, when app.js has not registered CoreUI yet');
    assert.equal(doc.listeners[0].type, 'DOMContentLoaded');
    assert.equal(doc.listeners[0].opts.once, true);
    doc.fire('DOMContentLoaded');
    assert.equal(ran, 1);
});

test('after parsing, the summary opens immediately', () => {
    for (const state of ['interactive', 'complete']) {
        let ran = 0;
        whenScriptsReady(fakeDocument(state), () => { ran += 1; });
        assert.equal(ran, 1, state);
    }
});

test('plugin detection requires jQuery.fn.modal to be a function', () => {
    assert.equal(hasModalPlugin(undefined), false);
    assert.equal(hasModalPlugin(fakeJQuery({ withPlugin: false }).$), false);
    assert.equal(hasModalPlugin(fakeJQuery().$), true);
});

test('with the CoreUI plugin, the modal is shown and the fallback stays hidden', () => {
    const { $, calls } = fakeJQuery();
    const modal = {};
    const fallback = fakeFallback();
    assert.equal(showSummary({ jQuery: $, modal, fallback }), 'modal');
    assert.deepEqual(calls.map((c) => c[1]), ['show']);
    assert.equal(calls[0][0], modal);
    assert.equal(fallback.hidden, true);
});

test('without the plugin (e.g. app.js failed to load), the inline fallback is revealed and focused', () => {
    const fallback = fakeFallback();
    assert.equal(showSummary({ jQuery: fakeJQuery({ withPlugin: false }).$, modal: {}, fallback }), 'fallback');
    assert.equal(fallback.hidden, false);
    assert.equal(fallback.focused, true);

    const noJQuery = fakeFallback();
    assert.equal(showSummary({ jQuery: undefined, modal: {}, fallback: noJQuery }), 'fallback');
    assert.equal(noJQuery.hidden, false);
});

test('if showing the modal throws, the fallback is revealed instead', () => {
    const fallback = fakeFallback();
    assert.equal(showSummary({ jQuery: fakeJQuery({ throws: true }).$, modal: {}, fallback }), 'fallback');
    assert.equal(fallback.hidden, false);
});

test('opening the summary again (Lihat Ringkasan) shows the modal again', () => {
    const { $, calls } = fakeJQuery();
    const opts = { jQuery: $, modal: {}, fallback: fakeFallback() };
    showSummary(opts);
    showSummary(opts);
    assert.equal(calls.length, 2);
});
