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

        <form id="location-create-form" action="{{ route('locations.store') }}" method="POST">
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

                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="pos_cash_threshold">
                                            Ambang Kas POS (opsional)
                                            <span class="badge badge-warning align-middle ml-1">Deprecated</span>
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            id="pos_cash_threshold"
                                            name="pos_cash_threshold"
                                            value="{{ old('pos_cash_threshold', $defaultCashThreshold) }}"
                                            class="form-control @error('pos_cash_threshold') is-invalid @enderror"
                                        >
                                        <small class="text-muted">Fitur ini akan dihentikan; biarkan kosong kecuali Anda masih membutuhkan batasan lama.</small>
                                        @error('pos_cash_threshold')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('locations.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                                        <x-button label="Tambahkan Lokasi" icon="bi-check" processing-text="Memproses…" />
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

@push('page_scripts')
    <script src="{{ asset('js/form-submission-lock.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initFormSubmissionLock('location-create-form', {
                errorEventName: 'location:submit-error'
            });
        });
    </script>
@endpush
