@php use Modules\People\Entities\Supplier; @endphp
@extends('layouts.app')

@section('title', 'Create Purchase Return')

@section('content')
    <div class="container-fluid">
        {{-- Alerts --}}
        @include('utils.alerts')

        {{-- Purchase Return Form --}}
        <form id="purchase-return-form" action="{{ route('purchase-returns.store') }}" method="POST">
            @csrf

            {{-- Supplier & Date Inputs --}}
            <livewire:purchase-return.purchase-return-create-form/>

            {{-- Submit Button --}}
{{--            <div class="mt-3">--}}
{{--                <button type="submit" class="btn btn-primary">Proses Retur</button>--}}
{{--                <a href="{{ route('purchase-returns.index') }}" class="btn btn-secondary">Kembali</a>--}}
{{--            </div>--}}
        </form>
    </div>
@endsection
