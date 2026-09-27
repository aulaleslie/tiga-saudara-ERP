/*
 * Stock Transfer workflow v3 scan coordinator (DOM-free core).
 *
 * FIFO scan queue with per-scan operation tokens, derived from the legacy
 * transfer scanner (resources/views/livewire/transfer/search-product.blade.php)
 * but with explicit phases so that:
 *  - a scan leaves the queue only after its response is acknowledged;
 *  - an ambiguous scan pauses the queue until the operator chooses a
 *    candidate or cancels that scan (the choice is bound to the scan token);
 *  - a failed request pauses the queue, keeping the failed scan and every
 *    later scan, until the operator retries (same token) or cancels it;
 *  - save/submit actions only run once the queue is idle.
 *
 * Loaded inline by transfer-v3-goods-form.blade.php (no bundler) and by
 * tests/js/transfer-v3-scan-coordinator.test.mjs through node:vm, so it is a
 * plain script that attaches to the global object.
 */
(function (root) {
    'use strict';

    var MESSAGES = {
        processing: function (count) {
            return 'Memproses pindaian… (' + count + ' dalam antrean)';
        },
        awaitingChoice: 'Pindaian menunggu pilihan produk. Pilih salah satu hasil atau batalkan pindaian ini. Pindaian berikutnya ditahan dalam antrean.',
        failed: function (value) {
            return 'Gagal memproses pindaian "' + value + '". Periksa koneksi, lalu klik Coba Lagi atau Batalkan Pindaian.';
        },
        failedChoice: 'Gagal menyimpan pilihan produk. Klik Coba Lagi atau Batalkan untuk kembali ke daftar pilihan.',
        waitingForAction: 'Menunggu seluruh pindaian selesai diproses sebelum menyimpan…',
        blockedByChoice: 'Tidak dapat menyimpan: masih ada pindaian yang menunggu pilihan produk. Pilih produk atau batalkan pindaian tersebut terlebih dahulu.',
        blockedByFailure: 'Tidak dapat menyimpan: ada pindaian yang gagal diproses. Klik Coba Lagi atau Batalkan Pindaian terlebih dahulu.',
        actionAbandoned: 'Penyimpanan dibatalkan karena ada pindaian yang perlu ditindaklanjuti. Selesaikan pindaian tersebut, lalu simpan kembali.',
        actionBusy: 'Penyimpanan sedang diproses.',
        slow: function (value) {
            return 'Koneksi lambat: masih menunggu respons server untuk "' + value + '". Pindaian berikutnya ditahan dalam antrean. Jangan muat ulang halaman.';
        },
        scanRejectedDuringSave: function (value) {
            return 'Pindaian "' + value + '" tidak diproses karena dokumen sedang disimpan. Pindai ulang setelah penyimpanan selesai.';
        },
        saving: 'Menyimpan dokumen… Pindaian baru tidak diterima selama penyimpanan.',
        fatal: function (values) {
            return 'Koneksi ke server terputus dan halaman tidak dapat mengirim permintaan lagi. Muat ulang halaman.'
                + (values.length ? ' Pindaian berikut belum tercatat dan perlu dipindai ulang: ' + values.join(', ') + '.' : '')
                + ' Perubahan yang belum disimpan pada formulir ini tidak ikut tersimpan.';
        },
    };

    var TERMINATOR_KEYS = { Enter: true, NumpadEnter: true };

    function isScanTerminator(event) {
        if (!event) {
            return false;
        }
        return TERMINATOR_KEYS[event.key] === true || TERMINATOR_KEYS[event.code] === true
            || event.keyCode === 13 || event.keyCode === 10 || event.which === 13 || event.which === 10;
    }

    /**
     * Splits a raw input buffer on CR/LF. Returns complete codes and the
     * trailing, still-incomplete remainder.
     */
    function splitScanBuffer(buffer) {
        var parts = String(buffer == null ? '' : buffer).split(/[\r\n]+/);
        var rest = parts.pop();
        return {
            codes: parts.map(function (part) { return part.trim(); }).filter(function (part) { return part !== ''; }),
            rest: rest,
        };
    }

    function defaultToken() {
        return 'v3scan-' + Date.now() + '-' + Math.random().toString(36).substring(2, 10);
    }

    /**
     * @param {{
     *   call: function(string, ...*): Promise<*>,
     *   render?: function(object): void,
     *   onScanSettled?: function(): void,
     *   generateToken?: function(): string,
     *   slowAfterMs?: number,
     * }} options
     *
     * `call` must settle only when the underlying server request has
     * actually settled (resolved with its response, or rejected because the
     * request failed). It must never reject merely because it is slow: the
     * coordinator keeps the queue blocked until the original request settles,
     * so a late response can never land after a retry or cancellation.
     */
    function create(options) {
        var call = options.call;
        var render = options.render || function () {};
        var onScanSettled = options.onScanSettled || function () {};
        var generateToken = options.generateToken || defaultToken;
        var slowAfterMs = options.slowAfterMs == null ? 8000 : options.slowAfterMs;

        // phase: idle | processing | awaiting_choice | failed
        var state = {
            phase: 'idle',
            queue: [],
            failure: null,
            notice: null,
            slowValue: null,
            actionRunning: false,
            intakeFrozen: false,
            // Set when the transport itself is unusable (e.g. a network
            // failure that leaves Livewire unable to send further requests).
            // Nothing can be retried; the operator must reload.
            fatal: false,
        };
        var pendingAction = null;
        // The single in-flight lock. Every server request the coordinator
        // makes (scan, choice, cancellation, retry) runs inside the worker,
        // so at most one is ever outstanding and they settle in order.
        var worker = null;

        function snapshot() {
            return {
                phase: state.phase,
                queueLength: state.queue.length,
                queue: state.queue.map(function (item) { return { value: item.value, token: item.token }; }),
                failure: state.failure ? { kind: state.failure.kind, value: state.failure.item.value, token: state.failure.item.token } : null,
                notice: state.notice,
                message: statusMessage(),
                slow: state.slowValue !== null,
                fatal: state.fatal,
                canRetry: state.phase === 'failed' && !state.fatal,
                waitingAction: pendingAction !== null,
                actionRunning: state.actionRunning,
                intakeFrozen: state.intakeFrozen,
                busy: worker !== null,
                idle: isIdle(),
            };
        }

        function statusMessage() {
            if (state.fatal) {
                return MESSAGES.fatal(state.queue.map(function (item) { return item.value; }));
            }
            if (state.phase === 'failed') {
                return state.failure.kind === 'scan' ? MESSAGES.failed(state.failure.item.value) : MESSAGES.failedChoice;
            }
            if (state.slowValue !== null) {
                return MESSAGES.slow(state.slowValue);
            }
            if (state.phase === 'awaiting_choice') {
                return MESSAGES.awaitingChoice;
            }
            if (state.phase === 'processing' || state.queue.length > 0) {
                return MESSAGES.processing(state.queue.length);
            }
            if (state.actionRunning || state.intakeFrozen) {
                return MESSAGES.saving;
            }
            return null;
        }

        function emit() {
            render(snapshot());
        }

        function isIdle() {
            return state.phase === 'idle' && state.queue.length === 0 && worker === null;
        }

        function fail(kind, item, extra, error) {
            if (error && error.fatal) {
                state.fatal = true;
                state.intakeFrozen = true;
            }
            state.phase = 'failed';
            state.failure = { kind: kind, item: item, extra: extra || null };
            abandonPendingAction();
            emit();
        }

        function abandonPendingAction() {
            if (pendingAction) {
                var action = pendingAction;
                pendingAction = null;
                state.notice = MESSAGES.actionAbandoned;
                action.resolve({ ran: false, reason: 'abandoned', message: MESSAGES.actionAbandoned });
            }
        }

        /**
         * Sends one request, showing slow-connection feedback while it is
         * outstanding. Never gives up on its own: see `call` above.
         */
        async function request(item, method, args) {
            var timer = setTimeout(function () {
                state.slowValue = item.value;
                emit();
            }, slowAfterMs);
            try {
                return await call.apply(null, [method].concat(args));
            } finally {
                clearTimeout(timer);
                if (state.slowValue !== null) {
                    state.slowValue = null;
                    emit();
                }
            }
        }

        /**
         * Starts the worker if none is running. `first` (optional) runs
         * before the FIFO loop -- used for a choice, cancellation or retry --
         * so it holds the same lock as scans.
         */
        function kick(first) {
            if (worker) {
                return worker;
            }
            var current = (async function () {
                if (first) {
                    await first();
                }
                await drainLoop();
            })().finally(function () {
                if (worker === current) {
                    worker = null;
                }
                // Anything that arrived while the worker was finishing, or a
                // deferred save now that the queue is idle.
                if (state.phase === 'idle' && state.queue.length > 0) {
                    kick();
                } else {
                    emit();
                    runPendingAction();
                }
            });
            worker = current;
            return current;
        }

        async function drainLoop() {
            while (state.queue.length > 0) {
                if (state.phase === 'awaiting_choice' || state.phase === 'failed') {
                    return;
                }
                state.phase = 'processing';
                emit();

                var head = state.queue[0];
                var result;
                try {
                    result = await request(head, 'scanBarcode', [head.value, head.token]);
                } catch (error) {
                    fail('scan', head, null, error);
                    return;
                }

                var status = result && result.status;
                if (status === 'ambiguous' || status === 'blocked') {
                    // Server holds (or still holds) a pending choice:
                    // keep this scan at the head and pause.
                    state.phase = 'awaiting_choice';
                    abandonPendingAction();
                    emit();
                    return;
                }

                // Acknowledged (applied, duplicate, rejected, not found, replayed).
                state.queue.shift();
                onScanSettled(result);
            }
            state.phase = 'idle';
        }

        function runPendingAction() {
            if (!pendingAction || !isIdle()) {
                return;
            }
            var action = pendingAction;
            pendingAction = null;
            execute(action.run).then(action.resolve);
        }

        /**
         * Runs a save/submit with scan intake frozen. If the action reports
         * `keepFrozen` (it succeeded and the page is about to redirect),
         * intake stays frozen so no scan can be accepted and then lost.
         */
        function execute(run) {
            state.actionRunning = true;
            state.intakeFrozen = true;
            state.notice = null;
            emit();
            return Promise.resolve()
                .then(run)
                .then(function (value) {
                    return { ran: true, value: value };
                }, function (error) {
                    return { ran: true, error: error };
                })
                .then(function (outcome) {
                    state.actionRunning = false;
                    if (outcome.error && outcome.error.fatal) {
                        state.fatal = true;
                    }
                    state.intakeFrozen = state.fatal || Boolean(!outcome.error && outcome.value && outcome.value.keepFrozen);
                    emit();
                    return outcome;
                });
        }

        function enqueue(rawValue) {
            var value = String(rawValue == null ? '' : rawValue).replace(/[\r\n]+/g, '').trim();
            if (value === '') {
                return null;
            }
            if (state.fatal) {
                state.notice = 'Pindaian "' + value + '" tidak diproses. Muat ulang halaman terlebih dahulu.';
                emit();
                return null;
            }
            if (state.intakeFrozen) {
                state.notice = MESSAGES.scanRejectedDuringSave(value);
                emit();
                return null;
            }
            var item = { value: value, token: generateToken() };
            state.queue.push(item);
            state.notice = null;
            emit();
            kick();
            return item.token;
        }

        /**
         * Waits until no worker holds the lock. Callers must re-check their
         * preconditions synchronously right after awaiting this (and before
         * starting a worker), so two clicks cannot both claim the same step.
         */
        async function lockFree() {
            while (worker) {
                await worker;
            }
        }

        function choiceTask(kind, index) {
            return async function () {
                if (state.phase !== 'awaiting_choice' || state.queue.length === 0) {
                    return;
                }
                var head = state.queue[0];
                state.phase = 'processing';
                emit();

                var result;
                try {
                    result = kind === 'choose'
                        ? await request(head, 'chooseCandidate', [index, head.token])
                        : await request(head, 'cancelCandidates', [head.token]);
                } catch (error) {
                    fail(kind, head, { index: index }, error);
                    return;
                }

                if (result && result.status === 'stale') {
                    // Server's pending scan is not this one; keep waiting.
                    state.phase = 'awaiting_choice';
                    emit();
                    return;
                }

                state.queue.shift();
                state.phase = 'idle';
                onScanSettled(result);
                emit();
            };
        }

        async function resolveChoice(kind, index) {
            if (state.phase !== 'awaiting_choice' || state.fatal) {
                return false;
            }
            await lockFree();
            if (worker || state.phase !== 'awaiting_choice' || state.queue.length === 0) {
                return false;
            }
            var head = state.queue[0];
            await kick(choiceTask(kind, index));
            return state.queue[0] !== head;
        }

        function choose(index) {
            return resolveChoice('choose', index);
        }

        function cancelChoice() {
            return resolveChoice('cancel');
        }

        async function retry() {
            if (state.phase !== 'failed' || state.fatal) {
                return false;
            }
            await lockFree();
            if (worker || state.phase !== 'failed' || !state.failure) {
                return false;
            }
            var failure = state.failure;
            state.failure = null;

            if (failure.kind === 'scan') {
                // Same token. The failed request has fully settled, so the
                // retry cannot overlap it; the server ignores the token if a
                // prior attempt was applied.
                state.phase = 'idle';
                emit();
                await kick();
                return true;
            }

            state.phase = 'awaiting_choice';
            await kick(choiceTask(failure.kind, failure.extra ? failure.extra.index : undefined));
            return true;
        }

        async function cancelFailed() {
            if (state.phase !== 'failed' || state.fatal) {
                return false;
            }
            await lockFree();
            if (worker || state.phase !== 'failed' || !state.failure) {
                return false;
            }
            var failure = state.failure;
            state.failure = null;

            if (failure.kind === 'scan') {
                // Explicit operator decision: discard only the failed scan.
                if (state.queue[0] === failure.item) {
                    state.queue.shift();
                }
                state.phase = 'idle';
                emit();
                await kick();
                return true;
            }

            // A failed choice/cancel request changed nothing on the server;
            // return to the candidate list for this same scan.
            state.phase = 'awaiting_choice';
            emit();
            return true;
        }

        /**
         * Runs `run` once the queue is idle. Refuses immediately while a scan
         * awaits a choice or a failure decision; defers while scans are queued
         * or in flight.
         */
        function requestAction(run) {
            if (state.fatal) {
                return Promise.resolve({ ran: false, reason: 'fatal', message: statusMessage() });
            }
            if (state.actionRunning || state.intakeFrozen || pendingAction) {
                return Promise.resolve({ ran: false, reason: 'busy', message: MESSAGES.actionBusy });
            }
            if (state.phase === 'awaiting_choice') {
                state.notice = MESSAGES.blockedByChoice;
                emit();
                return Promise.resolve({ ran: false, reason: 'awaiting_choice', message: MESSAGES.blockedByChoice });
            }
            if (state.phase === 'failed') {
                state.notice = MESSAGES.blockedByFailure;
                emit();
                return Promise.resolve({ ran: false, reason: 'failed', message: MESSAGES.blockedByFailure });
            }
            if (!isIdle()) {
                return new Promise(function (resolve) {
                    pendingAction = { run: run, resolve: resolve };
                    state.notice = MESSAGES.waitingForAction;
                    emit();
                });
            }
            return execute(run);
        }

        /**
         * The transport can no longer send requests (reported by the
         * Livewire adapter, whatever request failed). Freezes everything and
         * tells the operator to reload; queued scans are listed as
         * unrecorded. Any in-flight request is rejected by the adapter.
         */
        function markFatal() {
            if (state.fatal) {
                return;
            }
            state.fatal = true;
            state.intakeFrozen = true;
            abandonPendingAction();
            state.notice = null;
            emit();
        }

        return {
            markFatal: markFatal,
            enqueue: enqueue,
            choose: choose,
            cancelChoice: cancelChoice,
            retry: retry,
            cancelFailed: cancelFailed,
            requestAction: requestAction,
            isIdle: isIdle,
            snapshot: snapshot,
            whenDrained: function () { return worker || Promise.resolve(); },
        };
    }

    root.V3TransferScanCoordinator = {
        create: create,
        isScanTerminator: isScanTerminator,
        splitScanBuffer: splitScanBuffer,
        MESSAGES: MESSAGES,
    };
})(typeof window !== 'undefined' ? window : globalThis);
