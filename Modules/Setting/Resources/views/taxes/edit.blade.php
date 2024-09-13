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
        <form action="{{ route('taxes.update', $tax) }}" method="POST">
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
                                        <label for="name">Nama <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required
                                               value="{{ $tax->name }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="value">Nilai <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="value" step="0.01" value="{{ $tax->value }}" required>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('taxes.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <button class="btn btn-primary">Simpan <i class="bi bi-check"></i></button>
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
