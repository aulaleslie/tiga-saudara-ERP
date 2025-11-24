@extends('layouts.pos')

@section('title', 'Sesi POS')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-xl-6 col-lg-8 mx-auto">
                <livewire:pos.session-manager />
            </div>
        </div>
    </div>
@endsection
