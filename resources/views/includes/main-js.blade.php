<script src="{{ asset('vendor/jquery/jquery-3.7.0.min.js') }}"></script>
<script src="{{ asset('js/select2.min.js') }}"></script>
<script src="{{ asset('js/bundle-lifecycle-warning.js') }}"></script>
@vite('resources/js/app.js')

@livewireScripts

<script defer src="{{ asset('vendor/datatables/datatables.min.js') }}"></script>
<script defer src="{{ asset('vendor/datatables/buttons.server-side.js') }}"></script>
<script defer src="{{ asset('vendor/perfect-scrollbar/perfect-scrollbar.min.js') }}"></script>
<script src="{{ asset('vendor/popperjs/popper.min.js') }}"></script>

@include('sweetalert::alert')

@yield('third_party_scripts')

@stack('page_scripts')
@stack('scripts')
