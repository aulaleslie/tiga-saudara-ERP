import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const publicAssetPath = path.resolve(__dirname, '../../public/js/session-expiry.js');
const source = fs.readFileSync(publicAssetPath, 'utf8');

const browserGlobal = {};
browserGlobal.window = browserGlobal;
browserGlobal.self = browserGlobal;
browserGlobal.globalThis = browserGlobal;

const context = vm.createContext(browserGlobal);
const script = new vm.Script(source, { filename: 'session-expiry.js' });
script.runInContext(context);

const SessionExpiry = browserGlobal.window.SessionExpiry;

const tests = [];
function test(name, fn) {
    tests.push({ name, fn });
}

let passedCount = 0;
let failedCount = 0;

test('parseIncidentPayload extracts the incident contract fields', () => {
    const result = SessionExpiry.parseIncidentPayload(JSON.stringify({
        incident_id: 'ERP-ABC123',
        message: 'Sesi berakhir',
        transaction_sensitive: true,
    }));

    assert.equal(result.incidentId, 'ERP-ABC123');
    assert.equal(result.message, 'Sesi berakhir');
    assert.equal(result.transactionSensitive, true);
});

test('parseIncidentPayload returns null for malformed or missing payloads', () => {
    assert.equal(SessionExpiry.parseIncidentPayload(null), null);
    assert.equal(SessionExpiry.parseIncidentPayload('not json'), null);
    assert.equal(SessionExpiry.parseIncidentPayload(JSON.stringify({ foo: 'bar' })), null);
});

test('isTransactionSensitiveRoute matches known transaction route families', () => {
    assert.equal(SessionExpiry.isTransactionSensitiveRoute('purchase.create'), true);
    assert.equal(SessionExpiry.isTransactionSensitiveRoute('pos.checkout'), true);
    assert.equal(SessionExpiry.isTransactionSensitiveRoute('dashboard'), false);
    assert.equal(SessionExpiry.isTransactionSensitiveRoute(null), false);
});

test('buildModalConfig includes the incident id and transaction warning when sensitive', () => {
    const doc = {
        querySelector: () => ({ content: 'purchase.create' }),
    };

    const config = SessionExpiry.buildModalConfig({
        incidentId: 'ERP-XYZ999',
        message: null,
        transactionSensitive: true,
    }, doc);

    assert.match(config.html, /ERP-XYZ999/);
    assert.match(config.html, /transaksi sebelumnya sudah tersimpan/);
});

test('buildModalConfig omits transaction warning on non-sensitive routes', () => {
    const doc = {
        querySelector: () => ({ content: 'dashboard' }),
    };

    const config = SessionExpiry.buildModalConfig({
        incidentId: 'ERP-XYZ999',
        message: null,
        transactionSensitive: false,
    }, doc);

    assert.doesNotMatch(config.html, /transaksi sebelumnya sudah tersimpan/);
});

/**
 * Fake Livewire matching the real 3.0.5 "request" hook contract: hook()
 * calls the registered callback synchronously with a context object exposing
 * a fail() *registration* function (not a returned {error(){}} object).
 * Registering via context.fail(cb) must be done synchronously, and calling
 * cb's preventDefault() is what actually skips Livewire's own
 * handlePageExpiry()/showFailureModal() for that failed request.
 */
function createFakeLivewire() {
    let failCallback = null;
    return {
        hook(name, handler) {
            assert.equal(name, 'request');
            handler({
                fail: (cb) => { failCallback = cb; },
            });
        },
        triggerFailure(failure) {
            failCallback(failure);
        },
    };
}

test('registerLivewireHook only intercepts status 419 and prevents the default dialog', () => {
    const livewire = createFakeLivewire();

    const fakeWindow = { location: { reload: () => {} } };
    const fakeDoc = { querySelector: () => null };

    let fireCalledWith = null;
    const fakeSwal = {
        fire: (config) => {
            fireCalledWith = config;
            return Promise.resolve({ isConfirmed: false, isDenied: false });
        },
    };

    SessionExpiry.registerLivewireHook(livewire, fakeDoc, fakeWindow, fakeSwal);

    let preventDefaultCalled = false;
    livewire.triggerFailure({
        status: 500,
        content: JSON.stringify({ incident_id: 'ERP-1' }),
        preventDefault: () => { preventDefaultCalled = true; },
    });
    assert.equal(preventDefaultCalled, false, 'non-419 statuses must not be intercepted');
    assert.equal(fireCalledWith, null);

    livewire.triggerFailure({
        status: 419,
        content: JSON.stringify({ incident_id: 'ERP-2', transaction_sensitive: false }),
        preventDefault: () => { preventDefaultCalled = true; },
    });
    assert.equal(preventDefaultCalled, true, '419 must prevent the native Livewire dialog');
    assert.ok(fireCalledWith, 'the application modal must be shown for 419');
    assert.match(fireCalledWith.html, /ERP-2/);
});

test('registerLivewireHook reloads only after explicit confirmation, never automatically', async () => {
    const livewire = createFakeLivewire();

    let reloaded = false;
    const fakeWindow = { location: { reload: () => { reloaded = true; } }, navigator: {} };
    const fakeDoc = { querySelector: () => null };

    const fakeSwal = {
        fire: () => Promise.resolve({ isConfirmed: true, isDenied: false }),
    };

    SessionExpiry.registerLivewireHook(livewire, fakeDoc, fakeWindow, fakeSwal);

    livewire.triggerFailure({
        status: 419,
        content: JSON.stringify({ incident_id: 'ERP-3' }),
        preventDefault: () => {},
    });

    assert.equal(reloaded, false, 'reload must not happen synchronously / before confirmation');
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(reloaded, true);
});

test('init registers the Livewire hook synchronously against the already-available Livewire global, not via a second livewire:init listener', () => {
    let hookRegistered = false;
    const fakeWindow = {
        Livewire: {
            hook: (name, handler) => {
                hookRegistered = true;
                handler({ fail: () => {} });
            },
        },
        Swal: { fire: () => Promise.resolve({}) },
    };
    const fakeDoc = { querySelector: () => null };

    SessionExpiry.init(fakeDoc, fakeWindow);

    assert.equal(hookRegistered, true, 'init() must register the hook immediately, since livewire:init has already fired by the time this runs');
});

test('showFallback presents the incident id when SweetAlert is unavailable', () => {
    let alertedWith = null;
    const fakeWindow = { alert: (msg) => { alertedWith = msg; } };

    SessionExpiry.showFallback({ incidentId: 'ERP-FALLBACK' }, fakeWindow);

    assert.match(alertedWith, /ERP-FALLBACK/);
});

for (const { name, fn } of tests) {
    try {
        const result = fn();
        if (result && typeof result.then === 'function') {
            await result;
        }
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
