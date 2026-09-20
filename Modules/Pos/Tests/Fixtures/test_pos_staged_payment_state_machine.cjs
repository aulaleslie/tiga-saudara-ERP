const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

function createMockElement(id) {
    const classList = new Set();
    const listeners = {};
    const children = [];
    return {
        id: id,
        dataset: {},
        value: '',
        textContent: '',
        innerHTML: '',
        style: {},
        disabled: false,
        children: children,
        classList: {
            add: (...cls) => cls.forEach(c => classList.add(c)),
            remove: (...cls) => cls.forEach(c => classList.delete(c)),
            contains: (c) => classList.has(c)
        },
        addEventListener: (event, handler) => {
            listeners[event] = listeners[event] || [];
            listeners[event].push(handler);
        },
        removeEventListener: () => {},
        trigger: async (event, eventObj = {}) => {
            if (listeners[event]) {
                for (const handler of listeners[event]) {
                    await handler(eventObj);
                }
            }
        },
        querySelectorAll: () => [],
        querySelector: () => null,
        appendChild: (el) => children.push(el),
        contains: () => false,
        focus: () => {}
    };
}

const elements = {};

const httpCalls = [];

const mockFetch = async (url, options = {}) => {
    const callRecord = {
        url,
        method: options.method || 'GET',
        body: options.body ? (typeof options.body === 'string' ? JSON.parse(options.body) : options.body) : null
    };
    httpCalls.push(callRecord);

    if (url.includes('/pos/sell/payment-image') && options.method === 'POST') {
        return {
            ok: true,
            status: 200,
            json: async () => ({
                token: 'tok_upload_abc123',
                original_name: 'receipt.jpg',
                size: 1024,
                expires_at: '2026-09-21T00:00:00Z'
            })
        };
    }

    if (url.includes('/pos/sell/checkout/stage-payment') && options.method === 'POST') {
        return {
            ok: true,
            status: 200,
            json: async () => ({
                remainder: 40000,
                payment_chain: {
                    original_grand_total: 100000,
                    remainder: 40000,
                    payments: [
                        {
                            method_id: 1,
                            method_name: 'Transfer',
                            amount: 60000,
                            payment_image: {
                                token: 'tok_upload_abc123',
                                original_name: 'receipt.jpg',
                                size: 1024
                            }
                        }
                    ]
                }
            })
        };
    }

    if (url.includes('/pos/sell/payment-image') && options.method === 'DELETE') {
        return {
            ok: true,
            status: 200,
            json: async () => ({ message: 'Gambar berhasil dihapus.' })
        };
    }

    return {
        ok: true,
        status: 200,
        json: async () => ({})
    };
};

const context = {
    window: {},
    document: {
        getElementById: (id) => elements[id] || (elements[id] = createMockElement(id)),
        querySelectorAll: () => [],
        addEventListener: () => {},
        removeEventListener: () => {},
        querySelector: (sel) => sel.includes('meta') ? { content: 'mock-csrf' } : createMockElement(sel),
        createElement: (tag) => createMockElement('created-' + tag)
    },
    console: console,
    fetch: mockFetch,
    $: () => ({ modal: () => {} }),
    escapeHtml: (str) => str,
    formatPrice: (num) => 'Rp ' + num,
    FormData: class FormData {
        constructor() { this.entries = {}; }
        append(k, v) { this.entries[k] = v; }
    },
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    POS_PAYMENT_METHODS: [
        { id: 1, name: 'Transfer', is_cash: false, requires_reference: false },
        { id: 2, name: 'Tunai', is_cash: true, requires_reference: false }
    ]
};

context.window = context;

const scriptPath = path.resolve(__dirname, '../../../../public/js/pos-staged-payment.js');
const code = fs.readFileSync(scriptPath, 'utf8');
vm.createContext(context);
vm.runInContext(code, context);

async function runBrowserRegressionTests() {
    // 1. Initialize PosStagedPayment
    context.PosStagedPayment.initialize();
    await context.PosStagedPayment.loadPaymentMethods();

    // 2. Open Modal with cart
    await context.PosStagedPayment.openModal('cart-uuid-001', 100000);

    // 3. Select Transfer method from dropdown
    await elements['staged-method-search'].trigger('focus');
    const transferOption = elements['staged-method-results'].children.find(c => c.textContent === 'Transfer');
    assert.ok(transferOption, 'Transfer payment method option should be rendered in dropdown');
    await transferOption.trigger('click', { preventDefault: () => {} });

    elements['staged-amount-input'].value = '60000';
    elements['staged-amount-input'].dataset.rawValue = '60000';

    // 4. Simulate Uploading Image
    const fakeFile = { type: 'image/jpeg', size: 1000, name: 'receipt.jpg' };
    await elements['staged-payment-image-file'].trigger('change', { target: { files: [fakeFile] } });

    // Assert upload POST call happened
    const uploadCalls = httpCalls.filter(c => c.url.includes('/pos/sell/payment-image') && c.method === 'POST');
    assert.strictEqual(uploadCalls.length, 1, 'Expected 1 image upload POST call');

    // 5. Submit stage payment (Transfer 60k with image tok_upload_abc123)
    httpCalls.length = 0;
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    // Verify stage payment POST was sent with payment_image_token
    const stageCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/stage-payment') && c.method === 'POST');
    assert.strictEqual(stageCalls.length, 1, 'Expected 1 stage-payment POST call');
    assert.strictEqual(stageCalls[0].body.payment_image_token, 'tok_upload_abc123', 'Stage payload must include uploaded token');

    // CRITICAL REGRESSION ASSERTION (Task 1.1 / Finding 1):
    // A successful stage transition must NOT issue DELETE /pos/sell/payment-image for the committed token!
    const deleteCallsAfterCommit = httpCalls.filter(c => c.url.includes('/pos/sell/payment-image') && c.method === 'DELETE');
    assert.strictEqual(deleteCallsAfterCommit.length, 0, 'CRITICAL: Successful stage transition must NEVER issue a DELETE request for committed image token');

    // 6. Test Processing Lock (Finding 2)
    assert.strictEqual(elements['staged-payment-image-file'].disabled, false, 'Image file input should be re-enabled after processing finishes');
    assert.strictEqual(elements['staged-payment-image-remove-btn'].disabled, false, 'Image remove button should be re-enabled after processing finishes');

    // 7. Test Explicit Pending Removal (Task 1.2)
    await elements['staged-payment-image-file'].trigger('change', { target: { files: [fakeFile] } });
    httpCalls.length = 0;
    await elements['staged-payment-image-remove-btn'].trigger('click');

    const deletePendingCalls = httpCalls.filter(c => c.url.includes('/pos/sell/payment-image') && c.method === 'DELETE');
    assert.strictEqual(deletePendingCalls.length, 1, 'Explicit remove button must issue DELETE for uncommitted upload');
    assert.strictEqual(deletePendingCalls[0].body.token, 'tok_upload_abc123');

    console.log('SUCCESS');
}

runBrowserRegressionTests().catch(err => {
    console.error('FAILED JS REGRESSION TEST:', err);
    process.exit(1);
});
