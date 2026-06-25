<!-- Dropezone CSS -->
<link rel="stylesheet" href="{{ asset('css/dropzone.css') }}">
<!-- Select2 CSS -->
<link rel="stylesheet" href="{{ asset('css/select2.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/select2-coreui.min.css') }}">
<!-- CoreUI CSS -->
@vite('resources/sass/app.scss')
@vite('resources/css/tw.css')
<link href="{{ asset('vendor/datatables/datatables.min.css') }}" rel="stylesheet">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">

@yield('third_party_stylesheets')

@stack('page_css')

@livewireStyles

<style>
    [x-cloak] { display: none !important; }

    div.dataTables_wrapper div.dataTables_length select {
        width: 65px;
        display: inline-block;
    }
    .select2-container--default .select2-selection--single {
        background-color: #fff;
        border: 1px solid #D8DBE0;
        border-radius: 4px;
    }
    .select2-container--default .select2-selection--multiple {
        background-color: #fff;
        border: 1px solid #D8DBE0;
        border-radius: 4px;
    }
    .select2-container .select2-selection--multiple {
        height: 35px;
    }
    .select2-container .select2-selection--single {
        height: 35px;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 33px;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow b {
        margin-top: 2px;
    }

    .text-uppercase {
        text-transform: uppercase;
    }
</style>
