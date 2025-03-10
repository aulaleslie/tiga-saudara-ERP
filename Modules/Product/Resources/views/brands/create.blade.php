@extends('layouts.app')

@section('title', 'Create Brand')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('brands.index') }}">Merek</a></li>
        <li class="breadcrumb-item active">Tambahkan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form action="{{ route('brands.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Merek <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" id="name" required>
                                    </div>
                                </div>
                                <div class="col-lg-12">
                                    <div class="form-group">
                                        <label for="description">Keterangan</label>
                                        <textarea class="form-control" name="description" id="description" rows="4"></textarea>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('brands.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <button class="btn btn-primary">Tambah Merek <i class="bi bi-check"></i></button>
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
