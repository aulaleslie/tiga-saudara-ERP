@extends('layouts.app')

@section('title', 'Edit Lokasi')

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

        <form id="location-edit-form" action="{{ route('locations.update', $location) }}" method="POST">
            @csrf
            @method('put')
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">

                                    <div class="form-group">
                                        <label for="name">Location Name <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            id="name"
                                            name="name"
                                            class="form-control @error('name') is-invalid @enderror"
                                            value="{{ old('name', $location->name) }}"
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
                                        <x-button label="Update Lokasi" icon="bi-check" processing-text="Memproses…" />
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
            initFormSubmissionLock('location-edit-form', {
                errorEventName: 'location:submit-error'
            });
        });
    </script>
@endpush
