import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const publicAssetPath = path.resolve(__dirname, '../../public/js/bundle-lifecycle-warning.js');
const source = fs.readFileSync(publicAssetPath, 'utf8');

// Assert classic script parsing and execution in a browser-like VM context
const browserGlobal = {};
browserGlobal.window = browserGlobal;
browserGlobal.self = browserGlobal;
browserGlobal.globalThis = browserGlobal;

const context = vm.createContext(browserGlobal);
const script = new vm.Script(source, { filename: 'bundle-lifecycle-warning.js' });
script.runInContext(context);

const BundleLifecycleWarning = browserGlobal.window.BundleLifecycleWarning;

const {
    resolveLifecycleWarning,
    escapeHtml,
    buildLifecycleWarningItemsHtml,
    buildLifecycleWarningModalHtml,
    findTargetForm,
    applyAcknowledgementAndSubmit,
    handleSalesLifecycleWarning
} = BundleLifecycleWarning || {};

const tests = [];
let passedCount = 0;
let failedCount = 0;

function test(name, fn) {
    tests.push({ name, fn });
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message || 'Assertion failed');
    }
}

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message || 'Assertion failed'}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

// ============ Classic Script & Global Exposure Assertions ============

test('public asset parses without ESM export syntax errors in classic VM context', () => {
    assert(typeof source === 'string' && source.length > 0, 'Source file must be non-empty');
    assert(!source.includes('export const'), 'Source must not contain ESM named exports');
    assert(!source.includes('export default'), 'Source must not contain ESM default export');
});

test('window.BundleLifecycleWarning exists and exposes all seven required functions', () => {
    assert(BundleLifecycleWarning !== null && typeof BundleLifecycleWarning === 'object', 'window.BundleLifecycleWarning must exist');
    assertEqual(typeof resolveLifecycleWarning, 'function', 'resolveLifecycleWarning must be a function');
    assertEqual(typeof escapeHtml, 'function', 'escapeHtml must be a function');
    assertEqual(typeof buildLifecycleWarningItemsHtml, 'function', 'buildLifecycleWarningItemsHtml must be a function');
    assertEqual(typeof buildLifecycleWarningModalHtml, 'function', 'buildLifecycleWarningModalHtml must be a function');
    assertEqual(typeof findTargetForm, 'function', 'findTargetForm must be a function');
    assertEqual(typeof applyAcknowledgementAndSubmit, 'function', 'applyAcknowledgementAndSubmit must be a function');
    assertEqual(typeof handleSalesLifecycleWarning, 'function', 'handleSalesLifecycleWarning must be a function');
});

// ============ Behavioral Tests ============

test('extracts warning from details.warning with highest priority', () => {
    const error = {
        message: 'Root generic message',
        code: 'BUNDLE_LIFECYCLE_WARNING',
        warning: {
            code: 'SECONDARY_WARNING',
            message: 'Secondary message',
            items: [{ product_name: 'Secondary Item', reason: 'Secondary reason' }]
        },
        details: {
            warning: {
                code: 'PRIMARY_WARNING',
                message: 'Primary message from details',
                items: [{ product_name: 'Primary Item', reason: 'Primary reason' }]
            }
        }
    };

    const result = resolveLifecycleWarning(error);
    assert(result !== null, 'Warning should be resolved');
    assertEqual(result.code, 'PRIMARY_WARNING', 'Should pick details.warning');
    assertEqual(result.items[0].product_name, 'Primary Item', 'Should contain details items');
});

test('falls back to error.warning when details.warning is absent', () => {
    const error = {
        message: 'Root generic message',
        code: 'BUNDLE_LIFECYCLE_WARNING',
        warning: {
            code: 'WARNING_OBJECT',
            message: 'Warning object message',
            items: [{ product_name: 'Bundle A', reason: 'Expired' }]
        },
        details: {}
    };

    const result = resolveLifecycleWarning(error);
    assert(result !== null, 'Warning should be resolved');
    assertEqual(result.code, 'WARNING_OBJECT', 'Should pick error.warning');
    assertEqual(result.items[0].product_name, 'Bundle A', 'Should contain warning items');
});

test('falls back to root error only when items are present directly', () => {
    const errorWithItems = {
        message: 'Direct root error message',
        code: 'BUNDLE_LIFECYCLE_WARNING',
        items: [{ product_name: 'Direct Item', reason: 'Direct reason' }]
    };

    const result = resolveLifecycleWarning(errorWithItems);
    assert(result !== null, 'Warning should be resolved');
    assertEqual(result.items[0].product_name, 'Direct Item', 'Should resolve root error with items');

    const errorWithoutItems = {
        message: 'No items error',
        code: 'BUNDLE_LIFECYCLE_WARNING'
    };
    const nullResult = resolveLifecycleWarning(errorWithoutItems);
    assertEqual(nullResult, null, 'Should return null when root error lacks items');
});

test('returns null for empty or non-lifecycle error objects', () => {
    assertEqual(resolveLifecycleWarning(null), null);
    assertEqual(resolveLifecycleWarning(undefined), null);
    assertEqual(resolveLifecycleWarning({ message: 'Random error', code: 'STOCK_UNAVAILABLE' }), null);
});

test('escapeHtml sanitizes all dangerous HTML characters', () => {
    const dangerous = '<script>alert("XSS & attack\'s")</script>';
    const escaped = escapeHtml(dangerous);
    assertEqual(escaped, '&lt;script&gt;alert(&quot;XSS &amp; attack&#039;s&quot;)&lt;/script&gt;');
    assertEqual(escapeHtml(null), '');
    assertEqual(escapeHtml(undefined), '');
});

test('buildLifecycleWarningItemsHtml escapes all dynamic item labels and messages', () => {
    const items = [
        {
            line_label: '<img src=x onerror=alert(1)>Special & Bundle',
            message: '<b>Component deactivated</b>'
        },
        {
            product_name: 'Bundle "Pro"',
            reasons: ['Reason <1>', 'Reason "2"']
        }
    ];

    const html = buildLifecycleWarningItemsHtml(items);
    assert(!html.includes('<img'), 'Must not contain raw img tag');
    assert(!html.includes('<b>'), 'Must not contain raw b tag');
    assert(html.includes('&lt;img src=x onerror=alert(1)&gt;Special &amp; Bundle'), 'Must escape label');
    assert(html.includes('&lt;b&gt;Component deactivated&lt;/b&gt;'), 'Must escape item message');
    assert(html.includes('Reason &lt;1&gt;, Reason &quot;2&quot;'), 'Must escape item reasons');
    assert(html.startsWith('<li><strong>'), 'Must preserve structural HTML tags');
});

test('buildLifecycleWarningModalHtml escapes dynamic top-level message and preserves structural wrapper', () => {
    const warningData = {
        message: 'Custom <script>danger</script> message & alert',
        items: [
            { bundle_name: '<Payload>', reason: '<detail>' }
        ]
    };

    const modalHtml = buildLifecycleWarningModalHtml(warningData);
    assert(!modalHtml.includes('<script>'), 'Must not contain raw script tag');
    assert(modalHtml.includes('Custom &lt;script&gt;danger&lt;/script&gt; message &amp; alert'), 'Must escape top-level message');
    assert(modalHtml.includes('&lt;Payload&gt;'), 'Must escape bundle name');
    assert(modalHtml.includes('&lt;detail&gt;'), 'Must escape reason');
    assert(modalHtml.startsWith('<div class="text-left">'), 'Must contain structural div');
});

// ============ DOM & Targeting Mechanics Tests ============

function createMockElement(tagName, attrs = {}) {
    const element = {
        tagName: tagName.toUpperCase(),
        attributes: { ...attrs },
        children: [],
        ownerDocument: null,
        getAttribute(key) {
            return this.attributes[key] || null;
        },
        setAttribute(key, val) {
            this.attributes[key] = String(val);
        },
        querySelector(selector) {
            for (const child of this.children) {
                if (matchesSelector(child, selector)) {
                    return child;
                }
                const found = child.querySelector ? child.querySelector(selector) : null;
                if (found) return found;
            }
            return null;
        },
        querySelectorAll(selector) {
            const results = [];
            for (const child of this.children) {
                if (matchesSelector(child, selector)) {
                    results.push(child);
                }
                if (child.querySelectorAll) {
                    results.push(...child.querySelectorAll(selector));
                }
            }
            return results;
        },
        appendChild(child) {
            child.parentElement = this;
            this.children.push(child);
            return child;
        },
        submitCalled: false,
        submit() {
            this.submitCalled = true;
        }
    };
    return element;
}

function matchesSelector(el, selector) {
    if (!el) return false;
    const attrs = el.attributes || {};
    // form[data-sale-approval-id="123"][data-status="APPROVED"]
    const saleMatch = selector.match(/form\[data-sale-approval-id="([^"]+)"\](?:\[data-status="([^"]+)"\])?/);
    if (saleMatch && el.tagName === 'FORM') {
        const idMatch = attrs['data-sale-approval-id'] === saleMatch[1];
        const statusMatch = !saleMatch[2] || attrs['data-status'] === saleMatch[2];
        return idMatch && statusMatch;
    }

    // form[data-dispatch-approval-id="456"]
    const dispatchMatch = selector.match(/form\[data-dispatch-approval-id="([^"]+)"\]/);
    if (dispatchMatch && el.tagName === 'FORM') {
        return attrs['data-dispatch-approval-id'] === dispatchMatch[1];
    }

    // input[name="acknowledge_lifecycle_warning"]
    const inputMatch = selector.match(/input\[name="([^"]+)"\]/);
    if (inputMatch && el.tagName === 'INPUT') {
        return (attrs.name || el.name) === inputMatch[1];
    }

    // form[data-store-dispatch-id="789"]
    const storeDispatchMatch = selector.match(/form\[data-store-dispatch-id="([^"]+)"\]/);
    if (storeDispatchMatch && el.tagName === 'FORM') {
        return attrs['data-store-dispatch-id'] === storeDispatchMatch[1];
    }

    return false;
}

function createMockDocument() {
    const doc = createMockElement('document');
    doc.body = createMockElement('body');
    doc.appendChild(doc.body);
    doc.createElement = function (tagName) {
        const el = createMockElement(tagName);
        el.ownerDocument = doc;
        return el;
    };
    return doc;
}

test('findTargetForm accurately selects Sale approval form by sale_id and status', () => {
    const doc = createMockDocument();
    const wrongSaleForm = doc.createElement('form');
    wrongSaleForm.setAttribute('data-sale-approval-id', '99');
    wrongSaleForm.setAttribute('data-status', 'APPROVED');
    doc.body.appendChild(wrongSaleForm);

    const targetSaleForm = doc.createElement('form');
    targetSaleForm.setAttribute('data-sale-approval-id', '101');
    targetSaleForm.setAttribute('data-status', 'APPROVED');
    doc.body.appendChild(targetSaleForm);

    const targetRejectForm = doc.createElement('form');
    targetRejectForm.setAttribute('data-sale-approval-id', '101');
    targetRejectForm.setAttribute('data-status', 'REJECTED');
    doc.body.appendChild(targetRejectForm);

    const found = findTargetForm(doc, {
        target_type: 'sale_approval',
        sale_id: 101,
        status: 'APPROVED'
    });

    assertEqual(found, targetSaleForm, 'Must locate exact matching Sale approval form');
});

test('findTargetForm accurately selects Dispatch approval form by dispatch_id', () => {
    const doc = createMockDocument();
    const otherDispatch = doc.createElement('form');
    otherDispatch.setAttribute('data-dispatch-approval-id', '201');
    doc.body.appendChild(otherDispatch);

    const targetDispatch = doc.createElement('form');
    targetDispatch.setAttribute('data-dispatch-approval-id', '202');
    doc.body.appendChild(targetDispatch);

    const found = findTargetForm(doc, {
        target_type: 'dispatch_approval',
        dispatch_id: 202
    });

    assertEqual(found, targetDispatch, 'Must locate exact matching Dispatch approval form');
});

test('applyAcknowledgementAndSubmit sets acknowledge_lifecycle_warning=1 and submits form', () => {
    const doc = createMockDocument();
    const form = doc.createElement('form');
    doc.body.appendChild(form);

    applyAcknowledgementAndSubmit(form);

    const ackInput = form.querySelector('input[name="acknowledge_lifecycle_warning"]');
    assert(ackInput !== null, 'Hidden input acknowledge_lifecycle_warning must be appended');
    assertEqual(ackInput.value, '1', 'Hidden input value must be 1');
    assert(form.submitCalled, 'Form submit must be executed');
});

test('handleSalesLifecycleWarning submits exact form when user confirms modal', async () => {
    const doc = createMockDocument();
    const targetForm = doc.createElement('form');
    targetForm.setAttribute('data-sale-approval-id', '303');
    targetForm.setAttribute('data-status', 'APPROVED');
    doc.body.appendChild(targetForm);

    let swalCalledWith = null;
    const mockSwal = {
        fire: async function (config) {
            swalCalledWith = config;
            return { isConfirmed: true };
        }
    };

    const warningData = {
        target_type: 'sale_approval',
        sale_id: 303,
        status: 'APPROVED',
        message: 'Bundle <b>X</b> changed',
        items: [{ product_name: 'Item <1>', reason: 'Deactivated' }]
    };

    const handled = await handleSalesLifecycleWarning(warningData, {
        document: doc,
        swal: mockSwal
    });

    assert(handled === true, 'Handler should return true on confirmed submission');
    assert(targetForm.submitCalled, 'Target form submit should be called');
    assertEqual(targetForm.querySelector('input[name="acknowledge_lifecycle_warning"]').value, '1');
    assert(!swalCalledWith.html.includes('<b>X</b>'), 'Modal HTML must escape tags in message');
});

test('handleSalesLifecycleWarning does not submit when user cancels modal', async () => {
    const doc = createMockDocument();
    const targetForm = doc.createElement('form');
    targetForm.setAttribute('data-sale-approval-id', '404');
    targetForm.setAttribute('data-status', 'APPROVED');
    doc.body.appendChild(targetForm);

    const mockSwal = {
        fire: async function () {
            return { isConfirmed: false };
        }
    };

    const warningData = {
        target_type: 'sale_approval',
        sale_id: 404,
        status: 'APPROVED',
        items: [{ product_name: 'Item', reason: 'Deactivated' }]
    };

    const handled = await handleSalesLifecycleWarning(warningData, {
        document: doc,
        swal: mockSwal
    });

    assert(handled === false, 'Handler should return false on cancel');
    assert(!targetForm.submitCalled, 'Target form submit must NOT be called on cancel');
    assert(targetForm.querySelector('input[name="acknowledge_lifecycle_warning"]') === null, 'No input appended on cancel');
});

test('handleSalesLifecycleWarning does not submit when SweetAlert is unavailable', async () => {
    const doc = createMockDocument();
    const targetForm = doc.createElement('form');
    targetForm.setAttribute('data-dispatch-approval-id', '505');
    doc.body.appendChild(targetForm);

    const warningData = {
        target_type: 'dispatch_approval',
        dispatch_id: 505,
        items: [{ product_name: 'Item', reason: 'Deactivated' }]
    };

    const handled = await handleSalesLifecycleWarning(warningData, {
        document: doc,
        swal: null
    });

    assert(handled === false, 'Handler should return false when swal is missing');
    assert(!targetForm.submitCalled, 'Form submit must NOT be called when swal is missing');
});

// ============ Runner ============

for (const { name, fn } of tests) {
    try {
        fn();
        passedCount++;
        console.log(`✓ ${name}`);
    } catch (err) {
        failedCount++;
        console.error(`✗ ${name}`);
        console.error(err);
    }
}

console.log(`\nTests: ${passedCount} passed, ${failedCount} failed`);

if (failedCount > 0) {
    process.exit(1);
}
