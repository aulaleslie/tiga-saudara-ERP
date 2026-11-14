{{--
    Livewire 3.0.5 transport guard (installed once per page).

    Livewire awaits fetch() and response.text() without a catch: a rejected request or a body read
    that fails after headers arrived (network drop, aborted connection) never runs its failure path, so commit `fail` callbacks never fire and its request queue stays
    blocked. This converts either failure of a Livewire update request into a synthetic 503 response, so the
    normal failure path runs (commit/request fail callbacks fire, the queue is released), and
    suppresses Livewire's HTML error modal for that synthetic response only.
--}}
<script data-livewire-transport-guard>
    (function () {
        if (window.__livewireTransportGuardInstalled) return;
        window.__livewireTransportGuardInstalled = true;

        var MARKER = '__livewire_transport_failure__';
        var originalFetch = window.fetch.bind(window);

        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var isLivewireUpdate = init && init.headers && Object.prototype.hasOwnProperty.call(init.headers, 'X-Livewire');
            var promise = originalFetch(input, init);
            if (!isLivewireUpdate && url.indexOf('/livewire/update') === -1) return promise;

            var transportFailure = function () {
                return new Response(JSON.stringify({ message: MARKER }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' },
                });
            };

            // Buffer the body here: the connection can also drop after headers arrive, while
            // Livewire awaits response.text(). Reading it inside this chain turns that failure into
            // the synthetic 503 too. The buffered copy keeps status, headers, redirected and url,
            // which Livewire reads after text().
            return promise.then(function (response) {
                return response.text().then(function (body) {
                    var buffered = new Response(body, {
                        status: response.status,
                        statusText: response.statusText,
                        headers: response.headers,
                    });
                    try {
                        Object.defineProperty(buffered, 'redirected', { value: response.redirected });
                        Object.defineProperty(buffered, 'url', { value: response.url });
                    } catch (e) {}
                    return buffered;
                });
            }).catch(transportFailure);
        };

        var registerHook = function () {
            window.Livewire.hook('request', function (ctx) {
                ctx.fail(function (failure) {
                    if (failure && typeof failure.content === 'string' && failure.content.indexOf(MARKER) !== -1) {
                        failure.preventDefault();
                    }
                });
            });
        };

        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            registerHook();
        } else {
            document.addEventListener('livewire:init', registerHook, { once: true });
        }
    })();
</script>
