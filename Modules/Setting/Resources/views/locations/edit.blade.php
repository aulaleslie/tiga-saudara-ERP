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

        <form action="{{ route('locations.update', $location) }}" method="POST">
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

                                    {{-- ensure a value is always sent --}}
                                    <input type="hidden" name="is_pos" value="0">

                                    {{-- is_pos checkbox --}}
                                    <div class="form-group form-check mt-2">
                                        <input
                                            type="checkbox"
                                            class="form-check-input @error('is_pos') is-invalid @enderror"
                                            id="is_pos"
                                            name="is_pos"
                                            value="1"
                                            {{ old('is_pos', optional($location->saleAssignment)->is_pos) ? 'checked' : '' }}
                                        >
                                        <label class="form-check-label" for="is_pos">
                                            Gunakan lokasi ini untuk POS
                                        </label>
                                        @error('is_pos')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>

                                </div>

                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('locations.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                                        <button class="btn btn-primary">
                                            Update Lokasi <i class="bi bi-check"></i>
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
