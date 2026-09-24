/*
 * Stock Transfer workflow v3: Livewire transport adapter for the scan
 * coordinator (resources/js/transfer/v3-scan-coordinator.js).
 *
 * Provides `call(method, ...args)` that settles only when Livewire has
 * actually settled the request, and detects an unusable transport.
 *
 * Written against Livewire v3.0.5 (composer.lock) request lifecycle:
 *  1. `commit` hooks fire when the component's commit is compiled;
 *  2. the `request` hook fires, then `fetch` runs;
 *  3. on an HTTP error, the commit `fail` callbacks run first (handleFailure),
 *     THEN the request `fail` callbacks run with `preventDefault`, and the
 *     error modal is shown unless prevented (419 triggers page expiry);
 *  4. on a network error, `fetch` rejects, no hook fires, and Livewire's
 *     request queue stays blocked (`sendingRequest` is never reset), so no
 *     further request from any component on the page can be sent.
 *
 * Loaded inline by transfer-v3-goods-form.blade.php and by
 * tests/js/transfer-v3-livewire-transport.test.mjs through node:vm.
 */
(function (root) {
    'use strict';

    function fatalError(message) {
        var error = new Error(message || 'network-failed');
        error.fatal = true;
        return error;
    }

    function isLivewireRequest(init) {
        var headers = init && init.headers;
        if (!headers) {
            return false;
        }
        if (typeof headers.has === 'function') {
            return headers.has('X-Livewire');
        }
        return Object.prototype.hasOwnProperty.call(headers, 'X-Livewire');
    }

    /**
     * @param {{
     *   livewire: {hook: function(string, function): void},
     *   host: {fetch: function},
     *   getComponent: function(): ?{call: function},
     *   getComponentId: function(): ?string,
     *   onFatal?: function(Error): void,
     * }} options
     */
    function create(options) {
        var getComponent = options.getComponent;
        var getComponentId = options.getComponentId;
        var onFatal = options.onFatal || function () {};

        var inflight = new Set();
        var broken = false;

        function rejectInflight(error) {
            var entries = Array.from(inflight);
            inflight.clear();
            entries.forEach(function (entry) { entry.reject(error); });
        }

        function markBroken() {
            if (broken) {
                return;
            }
            broken = true;
            var error = fatalError();
            rejectInflight(error);
            onFatal(error);
        }

        function call(method) {
            var args = Array.prototype.slice.call(arguments, 1);
            if (broken) {
                return Promise.reject(fatalError());
            }
            var component = getComponent();
            if (!component) {
                return Promise.reject(new Error('component-unavailable'));
            }
            return new Promise(function (resolve, reject) {
                var entry = { reject: reject };
                inflight.add(entry);
                Promise.resolve(component.call.apply(component, [method].concat(args))).then(function (value) {
                    if (inflight.delete(entry)) {
                        resolve(value);
                    }
                }, function (error) {
                    if (inflight.delete(entry)) {
                        reject(error);
                    }
                });
            });
        }

        function payloadIncludesComponent(payload, id) {
            if (!id || typeof payload !== 'string') {
                return false;
            }
            try {
                var body = JSON.parse(payload);
                return (body.components || []).some(function (commit) {
                    var snapshot = typeof commit.snapshot === 'string' ? JSON.parse(commit.snapshot) : commit.snapshot;
                    return snapshot && snapshot.memo && snapshot.memo.id === id;
                });
            } catch (error) {
                return payload.indexOf(id) !== -1;
            }
        }

        // Request hook fires BEFORE any failure callback, so ownership is
        // captured here, while `inflight` still holds our entries. The
        // captured flag drives popup suppression later, independent of the
        // commit hook having already cleared `inflight`.
        options.livewire.hook('request', function (hook) {
            var owned = inflight.size > 0 && payloadIncludesComponent(hook.payload, getComponentId());
            hook.fail(function (failure) {
                if (owned && failure && failure.status !== 419 && typeof failure.preventDefault === 'function') {
                    failure.preventDefault();
                }
            });
        });

        // HTTP error for this component's commit: Livewire has finished with
        // the request and discarded its response, so a retry is safe.
        options.livewire.hook('commit', function (hook) {
            if (!hook.component || hook.component.id !== getComponentId()) {
                return;
            }
            hook.fail(function () {
                rejectInflight(new Error('request-failed'));
            });
        });

        // Any Livewire request whose fetch rejects -- a scan, a save, a
        // modal search, a quantity edit, another component's update --
        // leaves Livewire unable to send further requests.
        var host = options.host;
        if (host && typeof host.fetch === 'function' && !host.__v3TransferFetchGuard) {
            var originalFetch = host.fetch;
            var guarded = function (input, init) {
                var pending = originalFetch.apply(this, arguments);
                if (isLivewireRequest(init)) {
                    pending.then(null, function () { markBroken(); });
                }
                return pending;
            };
            guarded.__v3Original = originalFetch;
            host.fetch = guarded;
            host.__v3TransferFetchGuard = true;
        }

        return {
            call: call,
            isBroken: function () { return broken; },
            inflightCount: function () { return inflight.size; },
        };
    }

    root.V3TransferLivewireTransport = { create: create };
})(typeof window !== 'undefined' ? window : globalThis);
