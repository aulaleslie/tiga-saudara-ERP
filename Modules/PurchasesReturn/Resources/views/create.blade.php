@php use Modules\People\Entities\Supplier; @endphp
@extends('layouts.app')

@section('title', 'Create Purchase Return')

@section('content')
    <div class="container-fluid">
        <livewire:purchase-return.purchase-return-create-form/>
    </div>
@endsection
