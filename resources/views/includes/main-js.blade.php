<script src="{{ asset('vendor/jquery/jquery-3.7.0.min.js') }}"></script>
<script src="{{ asset('js/select2.min.js') }}"></script>
<script src="{{ asset('js/bundle-lifecycle-warning.js') }}"></script>
<script src="{{ asset('js/session-expiry.js') }}"></script>
@vite('resources/js/app.js')

@livewireScripts

<script>
    document.addEventListener('livewire:init', function () {
        var tabId = window.SessionExpiry ? window.SessionExpiry.getTabId(window.sessionStorage) : null;

        Livewire.hook('request', function ({ options }) {
            var meta = function (name) {
                var el = document.querySelector('meta[name="' + name + '"]');
                return el ? el.content : '';
            };

            options.headers = options.headers || {};
            options.headers['X-ERP-Page-View-Id'] = meta('erp-page-view-id');
            options.headers['X-ERP-Tab-Id'] = tabId || '';
            options.headers['X-ERP-Page-Rendered-At'] = meta('erp-page-rendered-at');
            options.headers['X-ERP-Page-Business-Id'] = meta('erp-page-business-id');
            options.headers['X-ERP-Page-Route'] = meta('erp-page-route');
        });

        if (window.SessionExpiry) {
            window.SessionExpiry.init(document, window);
        }
    });
</script>

<script defer src="{{ asset('vendor/datatables/datatables.min.js') }}"></script>
<script defer src="{{ asset('vendor/datatables/buttons.server-side.js') }}"></script>
<script defer src="{{ asset('vendor/perfect-scrollbar/perfect-scrollbar.min.js') }}"></script>
<script src="{{ asset('vendor/popperjs/popper.min.js') }}"></script>

@include('sweetalert::alert')

@yield('third_party_scripts')

@stack('page_scripts')
@stack('scripts')
