// Unit tests for the Stock Transfer v3 scan coordinator core.
// Run: node --test tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/transfer/v3-scan-coordinator.js', import.meta.url), 'utf8');

function loadCore() {
    const context = { globalThis: {}, setTimeout, clearTimeout };
    context.globalThis = context;
    vm.runInNewContext(source, context);
    return context.V3TransferScanCoordinator;
}

const Core = loadCore();
const flush = () => new Promise((resolve) => setImmediate(resolve));

/**
 * Fake Livewire component: records calls and lets each test settle them.
 */
function harness(options = {}) {
    const calls = [];
    const renders = [];
    let counter = 0;
    const call = (method, ...args) => new Promise((resolve, reject) => {
        calls.push({ method, args, resolve, reject });
    });
    const coordinator = Core.create({
        call,
        render: (state) => renders.push(state),
        generateToken: () => 'tok-' + (++counter),
        ...options,
    });
    return { coordinator, calls, renders, last: () => renders[renders.length - 1] };
}

test('terminator detection covers Enter, CR and LF key codes', () => {
    assert.equal(Core.isScanTerminator({ key: 'Enter' }), true);
    assert.equal(Core.isScanTerminator({ code: 'NumpadEnter' }), true);
    assert.equal(Core.isScanTerminator({ keyCode: 13 }), true);
    assert.equal(Core.isScanTerminator({ keyCode: 10 }), true);
    assert.equal(Core.isScanTerminator({ key: 'a', keyCode: 65 }), false);
});

test('CR/LF buffer splitting keeps order and the incomplete remainder', () => {
    const split = Core.splitScanBuffer('A1\r\nB2\nC3\rPART');
    assert.deepEqual([...split.codes], ['A1', 'B2', 'C3']);
    assert.equal(split.rest, 'PART');
    assert.deepEqual([...Core.splitScanBuffer('\r\n\r\n').codes], []);
});

test('rapid scans are sent one at a time in FIFO order, each exactly once', async () => {
    const { coordinator, calls } = harness();
    ['A', 'B\r\n', '  ', 'C'].forEach((value) => coordinator.enqueue(value));

    await flush();
    assert.equal(calls.length, 1, 'only one request in flight');
    assert.deepEqual(calls[0].args, ['A', 'tok-1']);
    assert.equal(coordinator.snapshot().queueLength, 3);

    calls[0].resolve({ status: 'applied' });
    await flush();
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[1].args, ['B', 'tok-2']);

    calls[1].resolve({ status: 'not_found' });
    await flush();
    assert.deepEqual(calls[2].args, ['C', 'tok-3']);
    calls[2].resolve({ status: 'duplicate' });
    await flush();

    assert.equal(calls.length, 3);
    assert.equal(coordinator.isIdle(), true);
});

test('a scan stays queued until its response is acknowledged', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('A');
    await flush();
    assert.equal(coordinator.snapshot().queue[0].value, 'A');
    calls[0].resolve({ status: 'applied' });
    await flush();
    assert.equal(coordinator.snapshot().queueLength, 0);
});

test('ambiguity pauses the queue, keeps later scans and resumes after a token-bound choice', async () => {
    const { coordinator, calls, last } = harness();
    coordinator.enqueue('SHARED');
    await flush();
    calls[0].resolve({ status: 'ambiguous' });
    await flush();

    coordinator.enqueue('NEXT-1');
    coordinator.enqueue('NEXT-2');
    await flush();
    assert.equal(calls.length, 1, 'later scans are not sent while awaiting a choice');
    assert.equal(last().phase, 'awaiting_choice');
    assert.deepEqual([...coordinator.snapshot().queue.map((item) => item.value)], ['SHARED', 'NEXT-1', 'NEXT-2']);

    const chosen = coordinator.choose(1);
    await flush();
    assert.equal(calls[1].method, 'chooseCandidate');
    assert.deepEqual(calls[1].args, [1, 'tok-1'], 'choice carries the ambiguous scan token');
    calls[1].resolve({ status: 'applied' });
    await flush();

    assert.deepEqual(calls[2].args, ['NEXT-1', 'tok-2']);
    calls[2].resolve({ status: 'applied' });
    await flush();
    assert.deepEqual(calls[3].args, ['NEXT-2', 'tok-3']);
    calls[3].resolve({ status: 'applied' });
    await chosen;
    await flush();
    assert.equal(coordinator.isIdle(), true);
});

test('cancelling an ambiguous scan discards only that scan and resumes', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('SHARED');
    coordinator.enqueue('NEXT');
    await flush();
    calls[0].resolve({ status: 'ambiguous' });
    await flush();

    coordinator.cancelChoice();
    await flush();
    assert.equal(calls[1].method, 'cancelCandidates');
    assert.deepEqual(calls[1].args, ['tok-1']);
    calls[1].resolve({ status: 'cancelled' });
    await flush();
    assert.deepEqual(calls[2].args, ['NEXT', 'tok-2']);
});

test('a stale choice response keeps the scan awaiting a choice', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('SHARED');
    await flush();
    calls[0].resolve({ status: 'ambiguous' });
    await flush();
    coordinator.choose(0);
    await flush();
    calls[1].resolve({ status: 'stale' });
    await flush();
    assert.equal(coordinator.snapshot().phase, 'awaiting_choice');
    assert.equal(coordinator.snapshot().queue[0].value, 'SHARED');
});

test('choosing while not awaiting a choice does nothing', async () => {
    const { coordinator, calls } = harness();
    assert.equal(await coordinator.choose(0), false);
    assert.equal(calls.length, 0);
});

test('failed request pauses, keeps the failed and later scans, and retries with the same token', async () => {
    const { coordinator, calls, last } = harness();
    coordinator.enqueue('A');
    coordinator.enqueue('B');
    await flush();
    calls[0].reject(new Error('network'));
    await flush();

    assert.equal(last().phase, 'failed');
    assert.equal(last().canRetry, true);
    assert.match(last().message, /Gagal memproses pindaian "A"/);
    assert.deepEqual([...coordinator.snapshot().queue.map((item) => item.value)], ['A', 'B']);

    coordinator.enqueue('C');
    await flush();
    assert.equal(calls.length, 1, 'nothing is sent while failed');

    coordinator.retry();
    await flush();
    assert.deepEqual(calls[1].args, ['A', 'tok-1'], 'retry reuses the operation token');
    calls[1].resolve({ status: 'replayed' });
    await flush();
    assert.deepEqual(calls[2].args, ['B', 'tok-2']);
    calls[2].resolve({ status: 'applied' });
    await flush();
    assert.deepEqual(calls[3].args, ['C', 'tok-3']);
});

test('cancelling a failed scan drops only that scan and resumes FIFO', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('A');
    coordinator.enqueue('B');
    await flush();
    calls[0].reject(new Error('network'));
    await flush();

    coordinator.cancelFailed();
    await flush();
    assert.deepEqual(calls[1].args, ['B', 'tok-2']);
    assert.deepEqual([...coordinator.snapshot().queue.map((item) => item.value)], ['B']);
});

test('a failed choice request can be retried with the same index and token, or returns to the choice', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('SHARED');
    await flush();
    calls[0].resolve({ status: 'ambiguous' });
    await flush();

    coordinator.choose(1);
    await flush();
    calls[1].reject(new Error('network'));
    await flush();
    assert.equal(coordinator.snapshot().phase, 'failed');
    assert.equal(coordinator.snapshot().failure.kind, 'choose');

    coordinator.cancelFailed();
    await flush();
    assert.equal(coordinator.snapshot().phase, 'awaiting_choice');

    coordinator.choose(1);
    await flush();
    calls[2].reject(new Error('network'));
    await flush();
    coordinator.retry();
    await flush();
    assert.equal(calls[3].method, 'chooseCandidate');
    assert.deepEqual(calls[3].args, [1, 'tok-1']);
});

test('save runs immediately when idle', async () => {
    const { coordinator } = harness();
    let ran = 0;
    const outcome = await coordinator.requestAction(() => { ran += 1; });
    assert.equal(ran, 1);
    assert.equal(outcome.ran, true);
});

test('save waits for queued and in-flight scans, then runs once', async () => {
    const { coordinator, calls, last } = harness();
    coordinator.enqueue('A');
    coordinator.enqueue('B');
    await flush();

    let ran = 0;
    const pending = coordinator.requestAction(() => { ran += 1; });
    await flush();
    assert.equal(ran, 0);
    assert.equal(last().waitingAction, true);
    assert.match(last().notice, /Menunggu seluruh pindaian/);

    const second = await coordinator.requestAction(() => { ran += 10; });
    assert.equal(second.ran, false, 'a second save while one is pending is refused');

    calls[0].resolve({ status: 'applied' });
    await flush();
    assert.equal(ran, 0);
    calls[1].resolve({ status: 'applied' });
    const outcome = await pending;
    assert.equal(outcome.ran, true);
    assert.equal(ran, 1);
});

test('save is refused while a scan awaits a choice or a failure decision', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('SHARED');
    await flush();
    calls[0].resolve({ status: 'ambiguous' });
    await flush();

    let ran = 0;
    let outcome = await coordinator.requestAction(() => { ran += 1; });
    assert.equal(outcome.ran, false);
    assert.equal(outcome.reason, 'awaiting_choice');
    assert.match(outcome.message, /menunggu pilihan produk/);

    coordinator.cancelChoice();
    await flush();
    calls[1].resolve({ status: 'cancelled' });
    await flush();

    coordinator.enqueue('X');
    await flush();
    calls[2].reject(new Error('network'));
    await flush();
    outcome = await coordinator.requestAction(() => { ran += 1; });
    assert.equal(outcome.reason, 'failed');
    assert.match(outcome.message, /gagal diproses/);
    assert.equal(ran, 0);
});

test('a deferred save is abandoned, not run, when the queue stops on ambiguity or failure', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('SHARED');
    await flush();

    let ran = 0;
    const pending = coordinator.requestAction(() => { ran += 1; });
    calls[0].resolve({ status: 'ambiguous' });
    const outcome = await pending;
    assert.equal(outcome.ran, false);
    assert.equal(outcome.reason, 'abandoned');
    assert.equal(ran, 0);

    coordinator.cancelChoice();
    await flush();
    calls[1].resolve({ status: 'cancelled' });
    await flush();
    assert.equal(ran, 0, 'abandoned save does not run later on its own');
});

test('server-side blocked status keeps the scan at the head awaiting a choice', async () => {
    const { coordinator, calls } = harness();
    coordinator.enqueue('A');
    await flush();
    calls[0].resolve({ status: 'blocked' });
    await flush();
    assert.equal(coordinator.snapshot().phase, 'awaiting_choice');
    assert.equal(coordinator.snapshot().queue[0].value, 'A');
});

test('onScanSettled fires for each acknowledged scan (focus restoration hook)', async () => {
    let settled = 0;
    const { coordinator, calls } = harness({ onScanSettled: () => { settled += 1; } });
    coordinator.enqueue('A');
    coordinator.enqueue('B');
    await flush();
    calls[0].resolve({ status: 'not_found' });
    await flush();
    calls[1].resolve({ status: 'applied' });
    await flush();
    assert.equal(settled, 2);
});

// ---------------------------------------------------------------------------
// Overlapping operations (review 2026-09-24).
// ---------------------------------------------------------------------------

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const values = (coordinator) => [...coordinator.snapshot().queue.map((item) => item.value)];

async function ambiguousHead(h, value = 'A') {
    h.coordinator.enqueue(value);
    await flush();
    h.calls[h.calls.length - 1].resolve({ status: 'ambiguous' });
    await flush();
}

test('[P1] a scan arriving while a choice is in flight is neither dropped nor causes a re-request', async () => {
    const h = harness();
    await ambiguousHead(h, 'A');

    h.coordinator.choose(0);
    await flush();
    assert.equal(h.calls.length, 2);
    assert.equal(h.calls[1].method, 'chooseCandidate');

    h.coordinator.enqueue('B');
    await flush();
    assert.equal(h.calls.length, 2, 'B waits for the in-flight choice; A is not re-requested');
    assert.deepEqual(values(h.coordinator), ['A', 'B']);

    h.calls[1].resolve({ status: 'applied' });
    await flush();
    assert.equal(h.calls.length, 3);
    assert.equal(h.calls[2].method, 'scanBarcode');
    assert.deepEqual([...h.calls[2].args], ['B', 'tok-2']);
    h.calls[2].resolve({ status: 'applied' });
    await flush();
    assert.deepEqual(values(h.coordinator), []);
    assert.equal(h.calls.filter((c) => c.method === 'scanBarcode' && c.args[0] === 'A').length, 1);
});

test('[P1] a scan arriving while a cancellation is in flight is kept and sent afterwards', async () => {
    const h = harness();
    await ambiguousHead(h, 'A');

    h.coordinator.cancelChoice();
    await flush();
    h.coordinator.enqueue('B');
    h.coordinator.enqueue('C');
    await flush();
    assert.equal(h.calls.length, 2);

    h.calls[1].resolve({ status: 'cancelled' });
    await flush();
    assert.deepEqual([...h.calls[2].args], ['B', 'tok-2']);
    h.calls[2].resolve({ status: 'applied' });
    await flush();
    assert.deepEqual([...h.calls[3].args], ['C', 'tok-3']);
});

test('[P1] repeated choice clicks while one is in flight send only one choice', async () => {
    const h = harness();
    await ambiguousHead(h, 'A');

    h.coordinator.choose(0);
    h.coordinator.choose(1);
    h.coordinator.cancelChoice();
    await flush();
    assert.equal(h.calls.length, 2);
    h.calls[1].resolve({ status: 'applied' });
    await flush();
    await flush();
    assert.equal(h.calls.length, 2, 'queued clicks find no pending choice and do nothing');
});

test('[P1] retry clicked twice, or while a scan is in flight, sends one request', async () => {
    const h = harness();
    h.coordinator.enqueue('A');
    await flush();
    h.calls[0].reject(new Error('request-failed'));
    await flush();

    h.coordinator.retry();
    h.coordinator.retry();
    h.coordinator.cancelFailed();
    await flush();
    assert.equal(h.calls.length, 2);
    assert.deepEqual([...h.calls[1].args], ['A', 'tok-1']);
    h.calls[1].resolve({ status: 'applied' });
    await flush();
    await flush();
    assert.equal(h.calls.length, 2);
    assert.deepEqual(values(h.coordinator), []);
});

test('[P2 timeout] a slow request shows feedback but keeps the queue blocked until it settles; no retry is offered', async () => {
    const h = harness({ slowAfterMs: 5 });
    h.coordinator.enqueue('A');
    h.coordinator.enqueue('B');
    await flush();
    await sleep(20);

    const slow = h.last();
    assert.equal(slow.slow, true);
    assert.equal(slow.canRetry, false, 'slowness is not a failure');
    assert.match(slow.message, /Koneksi lambat/);
    assert.equal(h.calls.length, 1, 'B is not sent while A is outstanding');
    assert.equal(await h.coordinator.retry(), false);

    let ran = false;
    const save = h.coordinator.requestAction(() => { ran = true; });

    // The late response is the one that settles A, exactly once.
    h.calls[0].resolve({ status: 'applied' });
    await flush();
    assert.equal(h.last().slow, false);
    assert.deepEqual([...h.calls[1].args], ['B', 'tok-2']);
    h.calls[1].resolve({ status: 'applied' });
    assert.equal((await save).ran, true);
    assert.equal(ran, true);
});

test('[P2 timeout] cancelling a failed scan is only possible after the original request settled', async () => {
    const h = harness({ slowAfterMs: 5 });
    h.coordinator.enqueue('A');
    await flush();
    await sleep(15);
    assert.equal(await h.coordinator.cancelFailed(), false, 'nothing to cancel while A is merely slow');
    assert.deepEqual(values(h.coordinator), ['A']);

    h.calls[0].reject(new Error('request-failed'));
    await flush();
    assert.equal(await h.coordinator.cancelFailed(), true);
    assert.deepEqual(values(h.coordinator), []);
});

test('[P3] scans are refused with feedback while save/submit runs, and accepted again if the save fails', async () => {
    const h = harness();
    let finishSave;
    const save = h.coordinator.requestAction(() => new Promise((resolve) => { finishSave = resolve; }));
    await flush();

    assert.equal(h.coordinator.enqueue('LATE'), null);
    assert.match(h.last().notice, /tidak diproses karena dokumen sedang disimpan/);
    assert.equal(h.calls.length, 0);

    finishSave({ keepFrozen: false }); // validation error, page stays
    await save;
    assert.notEqual(h.coordinator.enqueue('AFTER'), null);
    await flush();
    assert.deepEqual([...h.calls[0].args], ['AFTER', 'tok-1']);
});

test('[P3] after a successful save that redirects, intake stays frozen', async () => {
    const h = harness();
    const outcome = await h.coordinator.requestAction(() => ({ keepFrozen: true }));
    assert.equal(outcome.ran, true);
    assert.equal(h.last().intakeFrozen, true);
    assert.equal(h.coordinator.enqueue('X'), null);
    assert.equal(h.calls.length, 0);
    assert.equal((await h.coordinator.requestAction(() => {})).ran, false);
});

test('[P3] a failed save request unfreezes intake', async () => {
    const h = harness();
    const outcome = await h.coordinator.requestAction(() => Promise.reject(new Error('request-failed')));
    assert.ok(outcome.error);
    assert.equal(h.last().intakeFrozen, false);
    assert.notEqual(h.coordinator.enqueue('X'), null);
});

test('[P3] scans accepted while a save is deferred are applied before the save runs', async () => {
    const h = harness();
    h.coordinator.enqueue('A');
    await flush();
    const order = [];
    const save = h.coordinator.requestAction(() => { order.push('save'); });
    h.coordinator.enqueue('B');
    await flush();

    h.calls[0].resolve({ status: 'applied' });
    await flush();
    order.push('B-sent');
    h.calls[1].resolve({ status: 'applied' });
    await save;
    assert.deepEqual(order, ['B-sent', 'save']);
});

test('a fatal transport failure offers no retry, lists unrecorded scans and refuses further work', async () => {
    const h = harness();
    h.coordinator.enqueue('A');
    h.coordinator.enqueue('B');
    await flush();
    h.calls[0].reject(Object.assign(new Error('network'), { fatal: true }));
    await flush();

    const state = h.last();
    assert.equal(state.fatal, true);
    assert.equal(state.canRetry, false);
    assert.match(state.message, /Muat ulang halaman/);
    assert.match(state.message, /A, B/);
    assert.equal(await h.coordinator.retry(), false);
    assert.equal(await h.coordinator.cancelFailed(), false);
    assert.equal(h.coordinator.enqueue('C'), null);
    assert.equal((await h.coordinator.requestAction(() => {})).ran, false);
    assert.equal(h.calls.length, 1);
});
