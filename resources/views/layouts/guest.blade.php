<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <meta name="author" content="Leslie Aula">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Favicon + global CSS (same as app layout) --}}
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    @include('includes.main-css')

    {{-- Page-level head slot (optional) --}}
    @stack('head')
</head>
<body class="c-app">
<main class="c-main">
    @yield('content')
</main>


{{-- Global JS (same as app layout) --}}
@include('includes.main-js')

{{-- Page-level scripts slot --}}
@stack('scripts')
</body>
</html>
