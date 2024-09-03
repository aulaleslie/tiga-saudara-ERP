@extends('layouts.app')

@section('title', 'Upload Produk')

@section('content')
    <div class="container-fluid">
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
                    <x-button label="Upload Produk" icon="bi-upload"/>
                </div>
            </div>
        </form>
    </div>
@endsection
