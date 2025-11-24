@extends('layouts.pos')

@section('title', 'Penjemputan Kas')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12 mb-3">
                <livewire:pos.session-manager />
            </div>
            <div class="col-12">
                @include('sale::pos.partials.cash-navigation')
            </div>
            <div class="col-xl-6 col-lg-8 mx-auto">
                <livewire:pos.cash-pickup />
            </div>
        </div>
    </div>
@endsection
