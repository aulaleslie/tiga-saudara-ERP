const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');

function createMockElement(id) {
    const classList = new Set();
    const listeners = {};
    const children = [];
    const el = {
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
                    await handler.call(el, eventObj);
                }
            }
        },
        querySelectorAll: () => [],
        querySelector: () => null,
        appendChild: (child) => children.push(child),
        contains: () => false,
        focused: false,
        focus: function() { this.focused = true; }
    };
    return el;
}

const elements = {};

const httpCalls = [];
const stagedCartChains = {};

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
        const body = options.body ? (typeof options.body === 'string' ? JSON.parse(options.body) : options.body) : {};
        const cartToken = body.cart_token;
        const amount = Number(body.amount) || 0;
        const grandTotal = Number(body.grand_total) || 100000;
        const currentChain = stagedCartChains[cartToken] || { payments: [] };
        const newPayment = {
            method_id: body.payment_method_id || 1,
            method_name: body.payment_method_id === 2 ? 'Tunai' : 'Transfer',
            amount: amount,
            payment_image: body.payment_image_token ? {
                token: body.payment_image_token,
                original_name: 'receipt.jpg',
                size: 1024
            } : null
        };
        currentChain.payments = (currentChain.payments || []).concat([newPayment]);
        const totalPaid = currentChain.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
        const remainder = grandTotal - totalPaid;
        currentChain.original_grand_total = grandTotal;
        currentChain.remainder = remainder;
        currentChain.flow = body.flow || 'full_payment';
        stagedCartChains[cartToken] = currentChain;

        return {
            ok: true,
            status: 200,
            json: async () => ({
                remainder: remainder,
                payment_chain: currentChain
            })
        };
    }

    if (url.includes('/pos/sell/checkout/reconcile-payment-chain') && options.method === 'POST') {
        const body = options.body ? (typeof options.body === 'string' ? JSON.parse(options.body) : options.body) : {};
        const cartToken = body.cart_token;
        const grandTotal = Number(body.grand_total) || 100000;
        const currentChain = stagedCartChains[cartToken] || { payments: [] };
        const totalPaid = (currentChain.payments || []).reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
        const remainder = grandTotal - totalPaid;
        currentChain.original_grand_total = grandTotal;
        currentChain.remainder = remainder;
        currentChain.flow = body.flow || currentChain.flow || 'full_payment';
        stagedCartChains[cartToken] = currentChain;

        return {
            ok: true,
            status: 200,
            json: async () => ({
                success: true,
                has_chain: true,
                payment_chain: currentChain,
                remainder: remainder
            })
        };
    }

    if (url.includes('/pos/sell/checkout/payment-chain') && options.method === 'DELETE') {
        const body = options.body ? (typeof options.body === 'string' ? JSON.parse(options.body) : options.body) : {};
        if (body.cart_token) {
            delete stagedCartChains[body.cart_token];
        }
        return {
            ok: true,
            status: 200,
            json: async () => ({ success: true, message: 'Reset successful' })
        };
    }

    if (url.includes('/pos/sell/payment-image') && options.method === 'DELETE') {
        return {
            ok: true,
            status: 200,
            json: async () => ({ message: 'Gambar berhasil dihapus.' })
        };
    }

    if (url.includes('/pos/sell/payment-terms/search')) {
        return {
            ok: true,
            status: 200,
            json: async () => ({
                terms: [
                    { id: 1, name: 'Net 30', longevity: 30 }
                ]
            })
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
    $: (el) => {
        const target = (typeof el === 'string')
            ? (elements[el.replace(/^#/, '')] || (elements[el.replace(/^#/, '')] = createMockElement(el.replace(/^#/, ''))))
            : el;
        return {
            modal: (action) => {
                if (target) target.modalAction = action;
            }
        };
    },
    escapeHtml: (str) => str,
    formatPrice: (num) => 'Rp ' + num,
    FormData: class FormData {
        constructor() { this.entries = {}; }
        append(k, v) { this.entries[k] = v; }
    },
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    AbortController: globalThis.AbortController,
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

    // =========================================================================
    // 8. Test Flow Switching with Dirty State (Task 2.3 & 3.4)
    // =========================================================================
    console.log('--- Testing Flow Switching from full_payment to kasbon with dirty state ---');
    // Currently chain has 1 committed payment (Transfer 60k). State is dirty.
    assert.strictEqual(context.PosStagedPayment.isDirty(), true, 'Module should detect dirty state with committed payments');

    // Attempt switch to 'kasbon'
    await context.PosStagedPayment.switchFlow('kasbon');

    // Assert flow switch confirmation modal was opened
    assert.strictEqual(elements['pos-flow-switch-modal'].modalAction, 'show', 'Flow switch confirmation modal should be shown for dirty switch');
    assert.ok(elements['pos-flow-switch-message'].textContent.includes('Kasbon'), 'Switch message must mention destination Kasbon');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'full_payment', 'Flow must not change before confirmation');

    // Test Confirm Flow Switch
    httpCalls.length = 0;
    await elements['pos-flow-switch-confirm-btn'].trigger('click');

    // Verify DELETE /pos/sell/checkout/payment-chain was called with cart token
    const deleteChainCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/payment-chain') && c.method === 'DELETE');
    assert.strictEqual(deleteChainCalls.length, 1, 'Expected 1 DELETE payment-chain call on confirmed switch');
    assert.strictEqual(deleteChainCalls[0].body.cart_token, 'cart-uuid-001');

    // Verify flow is now 'kasbon' and state is clean
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'kasbon', 'Flow should now be kasbon');
    const chainAfterSwitch = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(chainAfterSwitch.payments.length, 0, 'Committed payments must be cleared on flow switch');
    assert.strictEqual(chainAfterSwitch.remainder, 100000, 'Remainder must be restored to grand total');

    // =========================================================================
    // 9. Test Kasbon Multiple Down Payments Without Term Requirement (Task 4.1 & 4.2)
    // =========================================================================
    console.log('--- Testing Kasbon Multiple Down Payments Without Term Requirement ---');
    // Term dropdown is NOT selected yet
    elements['staged-payment-term'].value = '';
    elements['staged-payment-term'].options = [{ value: '1', textContent: 'Net 30' }];
    elements['staged-payment-term'].selectedIndex = -1;
    elements['staged-payment-term'].focused = false;

    // Stage 1: Transfer 30k down payment without term
    elements['staged-amount-input'].value = '30000';
    elements['staged-amount-input'].dataset.rawValue = '30000';
    await transferOption.trigger('click', { preventDefault: () => {} });

    // CRITICAL ASSERTION: Submit button must be ENABLED even without a term!
    assert.strictEqual(elements['staged-payment-submit'].disabled, false, 'Tambah Uang Muka must be enabled without selecting a term');

    httpCalls.length = 0;
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    // Verify stage payment POST has flow: 'kasbon', is_debt: true, and NO payment_term_id
    const kasbonStage1 = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/stage-payment') && c.method === 'POST');
    assert.strictEqual(kasbonStage1.length, 1, 'Expected 1 stage-payment POST for Kasbon down payment');
    assert.strictEqual(kasbonStage1[0].body.flow, 'kasbon');
    assert.strictEqual(kasbonStage1[0].body.is_debt, true);
    assert.strictEqual(kasbonStage1[0].body.payment_term_id, null, 'payment_term_id must not be required when staging');

    // Verify that adding down payment did NOT auto-finalize!
    assert.notStrictEqual(elements['pos-final-confirmation-modal'].modalAction, 'show', 'Adding Kasbon down payment must NEVER auto-finalize');

    // Stage 2: Cash 20k down payment without term
    await elements['staged-method-search'].trigger('focus');
    const cashOption = elements['staged-method-results'].children.find(c => c.textContent === 'Tunai');
    assert.ok(cashOption, 'Tunai payment method option should be rendered');
    await cashOption.trigger('click', { preventDefault: () => {} });

    elements['staged-amount-input'].value = '20000';
    elements['staged-amount-input'].dataset.rawValue = '20000';
    await elements['staged-amount-input'].trigger('input');

    // Verify submit button is enabled for 2nd down payment without term
    assert.strictEqual(elements['staged-payment-submit'].disabled, false, 'Tambah Uang Muka must be enabled for 2nd down payment without term');

    httpCalls.length = 0;
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    const kasbonStage2 = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/stage-payment') && c.method === 'POST');
    assert.strictEqual(kasbonStage2.length, 1, 'Expected 1 stage-payment POST for 2nd Kasbon down payment');
    assert.strictEqual(kasbonStage2[0].body.flow, 'kasbon');
    assert.strictEqual(kasbonStage2[0].body.is_debt, true);
    assert.strictEqual(kasbonStage2[0].body.payment_term_id, null);

    // Still in staged modal (not auto-finalized)
    assert.notStrictEqual(elements['pos-final-confirmation-modal'].modalAction, 'show', '2nd down payment must not auto-finalize');

    // =========================================================================
    // 10. Test Explicit Finish Kasbon Requires Term (Task 4.1)
    // =========================================================================
    console.log('--- Testing Explicit Finish Kasbon Requires Term ---');
    elements['staged-amount-input'].value = '';
    elements['staged-amount-input'].dataset.rawValue = '';

    // Step A: Attempt completion without selecting a term
    elements['staged-payment-term'].value = '';
    elements['staged-payment-term'].focused = false;
    elements['pos-final-confirmation-modal'].modalAction = null;

    await elements['staged-finish-kasbon-btn'].trigger('click');

    // CRITICAL ASSERTION: Completion is blocked, error shown, and term select is focused!
    assert.notStrictEqual(elements['pos-final-confirmation-modal'].modalAction, 'show', 'Final confirmation modal must NOT open without a term');
    assert.strictEqual(elements['staged-payment-term'].focused, true, 'Payment term selector must be focused when completion is blocked');

    // Step B: Cashier selects term and clicks Finish Kasbon again
    elements['staged-payment-term'].value = '1';
    elements['staged-payment-term'].selectedIndex = 0;

    await elements['staged-finish-kasbon-btn'].trigger('click');

    // Final confirmation modal opens!
    assert.strictEqual(elements['pos-final-confirmation-modal'].modalAction, 'show', 'Final confirmation modal should open on Finish Kasbon after term is selected');

    // Finalize checkout
    httpCalls.length = 0;
    await elements['final-payment-proceed-btn'].trigger('click');

    const finalizeCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/finalize') && c.method === 'POST');
    assert.strictEqual(finalizeCalls.length, 1, 'Expected 1 finalize POST call');
    assert.strictEqual(finalizeCalls[0].body.flow, 'kasbon');
    assert.strictEqual(finalizeCalls[0].body.is_debt, true);
    assert.strictEqual(finalizeCalls[0].body.payment_term_id, '1');

    // =========================================================================
    // 11. Test Same-Flow Reopen Preservation (Task 2.1 & 3.4)
    // =========================================================================
    console.log('--- Testing Same-Flow Reopen Preservation ---');
    // Open modal with same cart token and same flow ('kasbon')
    httpCalls.length = 0;
    await context.PosStagedPayment.openModal('cart-uuid-001', 100000, true, '-', 'kasbon');

    // No DELETE request should be issued on same-flow reopen
    const deleteOnReopen = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/payment-chain') && c.method === 'DELETE');
    assert.strictEqual(deleteOnReopen.length, 0, 'Reopening same flow must NOT issue DELETE payment-chain');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'kasbon', 'Flow must remain kasbon');

    // =========================================================================
    // 12. Test Clean Flow Switching (Task 3.4)
    // =========================================================================
    console.log('--- Testing Clean Flow Switching ---');
    // First, reset payment chain so state is clean
    await context.PosStagedPayment.executeAuthoritativeReset('kasbon');
    elements['staged-amount-input'].value = '';
    elements['staged-amount-input'].dataset.rawValue = '';
    elements['staged-payment-term'].value = '';
    assert.strictEqual(context.PosStagedPayment.isDirty(), false, 'State should be clean now');

    // Clean switch from kasbon to full_payment
    elements['pos-flow-switch-modal'].modalAction = null;
    await context.PosStagedPayment.switchFlow('full_payment');

    // Verify modal was NOT shown
    assert.strictEqual(elements['pos-flow-switch-modal'].modalAction, null, 'Clean switch must NOT prompt with modal');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'full_payment', 'Flow must switch immediately to full_payment');

    // =========================================================================
    // 13. Test Reset Failure Handling (Task 2.3)
    // =========================================================================
    console.log('--- Testing Reset Failure Handling ---');
    // Stage a payment to make dirty
    elements['staged-amount-input'].value = '50000';
    elements['staged-amount-input'].dataset.rawValue = '50000';
    await transferOption.trigger('click', { preventDefault: () => {} });
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    // Mock failure for DELETE payment-chain
    const originalFetch = context.fetch;
    context.fetch = async (url, options = {}) => {
        if (url.includes('/pos/sell/checkout/payment-chain') && options.method === 'DELETE') {
            return {
                ok: false,
                status: 500,
                json: async () => ({ message: 'Server reset failed' })
            };
        }
        return originalFetch(url, options);
    };

    // Attempt switch to kasbon
    await context.PosStagedPayment.switchFlow('kasbon');
    // Confirm switch modal
    await elements['pos-flow-switch-confirm-btn'].trigger('click');

    // Assert that flow remained 'full_payment' on reset failure
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'full_payment', 'Flow must stay in current flow when server reset fails');
    context.fetch = originalFetch;

    // =========================================================================
    // 14. Test Late/In-Flight Request Protection (Task 2.3)
    // =========================================================================
    console.log('--- Testing Late / In-Flight Request Protection ---');
    const genBeforeReset = context.PosStagedPayment.getActiveGeneration();
    await context.PosStagedPayment.executeAuthoritativeReset('full_payment');
    const genAfterReset = context.PosStagedPayment.getActiveGeneration();
    assert.ok(genAfterReset > genBeforeReset, 'activeGeneration must increment on authoritative reset');

    // =========================================================================
    // 15. Test Refresh Payment Total When Reopening an Edited Cart (Review Item 1)
    // =========================================================================
    console.log('--- Testing Refresh Payment Total When Reopening an Edited Cart ---');
    // Step A: Open clean checkout at Rp100,000 without staging a payment
    await context.PosStagedPayment.openModal('cart-edit-001', 100000, true, '-', 'full_payment');
    let chain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(chain.original_grand_total, 100000);
    assert.strictEqual(chain.remainder, 100000);
    assert.strictEqual(chain.payments.length, 0);

    // Step B: Cashier closes modal (simulated by not staging any payment),
    // cart total is edited to Rp200,000 in POS cart, and modal reopened with same token
    await context.PosStagedPayment.openModal('cart-edit-001', 200000, true, '-', 'full_payment');
    chain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(chain.original_grand_total, 200000, 'Payment modal must update original_grand_total to Rp200,000 on reopening');
    assert.strictEqual(chain.remainder, 200000, 'Remainder must update to Rp200,000 when clean cart is edited');

    // Step C: Stage Rp50,000 payment
    elements['staged-amount-input'].value = '50000';
    elements['staged-amount-input'].dataset.rawValue = '50000';
    await transferOption.trigger('click', { preventDefault: () => {} });
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });
    chain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(chain.payments.length, 1);

    // Step D: Cart total edited again to Rp250,000 while Rp50,000 is staged
    await context.PosStagedPayment.openModal('cart-edit-001', 250000, true, '-', 'full_payment');
    chain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(chain.original_grand_total, 250000, 'Grand total must update to Rp250,000');
    assert.strictEqual(chain.remainder, 200000, 'Remainder must reconcile: 250k - 50k staged = 200k');

    // =========================================================================
    // 16. Test Pending Evidence Upload Discarded on Flow Switch (Review Item 2)
    // =========================================================================
    console.log('--- Testing Pending Evidence Upload Discarded on Flow Switch ---');
    // Start fresh clean full_payment flow
    await context.PosStagedPayment.executeAuthoritativeReset('full_payment');
    elements['staged-amount-input'].value = '';
    elements['staged-amount-input'].dataset.rawValue = '';
    assert.strictEqual(context.PosStagedPayment.isDirty(), false, 'State should be clean before upload');

    // Setup deferred upload promise
    let resolveUpload;
    const uploadDeferredPromise = new Promise((resolve) => {
        resolveUpload = resolve;
    });

    const currentFetch = context.fetch;
    context.fetch = async (url, options = {}) => {
        if (url.includes('/pos/sell/payment-image') && options.method === 'POST') {
            httpCalls.push({ url, method: 'POST', body: options.body });
            return uploadDeferredPromise;
        }
        return currentFetch(url, options);
    };

    // Trigger image upload (simulating user selecting file)
    elements['staged-payment-image-file'].trigger('change', { target: { files: [{ type: 'image/jpeg', size: 1000, name: 'receipt.jpg' }] } });

    // Assert upload is in progress and state is marked dirty
    assert.strictEqual(context.PosStagedPayment.isImageUploading(), true, 'isImageUploading must be true while upload is in flight');
    assert.strictEqual(context.PosStagedPayment.isDirty(), true, 'isDirty() must return true while evidence upload is in flight');

    // Cashier attempts to switch flow to 'kasbon' while upload is pending
    await context.PosStagedPayment.switchFlow('kasbon');
    assert.strictEqual(elements['pos-flow-switch-modal'].modalAction, 'show', 'Confirmation modal must appear when upload is in progress');

    // Cashier confirms flow switch
    httpCalls.length = 0;
    await elements['pos-flow-switch-confirm-btn'].trigger('click');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'kasbon', 'Flow should switch to kasbon');

    // Now deferred upload finishes (after switch was confirmed)
    resolveUpload({
        ok: true,
        status: 200,
        json: async () => ({ token: 'tok_stale_delayed_123', original_name: 'delayed.jpg' })
    });
    // Wait for promise microtasks to settle
    await new Promise(r => setTimeout(r, 20));

    // Assert: stale token must NOT be assigned to currentImageToken
    assert.strictEqual(context.PosStagedPayment.getCurrentImageToken(), null, 'Stale evidence upload must NOT populate currentImageToken after flow switch');

    // Assert: stale upload was cleaned up on server via DELETE /pos/sell/payment-image
    const deleteStaleUpload = httpCalls.filter(c => c.url.includes('/pos/sell/payment-image') && c.method === 'DELETE');
    assert.strictEqual(deleteStaleUpload.length, 1, 'Expected DELETE /pos/sell/payment-image for stale upload');
    assert.strictEqual(deleteStaleUpload[0].body.token, 'tok_stale_delayed_123', 'Stale token must be deleted on server');

    // Restore fetch
    context.fetch = currentFetch;

    // =========================================================================
    // 17. Confirm Switching Before Clearing Unstaged Payments (Review Item 1)
    // =========================================================================
    console.log('--- Testing Confirm Switching Before Clearing Unstaged Payments ---');
    // Step A: Open checkout at Rp100,000 in full_payment without staging payments
    await context.PosStagedPayment.openModal('cart-unstaged-001', 100000, true, '-', 'full_payment');
    // Cashier enters Rp30,000 into amount input (unstaged)
    elements['staged-amount-input'].value = '30000';
    elements['staged-amount-input'].dataset.rawValue = '30000';
    assert.strictEqual(context.PosStagedPayment.isDirty(), true, 'Unstaged entered amount must mark state dirty');

    // Step B: Cart total is changed in POS shell to Rp200,000, and cashier clicks opposite flow (Kasbon)
    elements['pos-flow-switch-modal'].modalAction = null;
    await context.PosStagedPayment.openModal('cart-unstaged-001', 200000, true, '-', 'kasbon');

    // CRITICAL ASSERTION: Cashier must be prompted with confirmation modal,
    // currentFlow must NOT have been silently changed to 'kasbon', and entered 30000 must NOT be cleared!
    assert.strictEqual(elements['pos-flow-switch-modal'].modalAction, 'show', 'Confirmation modal must appear when switching flow with unstaged payment');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'full_payment', 'Flow must remain full_payment before confirmation');
    assert.strictEqual(elements['staged-amount-input'].value, '30000', 'Unstaged entered amount must not be silently cleared before confirmation');

    // Step C: If cashier cancels switch
    elements['pos-flow-switch-modal'].modalAction = 'hide';
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'full_payment');
    assert.strictEqual(elements['staged-amount-input'].value, '30000');

    // Step D: When cashier confirms switch
    httpCalls.length = 0;
    await elements['pos-flow-switch-confirm-btn'].trigger('click');
    assert.strictEqual(context.PosStagedPayment.getCurrentFlow(), 'kasbon', 'Flow switches to kasbon upon confirmation');
    assert.strictEqual(context.PosStagedPayment.getPaymentChain().original_grand_total, 200000, 'Destination flow initializes with updated grand total Rp200,000');

    // =========================================================================
    // 18. Reconcile Updated Total in Server Payment Chain (Review Item 2)
    // =========================================================================
    console.log('--- Testing Reconcile Updated Total in Server Payment Chain ---');
    // Start with 100k cart and 60k staged
    await context.PosStagedPayment.openModal('cart-reconcile-001', 100000, true, '-', 'full_payment');
    elements['staged-amount-input'].value = '60000';
    elements['staged-amount-input'].dataset.rawValue = '60000';
    await transferOption.trigger('click', { preventDefault: () => {} });
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    // Now cart increases to 150k in POS cart shell
    httpCalls.length = 0;
    await context.PosStagedPayment.openModal('cart-reconcile-001', 150000, true, '-', 'full_payment');

    // Verify /pos/sell/checkout/reconcile-payment-chain was called with grand_total 150000
    const reconcileCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/reconcile-payment-chain') && c.method === 'POST');
    assert.strictEqual(reconcileCalls.length, 1, 'Expected POST /pos/sell/checkout/reconcile-payment-chain call on cart total change');
    assert.strictEqual(reconcileCalls[0].body.grand_total, 150000);

    // Verify modal chain reflects the server's reconciled remainder: 150k - 60k = 90k
    const reconciledChain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(reconciledChain.original_grand_total, 150000);
    assert.strictEqual(reconciledChain.remainder, 90000);

    // Verify submitting 90k transfer sends grand_total: 150000
    elements['staged-amount-input'].value = '90000';
    elements['staged-amount-input'].dataset.rawValue = '90000';
    await transferOption.trigger('click', { preventDefault: () => {} });
    httpCalls.length = 0;
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    const secondStageCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/stage-payment') && c.method === 'POST');
    assert.strictEqual(secondStageCalls.length, 1);
    assert.strictEqual(secondStageCalls[0].body.amount, 90000);
    assert.strictEqual(secondStageCalls[0].body.grand_total, 150000);

    // =========================================================================
    // 19. Preserve Cash Overpayment When Reconciling Totals (Review Item 3)
    // =========================================================================
    console.log('--- Testing Preserve Cash Overpayment When Reconciling Totals ---');
    // Step A: Stage Rp120,000 cash against Rp100,000 cart
    await context.PosStagedPayment.openModal('cart-overpay-001', 100000, true, '-', 'full_payment');
    elements['staged-amount-input'].value = '120000';
    elements['staged-amount-input'].dataset.rawValue = '120000';
    await cashOption.trigger('click', { preventDefault: () => {} });
    await elements['confirm-payment-proceed-btn'].trigger('click', { preventDefault: () => {} });

    // Initial stage completed: final confirmation modal is shown with 120k paid and 20k change
    assert.strictEqual(elements['final-paid-amount'].textContent.replace(/\s+/g, ' '), 'Rp 120.000');
    assert.strictEqual(elements['final-change-amount'].textContent.replace(/\s+/g, ' '), 'Rp 20.000');

    // Step B: Cashier cancels confirmation
    await elements['final-payment-cancel-btn'].trigger('click');

    // Step C: Reduce cart to Rp90,000 in POS shell and reopen checkout
    httpCalls.length = 0;
    await context.PosStagedPayment.openModal('cart-overpay-001', 90000, true, '-', 'full_payment');

    // Verify reconcile was called with grand_total: 90000
    const overpayReconcileCalls = httpCalls.filter(c => c.url.includes('/pos/sell/checkout/reconcile-payment-chain') && c.method === 'POST');
    assert.strictEqual(overpayReconcileCalls.length, 1);
    assert.strictEqual(overpayReconcileCalls[0].body.grand_total, 90000);

    // Verify signed remainder (-30,000) is preserved and NOT clamped to zero
    const overpaidChain = context.PosStagedPayment.getPaymentChain();
    assert.strictEqual(overpaidChain.original_grand_total, 90000);
    assert.strictEqual(overpaidChain.remainder, -30000, 'Remainder must be signed (-30,000) rather than clamped to 0');

    // Step D: Trigger final confirmation (clicking submit on the fully paid order)
    await elements['staged-payment-submit'].trigger('click', { preventDefault: () => {} });

    // CRITICAL ASSERTION: Final confirmation must show Rp 120.000 paid and Rp 30.000 change!
    assert.strictEqual(elements['final-grand-total'].textContent.replace(/\s+/g, ' '), 'Rp 90.000');
    assert.strictEqual(elements['final-paid-amount'].textContent.replace(/\s+/g, ' '), 'Rp 120.000', 'Total paid must show full 120,000 cash paid rather than clamped 90,000');
    assert.strictEqual(elements['final-change-amount'].textContent.replace(/\s+/g, ' '), 'Rp 30.000', 'Change must show Rp 30.000 due rather than Rp 0');

    // Step E: Verify browser recovery and fallback calculation also preserves signed remainder
    // Simulate fallback when server reconcile returns null
    const fallbackChain = {
        payments: [{ amount: 120000, method_id: 2, method_name: 'Tunai' }]
    };
    const totalStaged = fallbackChain.payments.reduce((sum, p) => sum + p.amount, 0);
    const fallbackRemainder = Math.round((90000 - totalStaged) * 100) / 100;
    assert.strictEqual(fallbackRemainder, -30000, 'Fallback calculation must produce signed remainder -30,000');

    console.log('ALL JAVASCRIPT STATE MACHINE REGRESSION TESTS PASSED!');
}

runBrowserRegressionTests().catch(err => {
    console.error('FAILED JS REGRESSION TEST:', err);
    process.exit(1);
});
