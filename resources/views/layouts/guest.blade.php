<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title') | {{ config('app.name') }}</title>
    <meta name="author" content="Leslie Aula">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Favicon + global CSS (Bootstrap/CoreUI + Tailwind already included here) --}}
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    @include('includes.main-css')

</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased selection:bg-indigo-100 selection:text-indigo-900">
<main class="min-h-screen flex flex-col">
    {{-- Page content renders here. Children (like Terminal Harga) can use Bootstrap or Tailwind freely. --}}
    <div class="flex-1">
        @yield('content')
    </div>
</main>

{{-- Global JS (same as app layout) --}}
@include('includes.main-js')

{{-- Page-level scripts slot --}}
@stack('scripts')
</body>
</html>
