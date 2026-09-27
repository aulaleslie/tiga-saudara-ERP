@extends('layouts.app')

@section('title', 'Buat Retur POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.sessions.index') }}">POS</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.returns.index') }}">Retur POS</a></li>
        <li class="breadcrumb-item active">Buat Retur</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <livewire:pos-return.pos-return-create-form />
    </div>
@endsection
