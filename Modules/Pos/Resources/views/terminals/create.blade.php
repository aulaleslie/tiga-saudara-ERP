@extends('layouts.app')

@section('title', 'Buat Terminal POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Buat Terminal POS</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pos.terminals.store') }}">
                    @csrf
                    @include('pos::terminals._form', ['terminal' => null])
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <a href="{{ route('pos.terminals.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
