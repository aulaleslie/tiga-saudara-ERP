@extends('layouts.app')

@section('title', 'Edit Lokasi')

@section('content')
    <div class="container-fluid">
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
                                        <input type="text" class="form-control" name="name" required
                                               value="{{ $location->name }}">
                                    </div>

                                    {{-- New is_pos checkbox --}}
                                    <div class="form-group form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="is_pos" name="is_pos" value="1"
                                            {{ old('is_pos', $location->is_pos) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_pos">Gunakan lokasi ini untuk POS</label>
                                    </div>
                                </div>

                                <div class="col-lg-12 d-flex justify-content-end">
                                    <div class="form-group">
                                        <a href="{{ route('locations.index') }}" class="btn btn-secondary mr-2">
                                            Kembali
                                        </a>
                                        <button class="btn btn-primary">Update Lokasi <i class="bi bi-check"></i></button>
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
