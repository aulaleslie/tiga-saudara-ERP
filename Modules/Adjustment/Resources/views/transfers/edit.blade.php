@extends('layouts.app')

@section('title', 'Update Stock Transfer')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfers</a></li>
        <li class="breadcrumb-item active">Update Transfer</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')

                        <livewire:transfer.transfer-stock-form :transfer="$transfer" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        // Initialize form submission lock
        initFormSubmissionLock('transfer-edit-form', 'transfer:submit-error');
    </script>
@endpush
