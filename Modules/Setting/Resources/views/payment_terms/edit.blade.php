@extends('layouts.app')

@section('title', 'Ubah Term Pembayaran')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('payment-terms.index') }}">Term Pembayaran</a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">

        {{-- GLOBAL VALIDATION ERRORS --}}
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

        <form action="{{ route('payment-terms.update', $payment_term) }}" method="POST">
            @csrf
            @method('put')
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">

                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="name">Nama Term Pembayaran <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            class="form-control @error('name') is-invalid @enderror"
                                            value="{{ old('name', $payment_term->name) }}"
                                            required
                                        >
                                        @error('name')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="longevity">Tempo (hari) <span class="text-danger">*</span></label>
                                        <input
                                            type="number"
                                            id="longevity"
                                            name="longevity"
                                            class="form-control @error('longevity') is-invalid @enderror"
                                            value="{{ old('longevity', $payment_term->longevity) }}"
                                            step="1"
                                            min="0"
                                            inputmode="numeric"
                                            required
                                        >
                                        @error('longevity')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('payment-terms.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                                        <button class="btn btn-primary">Simpan <i class="bi bi-check"></i></button>
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
