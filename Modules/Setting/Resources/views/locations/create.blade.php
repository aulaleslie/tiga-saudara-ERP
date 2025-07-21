@extends('layouts.app')

@section('title', 'Buat Lokasi')

@section('content')
    <div class="container-fluid">
        <form action="{{ route('locations.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Lokasi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="name" required>
                                    </div>
                                </div>
                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('locations.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <button class="btn btn-primary">Tambahkan Lokasi <i class="bi bi-check"></i></button>
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
