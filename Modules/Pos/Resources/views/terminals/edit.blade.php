@extends('layouts.app')

@section('title', 'Ubah Terminal POS')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Ubah Terminal POS</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pos.terminals.update', $terminal->id) }}">
                    @csrf
                    @method('PUT')
                    @include('pos::terminals._form', ['terminal' => $terminal])
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Perbarui</button>
                        <a href="{{ route('pos.terminals.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
