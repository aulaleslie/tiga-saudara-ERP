@extends('layouts.app')

@section('title', 'Monitoring Sesi POS')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')
            </div>
            <div class="col-12">
                <livewire:pos.session-monitor />
            </div>
        </div>
    </div>
@endsection
