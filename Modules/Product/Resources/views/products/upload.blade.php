@extends('layouts.app')

@section('title', 'Upload Produk')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.imports.index') }}">Upload Produk</a></li>
        <li class="breadcrumb-item active">Upload Baru</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="product-upload-form" action="{{ route('products.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf

            @if($errors->any())
                <div class="alert alert-danger">
                    <strong>Periksa kembali data yang Anda masukkan.</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="row">
                <div class="col-lg-12">
                    <div class="form-group">
                        <a href="{{ route('products.imports.index') }}" class="btn btn-secondary mr-2">Kembali</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Upload Produk
                        </button>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            {{-- Template Download --}}
                            <div class="form-row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-info d-flex align-items-center">
                                        <i class="bi bi-info-circle-fill me-2" style="font-size: 1.25rem;"></i>
                                        <div class="ms-2">
                                            <strong>Petunjuk:</strong> Unduh template CSV terlebih dahulu, isi data produk sesuai format, kemudian upload file tersebut.
                                            <a href="{{ route('products.upload.template') }}" class="btn btn-outline-primary btn-sm ms-3" download="template_upload_produk.csv">
                                                <i class="bi bi-download"></i> Unduh Template CSV
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- File Upload --}}
                            <div class="form-row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="file">File CSV <span class="text-danger">*</span></label>
                                        <div class="custom-file">
                                            <input type="file" name="file" id="file" class="custom-file-input @error('file') is-invalid @enderror" accept=".csv" required>
                                            <label class="custom-file-label" for="file">Pilih file...</label>
                                            @error('file')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <small class="form-text text-muted">Format yang didukung: .csv (maksimal 10MB)</small>
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

@push('page_scripts')
<script>
    // Update file input label with selected filename
    document.getElementById('file').addEventListener('change', function(e) {
        var fileName = e.target.files[0] ? e.target.files[0].name : 'Pilih file...';
        var label = e.target.nextElementSibling;
        label.textContent = fileName;
    });
</script>
@endpush
