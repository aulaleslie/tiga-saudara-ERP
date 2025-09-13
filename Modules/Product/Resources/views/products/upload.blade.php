@extends('layouts.app')

@section('title', 'Upload Produk')

@section('content')
    <div class="container-fluid">
        {{-- Download template (GET) --}}
        <div class="mb-3">
            <a href="{{ route('products.upload.template') }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                <i class="bi bi-filetype-csv"></i> Unduh Template CSV
            </a>
        </div>

        <form id="product-upload-form" action="{{ route('products.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <label for="location">Pilih Lokasi</label>
                        <select name="location_id" id="location" class="form-control" required>
                            <option value="">Pilih Lokasi</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="form-group">
                        <label for="file">Pilih File CSV</label>
                        <input type="file" name="file" id="file" class="form-control" accept=".csv" required>
                    </div>
                </div>

                <div class="col-lg-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Upload Produk
                    </button>
                    {{-- If you prefer your blade component, you can keep this instead: --}}
                    {{-- <x-button label="Upload Produk" icon="bi-upload"/> --}}
                </div>
            </div>
        </form>
    </div>
@endsection
