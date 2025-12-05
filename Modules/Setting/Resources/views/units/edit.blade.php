@extends('layouts.app')

@section('title', 'Edit Unit')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('units.index') }}">Units</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('units.update', $unit) }}" method="POST" id="unit-edit-form">
            @csrf
            @method('put')
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Unit <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required
                                               value="{{ $unit->name }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="short_name">Singkatan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="short_name" required
                                               value="{{ $unit->short_name }}">
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('units.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <x-button type="submit" class="btn btn-primary" processing-text="Menyimpan..." form="unit-edit-form">Perbaharui Unit <i class="bi bi-check"></i></x-button>
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
        initFormSubmissionLock('unit-edit-form', 'unit:submit-error');
    </script>
@endpush
