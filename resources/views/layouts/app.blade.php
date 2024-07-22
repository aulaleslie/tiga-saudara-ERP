<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title') || {{ config('app.name') }}</title>
    <meta content="Leslie Aula" name="author">
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    @include('includes.main-css')
</head>

<body class="c-app">
    @include('layouts.sidebar')

    <div class="c-wrapper">
        <header class="c-header c-header-fixed">
            @include('layouts.header')
{{--            <div class="c-subheader justify-content-between px-3">--}}
{{--                @yield('breadcrumb')--}}
{{--            </div>--}}
        </header>

        <div class="c-body">
{{--            @include('layouts.sub-sidebar')--}}
            <main class="c-main">
                <div class="container-fluid">
                    <div class="row">
                        @hasSection('sub-sidebar')
                            <div class="col-md-3">
                                @yield('sub-sidebar')
                            </div>
                            <div class="col-md-9">
                                @yield('content')
                            </div>
                        @else
                            <div class="col-md-12">
                                @yield('content')
                            </div>
                        @endif
                    </div>
                </div>
            </main>
        </div>

        @include('layouts.footer')
    </div>

    @include('includes.main-js')
</body>
</html>
