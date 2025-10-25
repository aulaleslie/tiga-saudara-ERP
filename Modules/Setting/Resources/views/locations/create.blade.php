@extends('layouts.app')

@section('title', 'Buat Lokasi')

@section('content')
    <div class="container-fluid">

        {{-- GLOBAL ERROR LIST --}}
        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Periksa kembali formulir Anda:</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

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
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            value="{{ old('name') }}"
                                            class="form-control @error('name') is-invalid @enderror"
                                            required
                                        >
                                        @error('name')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>

                                </div>

                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('locations.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                                        <button class="btn btn-primary">
                                            Tambahkan Lokasi <i class="bi bi-check"></i>
                                        </button>
                                    </div>
                                </div>

                            </div> {{-- .form-row --}}
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection
