<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <meta name="author" content="Leslie Aula">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" href="{{ asset('images/favicon.png') }}">

    @include('includes.main-css')
</head>
<body class="min-h-screen antialiased">
<main class="min-h-screen">
    @yield('content')
</main>

@include('includes.main-js')
</body>
</html>
