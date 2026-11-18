const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.resolve(__dirname, '../../Resources/views/products/index.blade.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)?.[1]
    .replace("@json(route('products.index'))", '"http://example.test/products"');

test('a failed product status request stays on the page, restores the button and shows feedback', async () => {
    assert.ok(script, 'Product index must include its status handler');

    const listeners = {};
    const feedback = { children: [], innerHTML: '', appendChild(child) { this.children.push(child); } };
    const document = {
        addEventListener(type, listener) { listeners[type] = listener; },
        getElementById(id) { return id === 'product-status-feedback' ? feedback : null; },
        querySelectorAll() { return []; },
        createElement() {
            return { className: '', children: [], setAttribute() {}, appendChild(child) { this.children.push(child); } };
        },
        createTextNode(text) { return { text }; },
    };
    const button = { disabled: false };
    const form = {
        action: 'http://example.test/products/1/toggle-status',
        dataset: { productActive: '1', productName: 'Produk Uji' },
        querySelector() { return button; },
    };
    let fetchCalls = 0;
    const location = { href: 'http://example.test/products' };
    vm.runInNewContext(script, {
        document,
        window: { confirm: () => true, location },
        FormData: class { constructor(receivedForm) { assert.equal(receivedForm, form); } },
        fetch: async () => { fetchCalls++; throw new Error('Jaringan gagal'); },
        URL,
    });
    assert.equal(typeof listeners.DOMContentLoaded, 'function');
    listeners.DOMContentLoaded();
    assert.equal(typeof listeners.submit, 'function');

    let prevented = false;
    await listeners.submit({
        target: { closest: () => form },
        preventDefault() { prevented = true; },
    });

    assert.equal(prevented, true);
    assert.equal(fetchCalls, 1);
    assert.equal(button.disabled, false);
    assert.equal(location.href, 'http://example.test/products');
    assert.match(feedback.children[0].className, /alert-danger/);
    assert.equal(feedback.children[0].children[0].text, 'Jaringan gagal');
});
