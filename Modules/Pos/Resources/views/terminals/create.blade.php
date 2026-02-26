@extends('layouts.app')

@section('title', 'Create POS Terminal')

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')

        <div class="card">
            <div class="card-header">Create POS Terminal</div>
            <div class="card-body">
                <form method="POST" action="{{ route('pos.terminals.store') }}">
                    @csrf
                    @include('pos::terminals._form', ['terminal' => null])
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save</button>
                        <a href="{{ route('pos.terminals.index') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
