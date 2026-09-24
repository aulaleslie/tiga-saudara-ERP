// Adapter-level tests for the Stock Transfer v3 Livewire transport, driven by
// a fake that reproduces the Livewire v3.0.5 request lifecycle and hook
// ordering (vendor/livewire/livewire/dist/livewire.esm.js,
// sendRequestToServer / Commit.toRequestPayload).
// Run: node --test tests/js/transfer-v3-livewire-transport.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = (file) => readFileSync(new URL('../../resources/js/transfer/' + file, import.meta.url), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));

/**
 * Fake Livewire 3.0.5. Each component.call() becomes one request:
 *  commit hooks -> request hook -> fetch -> (HTTP error: commit fail
 *  callbacks, THEN request fail callbacks with preventDefault, then modal
 *  unless prevented / 419 page expiry) | (network error: fetch rejects, no
 *  hooks, queue stays blocked forever) | (ok: call resolves).
 */
function fakeLivewire(host) {
    const hooks = { commit: [], request: [] };
    const events = [];
    let sendingRequest = false;
    const afterSend = [];

    const livewire = {
        hook(name, callback) {
            hooks[name].push(callback);
        },
    };

    function component(id) {
        return {
            id,
            call(method, ...params) {
                return new Promise((resolve) => {
                    // Browsers only log Livewire's uncaught fetch rejection;
                    // swallow it the same way (sendingRequest stays true).
                    const send = () => { sendRequest(id, method, params, resolve).catch(() => {}); };
                    if (sendingRequest) {
                        afterSend.push(send);
                    } else {
                        send();
                    }
                });
            },
        };
    }

    async function sendRequest(id, method, params, resolveCall) {
        sendingRequest = true;

        const commitFail = [];
        hooks.commit.forEach((cb) => cb({
            component: { id },
            commit: {},
            succeed: () => {},
            respond: () => {},
            fail: (fn) => commitFail.push(fn),
        }));

        const body = JSON.stringify({
            _token: 't',
            components: [{ snapshot: JSON.stringify({ memo: { id } }), updates: {}, calls: [{ method, params }] }],
        });
        const requestFail = [];
        hooks.request.forEach((cb) => cb({
            url: '/livewire/update',
            options: {},
            payload: body,
            respond: () => {},
            succeed: () => {},
            fail: (fn) => requestFail.push(fn),
        }));

        // Network error: throws out of the queue; sendingRequest stays true.
        const response = await host.fetch('/livewire/update', { method: 'POST', body, headers: { 'Content-type': 'application/json', 'X-Livewire': '' } });

        if (!response.ok) {
            let prevented = false;
            commitFail.forEach((fn) => fn()); // handleFailure() first
            requestFail.forEach((fn) => fn({ status: response.status, content: '', preventDefault: () => { prevented = true; } }));
            if (!prevented) {
                events.push(response.status === 419 ? 'page-expired' : 'error-modal');
            }
            // the call promise never settles on failure in 3.0.5
        } else {
            resolveCall(response.returns);
        }

        sendingRequest = false;
        while (afterSend.length) {
            afterSend.shift()();
        }
    }

    return { livewire, component, events, isWedged: () => sendingRequest };
}

/** Programmable fetch: each request takes the next scripted outcome. */
function scriptedHost() {
    const outcomes = [];
    const requests = [];
    const host = {
        fetch(url, init) {
            requests.push(JSON.parse(init.body).components[0]);
            const next = outcomes.shift() || { ok: true };
            if (next.network) {
                return Promise.reject(new TypeError('Failed to fetch'));
            }
            return Promise.resolve({ ok: next.ok !== false, status: next.status || 200, returns: next.returns });
        },
    };
    return { host, outcomes, requests };
}

function setup() {
    const context = { globalThis: {}, setTimeout, clearTimeout, Set, Promise, JSON, Array, Object, Error };
    context.globalThis = context;
    vm.runInNewContext(read('v3-scan-coordinator.js'), context);
    vm.runInNewContext(read('v3-livewire-transport.js'), context);

    const { host, outcomes, requests } = scriptedHost();
    const lw = fakeLivewire(host);
    const ours = lw.component('comp-ours');
    const other = lw.component('comp-other');
    const renders = [];
    let coordinator;
    let counter = 0;

    const transport = context.V3TransferLivewireTransport.create({
        livewire: lw.livewire,
        host,
        getComponent: () => ours,
        getComponentId: () => 'comp-ours',
        onFatal: () => coordinator.markFatal(),
    });
    coordinator = context.V3TransferScanCoordinator.create({
        call: transport.call,
        render: (state) => renders.push(state),
        generateToken: () => 'tok-' + (++counter),
        slowAfterMs: 5,
    });

    return { lw, ours, other, transport, coordinator, outcomes, requests, last: () => renders[renders.length - 1] };
}

test('HTTP 500 for our scan: retry panel shown and Livewire error modal suppressed despite commit-fail running first', async () => {
    const t = setup();
    t.outcomes.push({ ok: false, status: 500 });
    t.coordinator.enqueue('A');
    await flush();
    await flush();

    assert.deepEqual([...t.lw.events], [], 'no Livewire error modal');
    assert.equal(t.last().phase, 'failed');
    assert.equal(t.last().canRetry, true);
    assert.equal(t.last().fatal, false);

    // Livewire recovered from the HTTP error: retry goes through, same token.
    t.outcomes.push({ ok: true, returns: { status: 'applied' } });
    await t.coordinator.retry();
    await flush();
    assert.equal(t.requests.length, 2);
    assert.deepEqual([...t.requests[1].calls[0].params], ['A', 'tok-1']);
    assert.equal(t.coordinator.isIdle(), true);
});

test('HTTP 503 during save: modal suppressed, intake unfrozen for retry', async () => {
    const t = setup();
    t.outcomes.push({ ok: false, status: 503 });
    const outcome = await t.coordinator.requestAction(() => t.transport.call('saveDraft'));
    assert.ok(outcome.error);
    assert.deepEqual([...t.lw.events], []);
    assert.equal(t.last().intakeFrozen, false);
});

test('HTTP 419 keeps Livewire default page-expiry handling', async () => {
    const t = setup();
    t.outcomes.push({ ok: false, status: 419 });
    t.coordinator.enqueue('A');
    await flush();
    await flush();
    assert.deepEqual([...t.lw.events], ['page-expired']);
    assert.equal(t.last().phase, 'failed');
});

test('HTTP error from a request we do not own (quantity edit/search, no scan in flight) keeps the default modal', async () => {
    const t = setup();
    t.outcomes.push({ ok: false, status: 500 });
    t.ours.call('$set', 'rows.0.quantity', 3); // not via the coordinator
    await flush();
    await flush();
    assert.deepEqual([...t.lw.events], ['error-modal']);
    assert.equal(t.coordinator.snapshot().phase, 'idle');
});

test('HTTP error for another component does not fail our scan', async () => {
    const t = setup();
    t.outcomes.push({ ok: false, status: 500 });
    t.other.call('refresh');
    await flush();
    await flush();
    t.outcomes.push({ ok: true, returns: { status: 'applied' } });
    t.coordinator.enqueue('A');
    await flush();
    await flush();
    assert.equal(t.coordinator.isIdle(), true);
    assert.equal(t.coordinator.snapshot().fatal, false);
});

test('network failure during a modal search, then scanning: reload warning, not an endless "Koneksi lambat"', async () => {
    const t = setup();
    t.outcomes.push({ network: true });
    t.ours.call('searchProducts'); // modal search, no coordinator request in flight
    await flush();
    await flush();

    assert.equal(t.lw.isWedged(), true, 'Livewire 3.0.5 queue is stuck');
    assert.equal(t.transport.isBroken(), true);
    assert.equal(t.last().fatal, true, 'coordinator notified without any scan in flight');
    assert.match(t.last().message, /Muat ulang halaman/);

    // The next scan is refused immediately with the reload message.
    assert.equal(t.coordinator.enqueue('A'), null);
    await flush();
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.doesNotMatch(t.last().message, /Koneksi lambat/);
    assert.equal(t.requests.length, 1, 'no further request is attempted');
});

test('network failure during a quantity edit freezes a deferred save', async () => {
    const t = setup();
    t.outcomes.push({ network: true });
    t.ours.call('$set', 'rows.0.quantity', 3);
    await flush();
    await flush();
    const outcome = await t.coordinator.requestAction(() => t.transport.call('saveDraft'));
    assert.equal(outcome.ran, false);
    assert.equal(outcome.reason, 'fatal');
});

test('a scan queued behind a request that then fails at the network level is reported fatal, not left waiting', async () => {
    const t = setup();
    t.outcomes.push({ network: true });
    t.ours.call('searchProducts'); // in flight...
    t.coordinator.enqueue('A');    // ...scan waits behind it in Livewire's queue
    await flush();
    await flush();

    const state = t.coordinator.snapshot();
    assert.equal(state.fatal, true);
    assert.equal(state.canRetry, false);
    assert.match(state.message, /belum tercatat.*A/);
});

test('network failure of our own scan request is fatal with the scan listed', async () => {
    const t = setup();
    t.outcomes.push({ network: true });
    t.coordinator.enqueue('A');
    t.coordinator.enqueue('B');
    await flush();
    await flush();
    assert.equal(t.last().fatal, true);
    assert.match(t.last().message, /A, B/);
    assert.equal(await t.coordinator.retry(), false);
});

test('successful requests resolve with the server return value', async () => {
    const t = setup();
    t.outcomes.push({ ok: true, returns: { status: 'ambiguous', token: 'tok-1' } });
    t.coordinator.enqueue('A');
    await flush();
    await flush();
    assert.equal(t.coordinator.snapshot().phase, 'awaiting_choice');
});
