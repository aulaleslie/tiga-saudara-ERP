@extends('layouts.app')

@section('title', 'Upload Harga Tier Dua Perusahaan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.imports.index') }}">Upload Produk</a></li>
        <li class="breadcrumb-item active">Upload Harga Tier Dua Perusahaan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="dual-company-tier-price-upload-form" action="{{ route('products.dual-company-tier-price.upload') }}" method="POST" enctype="multipart/form-data">
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
                            <i class="bi bi-upload"></i> Upload Harga Tier Dua Perusahaan
                        </button>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-tags"></i> Upload Harga Tier Dua Perusahaan</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <span class="badge bg-dark text-white" style="font-size: 0.9rem;">
                                    <i class="bi bi-tags"></i> Tipe Import: Harga Tier Dua Perusahaan
                                </span>
                            </div>

                            <div class="form-row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-info d-flex align-items-center">
                                        <i class="bi bi-info-circle-fill me-2" style="font-size: 1.25rem;"></i>
                                        <div class="ms-2">
                                            Import ini khusus untuk memperbarui <strong>Harga Jual</strong>, <strong>Harga Tier 1</strong>, dan <strong>Harga Tier 2</strong>
                                            dari file hasil export <code>product:export-tiga-nusa-prices</code>.
                                            <br>
                                            Proses ini <strong>TIDAK AKAN</strong> membuat produk baru, membuat baris harga baru, mengubah stok, harga beli, pajak, bundel, ataupun harga konversi.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-warning">
                                        <strong><i class="bi bi-file-earmark-spreadsheet"></i> Struktur File yang Diwajibkan:</strong>
                                        <ul class="mb-2 mt-2">
                                            <li>Tepat dua worksheet: <code>CV TIGA NUSA COMPUTER</code> dan <code>CV TOP IT INTERNUSA</code>. Worksheet lain akan menggagalkan batch.</li>
                                            <li>Baris ke-4 adalah baris header dengan kolom wajib: <code>Nama Produk</code>, <code>Harga Jual</code>, <code>Harga Tier 1</code>, <code>Harga Tier 2</code>.</li>
                                            <li>Kolom <code>Harga Beli Terakhir</code> dan <code>Harga Beli Rata-rata</code> diabaikan.</li>
                                            <li>Nama worksheet menentukan perusahaan pemilik harga — penanda pemilik pada nama produk tidak digunakan.</li>
                                        </ul>
                                        <small class="text-muted d-block">
                                            Sel harga yang dikosongkan akan <strong>mempertahankan</strong> harga lama untuk tier tersebut. Nilai <code>0</code> dianggap sebagai perubahan harga yang disengaja.
                                            Baris dengan nama produk yang tidak cocok atau cocok lebih dari satu tidak akan mengubah harga.
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="file">File Excel Harga Dua Perusahaan <span class="text-danger">*</span></label>
                                        <div class="custom-file">
                                            <input type="file" name="file" id="file" class="custom-file-input @error('file') is-invalid @enderror" accept=".xlsx" required>
                                            <label class="custom-file-label" for="file">Pilih file...</label>
                                            @error('file')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <small class="form-text text-muted">Format yang didukung: .xlsx</small>
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
