    @extends('layouts.guest')

    @section('title', 'Terminal Harga')

    @section('content')
        <div class="container py-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <livewire:price-point.browser :setting="$setting" />
                </div>
            </div>
        </div>
    @endsection
