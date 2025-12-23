<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <meta name="author" content="Leslie Aula">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    @include('includes.main-css')

    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
    </style>
</head>
<body class="min-h-screen antialiased">

<main class="min-h-screen">
    @yield('content')
</main>

@include('includes.main-js')

@stack('scripts')
</body>
</html>
