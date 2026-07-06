@extends('layouts.app')

@section('title', 'Upload HPP Snapshot')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.imports.index') }}">Upload Produk</a></li>
        <li class="breadcrumb-item active">Upload HPP Snapshot</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="sales-hpp-snapshot-upload-form" action="{{ route('products.sales-hpp-snapshot.upload') }}" method="POST" enctype="multipart/form-data">
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
                            <i class="bi bi-upload"></i> Upload HPP Snapshot
                        </button>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-currency-dollar"></i> Upload HPP Snapshot Ledger</h5>
                        </div>
                        <div class="card-body">
                            {{-- Import Type Badge --}}
                            <div class="mb-3">
                                <span class="badge bg-info text-white" style="font-size: 0.9rem;">
                                    <i class="bi bi-currency-dollar"></i> Tipe Import: HPP Snapshot
                                </span>
                            </div>

                            {{-- Template Download --}}
                            <div class="form-row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-info d-flex align-items-center">
                                        <i class="bi bi-info-circle-fill me-2" style="font-size: 1.25rem;"></i>
                                        <div class="ms-2">
                                            <strong>Petunjuk:</strong> Unduh template CSV HPP snapshot, isi data HPP sesuai format, kemudian upload file tersebut.
                                            <a href="{{ route('products.sales-hpp-snapshot.template') }}" class="btn btn-outline-primary btn-sm ms-3" download="template_sales_hpp_snapshot.csv">
                                                <i class="bi bi-download"></i> Unduh Template HPP Snapshot
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Owner Marker Routing Guidance --}}
                            <div class="form-row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-warning">
                                        <strong><i class="bi bi-signpost-split"></i> Aturan Penanda Pemilik (Owner Marker):</strong>
                                        <p class="mb-2 mt-2">Sistem akan menentukan pemilik berdasarkan format nama produk:</p>
                                        <table class="table table-bordered table-sm mb-0 bg-white">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th style="width: 200px;">Penanda</th>
                                                    <th>Pemilik</th>
                                                    <th>Contoh Nama Produk</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><code>KEDELE/KEDELAI/RAGI</code></td>
                                                    <td><strong>DAIZU</strong></td>
                                                    <td><code>Kedelai Putih</code></td>
                                                </tr>
                                                <tr>
                                                    <td><code>*</code> di awal nama</td>
                                                    <td><strong>CV TIGA NUSA COMPUTER</strong></td>
                                                    <td><code>* Laptop Acer Aspire 3</code></td>
                                                </tr>
                                                <tr>
                                                    <td><code>TP</code> di akhir nama</td>
                                                    <td><strong>CV TOP IT INTERNUSA</strong></td>
                                                    <td><code>Mouse Logitech TP</code></td>
                                                </tr>
                                                <tr>
                                                    <td>Tanpa penanda</td>
                                                    <td><strong>PERDANA</strong></td>
                                                    <td><code>Keyboard Mechanical</code></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <small class="text-muted mt-2 d-block">Penanda akan dihapus dari nama produk sebelum dicocokkan. Contoh: <code>* Laptop Acer</code> → <code>Laptop Acer</code></small>
                                    </div>
                                </div>
                            </div>

                            {{-- File Upload --}}
                            <div class="form-row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="file">File CSV HPP Snapshot <span class="text-danger">*</span></label>
                                        <div class="custom-file">
                                            <input type="file" name="file" id="file" class="custom-file-input @error('file') is-invalid @enderror" accept=".csv" required>
                                            <label class="custom-file-label" for="file">Pilih file...</label>
                                            @error('file')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <small class="form-text text-muted">Format yang didukung: .csv (maksimal 10MB)</small>
                                        <small class="form-text text-muted">Kolom wajib: Tipe Transaksi, No. Transaksi, Barang, Mutasi, Harga Rata-Rata</small>
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
    document.getElementById('file').addEventListener('change', function(e) {
        var fileName = e.target.files[0] ? e.target.files[0].name : 'Pilih file...';
        var label = e.target.nextElementSibling;
        label.textContent = fileName;
    });
</script>
@endpush
