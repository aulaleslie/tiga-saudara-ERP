@extends('layouts.guest')

@section('title', 'Terminal Harga')

@section('content')
    <div class="mx-auto max-w-6xl px-3 sm:px-4 lg:px-6 py-4 sm:py-6 lg:py-8">
        <div class="bg-white/90 supports-[backdrop-filter]:bg-white/70 backdrop-blur rounded-2xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
            <livewire:price-point.browser :setting="$setting" />
        </div>
    </div>
@endsection
