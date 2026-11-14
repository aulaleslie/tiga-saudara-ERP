{{--
    Livewire 3.0.5 transport guard (installed once per page).

    Livewire awaits fetch() without a catch: a rejected request (network drop, aborted connection)
    never runs its failure path, so commit `fail` callbacks never fire and its request queue stays
    blocked. This converts a rejected Livewire update request into a synthetic 503 response, so the
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

            return promise.catch(function () {
                return new Response(JSON.stringify({ message: MARKER }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' },
                });
            });
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
