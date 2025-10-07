@extends('layouts.pos')

@section('title', 'Rekonsiliasi Kas')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12">
                @include('sale::pos.partials.cash-navigation')
            </div>
            <div class="col-xl-6 col-lg-8 mx-auto">
                <livewire:pos.cash-reconciliation />
            </div>
        </div>
    </div>
@endsection
