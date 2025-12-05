@extends('layouts.app')

@section('title', 'Ubah Pajak')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('units.index') }}">Units</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('taxes.update', $tax) }}" method="POST" id="tax-edit-form">
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
                                        <label for="name">Nama Pajak<span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required
                                               value="{{ $tax->name }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="value">Nilai Presentase Pajak <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="value" step="0.01" value="{{ $tax->value }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('taxes.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <x-button type="submit" class="btn btn-primary" processing-text="Menyimpan..." form="tax-edit-form">Simpan <i class="bi bi-check"></i></x-button>
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
        initFormSubmissionLock('tax-edit-form', 'tax:submit-error');
    </script>
@endpush
