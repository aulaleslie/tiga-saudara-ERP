@extends('layouts.app')

@section('title', 'Buat Mata Uang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('currencies.index') }}">Currencies</a></li>
        <li class="breadcrumb-item active">Add</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('currencies.store') }}" method="POST" id="currency-create-form">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <x-button type="submit" class="btn btn-primary" processing-text="Menyimpan..." form="currency-create-form">Buat Mata Uang <i class="bi bi-check"></i></x-button>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="currency_name">Currency Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="currency_name" required>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="code">Currency Code <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="code" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="symbol">Symbol <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="symbol" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="thousand_separator">Thousand Separator <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="thousand_separator" required>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="decimal_separator">Decimal Separator <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="decimal_separator" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script>
        // Initialize form submission lock
        initFormSubmissionLock('currency-create-form', 'currency:submit-error');
    </script>
@endpush

