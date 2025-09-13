@php use Modules\Setting\Entities\Location; @endphp
@extends('layouts.app')
@section('content')
    <h1 class="text-xl font-semibold mb-4">Upload Produk (CSV)</h1>

    <form method="POST" action="{{ route('products.imports.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="block">Lokasi</label>
            <select name="location_id" class="form-select" required>
                @foreach(Location::query()->orderBy('name')->get() as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="block">File CSV</label>
            <input type="file" name="file" accept=".csv" class="form-input" required/>
        </div>
        <button class="btn btn-primary">Upload</button>
    </form>
@endsection
