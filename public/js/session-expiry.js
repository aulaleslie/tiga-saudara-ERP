(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        module.exports.default = module.exports;
    } else if (root) {
        root.SessionExpiry = factory();
    } else if (typeof globalThis !== 'undefined') {
        globalThis.SessionExpiry = factory();
    }
}(typeof self !== 'undefined' ? self : (typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this)), function () {
    'use strict';

    var TRANSACTION_ROUTE_MARKERS = ['purchase', 'receiving', 'payment', 'sale', 'return', 'stock', 'pos'];

    /**
     * Resolve or create a per-tab identifier stored in sessionStorage, so it
     * survives reloads of the same tab but not a new tab/window.
     */
    function getTabId(storage) {
        try {
            var existing = storage.getItem('erp_tab_id');
            if (existing) {
                return existing;
            }

            var generated = 'tab-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
            storage.setItem('erp_tab_id', generated);
            return generated;
        } catch (e) {
            return null;
        }
    }

    /**
     * Parse the minimal incident JSON contract out of a failed Livewire
     * response. Returns null when the shape doesn't match what we expect.
     */
    function parseIncidentPayload(responseText) {
        if (!responseText) {
            return null;
        }

        var data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            return null;
        }

        if (!data || typeof data !== 'object' || !data.incident_id) {
            return null;
        }

        return {
            incidentId: String(data.incident_id),
            message: typeof data.message === 'string' ? data.message : null,
            transactionSensitive: Boolean(data.transaction_sensitive),
        };
    }

    function isTransactionSensitiveRoute(routeName) {
        if (!routeName) {
            return false;
        }

        var lower = String(routeName).toLowerCase();

        return TRANSACTION_ROUTE_MARKERS.some(function (marker) {
            return lower.indexOf(marker) !== -1;
        });
    }

    /**
     * Build the SweetAlert2 configuration for the session-expiry modal.
     */
    function buildModalConfig(incident, doc) {
        var routeName = doc.querySelector('meta[name="erp-page-route"]');
        var transactionSensitive = incident.transactionSensitive || isTransactionSensitiveRoute(routeName && routeName.content);

        var html = '<p>' + (incident.message || 'Sesi Anda telah berakhir. Silakan muat ulang halaman atau masuk kembali.') + '</p>';

        if (transactionSensitive) {
            html += '<p style="background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:.5rem .75rem;">'
                + 'Sebelum mencoba lagi, periksa apakah transaksi sebelumnya sudah tersimpan agar tidak terjadi duplikasi.'
                + '</p>';
        }

        html += '<div style="background:#f4f5f7;border-radius:6px;padding:.5rem .75rem;font-family:monospace;word-break:break-all;margin-top:.5rem;">'
            + incident.incidentId + '</div>';

        return {
            title: 'Sesi Anda Telah Berakhir',
            html: html,
            icon: 'warning',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Muat Ulang Halaman',
            denyButtonText: 'Salin ID Insiden',
            cancelButtonText: 'Tutup',
            allowOutsideClick: false,
        };
    }

    /**
     * Show a minimal fallback dialog when SweetAlert2 is unavailable.
     */
    function showFallback(incident, win) {
        var message = (incident && incident.incidentId)
            ? 'Sesi Anda telah berakhir. Nomor insiden: ' + incident.incidentId + '. Muat ulang halaman untuk melanjutkan.'
            : 'Sesi Anda telah berakhir. Muat ulang halaman untuk melanjutkan.';

        win.alert(message);
    }

    /**
     * Register the global Livewire request-failure hook that suppresses the
     * native 419 confirmation and shows the application modal instead.
     *
     * Livewire 3's "request" hook does not use a returned { error() {} }
     * object; it passes a `fail` registration function that must be called
     * synchronously with a callback receiving { status, content,
     * preventDefault }. Calling preventDefault() there is what skips
     * Livewire's own handlePageExpiry()/showFailureModal() for this request.
     */
    function registerLivewireHook(livewire, doc, win, swal) {
        livewire.hook('request', function (context) {
            context.fail(function (failure) {
                if (failure.status !== 419) {
                    return;
                }

                failure.preventDefault();

                var incident = parseIncidentPayload(failure.content) || {
                    incidentId: 'ERP-INCIDENT-UNAVAILABLE',
                    message: null,
                    transactionSensitive: false,
                };

                if (swal && typeof swal.fire === 'function') {
                    swal.fire(buildModalConfig(incident, doc)).then(function (result) {
                        if (result.isConfirmed) {
                            win.location.reload();
                        } else if (result.isDenied && win.navigator && win.navigator.clipboard) {
                            win.navigator.clipboard.writeText(incident.incidentId);
                        }
                    });
                } else {
                    showFallback(incident, win);
                }
            });
        });
    }

    /**
     * Register the hook directly against the already-available Livewire
     * global. Must be called from the same livewire:init listener that first
     * detects window.Livewire, not from a second livewire:init listener,
     * since that event has already fired by the time this module loads.
     */
    function init(doc, win) {
        if (win.Livewire) {
            registerLivewireHook(win.Livewire, doc, win, win.Swal);
        }
    }

    return {
        getTabId: getTabId,
        parseIncidentPayload: parseIncidentPayload,
        isTransactionSensitiveRoute: isTransactionSensitiveRoute,
        buildModalConfig: buildModalConfig,
        showFallback: showFallback,
        registerLivewireHook: registerLivewireHook,
        init: init,
    };
}));
