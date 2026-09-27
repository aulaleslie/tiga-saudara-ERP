@extends('layouts.app')

@section('title', 'Daftar Retur POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.sessions.index') }}">POS</a></li>
        <li class="breadcrumb-item active">Retur POS</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-arrow-return-left"></i> <strong>Retur POS</strong>
                </div>
                @can('pos.returns.create')
                    <a href="{{ route('pos.returns.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus"></i> Buat Retur
                    </a>
                @endcan
            </div>
            <div class="card-body">
                <livewire:pos-return.pos-return-table />
            </div>
        </div>
    </div>
@endsection
