@extends('layouts.app')

@section('title', 'Upload Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Upload</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                @include('utils.alerts')

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Upload Pembelian dari CSV</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Format Template</h6>
                            <p class="mb-2">Kolom yang diperlukan:</p>
                            <ul class="mb-2">
                                <li><strong>Tanggal</strong> - Format: DD/MM/YYYY (contoh: 19/03/2020)</li>
                                <li><strong>Nama Panggilan</strong> - Nama supplier</li>
                                <li><strong>Nomor Transaksi</strong> - Nomor invoice/faktur asli</li>
                                <li><strong>Tag</strong> - Penanda tenant (prioritas utama)</li>
                                <li><strong>Nama Produk</strong> - Nama produk</li>
                                <li><strong>Kuantitas</strong> - Jumlah</li>
                                <li><strong>Satuan</strong> - Unit (PCS, UNIT, SET, dll)</li>
                                <li><strong>Harga per Unit</strong> - Harga per unit sebelum pajak</li>
                                <li><strong>Tarif Pajak</strong> - Persentase pajak (opsional)</li>
                                <li><strong>Jumlah Pajak</strong> - Jumlah pajak per baris (opsional)</li>
                                <li><strong>Sisa Tagihan Hari Ini</strong> - Saldo hutang (untuk status PAID/UNPAID)</li>
                            </ul>
                            <p class="mb-1"><strong>Penanda Tenant (Prioritas 1 - Tag):</strong></p>
                            <p class="mb-2">
                                <code>CV Tiga Nusa</code> → CV Tiga Nusa Computer |
                                <code>CV Top IT</code> → CV Top IT Internusa |
                                <code>Aries</code> → Tiga Computer |
                                <code>Rahmat</code> → White Knight Computer |
                                <code>Agus</code> → Dunia Computer |
                                <code>Perdana</code> → Perdana
                            </p>
                            <p class="mb-0">
                                <strong>Penanda Produk (Prioritas 2 - jika Tag kosong):</strong>
                                <code>* Produk</code> → CV Tiga Nusa Computer |
                                <code>Produk TP</code> → CV Top IT Internusa |
                                <code>Produk</code> (tanpa marker) → Perdana
                            </p>
                        </div>

                        <form id="upload-form" action="{{ route('purchases.upload.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <div class="mb-3">
                                <label for="file" class="form-label">File CSV <span class="text-danger">*</span></label>
                                <input type="file" 
                                       class="form-control @error('file') is-invalid @enderror" 
                                       id="file" 
                                       name="file" 
                                       accept=".csv,.txt"
                                       required>
                                @error('file')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">
                                    Upload file CSV dengan format sesuai template.
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> Upload & Proses
                                </button>
                                <a href="{{ route('purchases.upload.template') }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-download"></i> Download Template
                                </a>
                                <a href="{{ route('purchases.index') }}" class="btn btn-secondary">
                                    Batal
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        document.getElementById('upload-form').addEventListener('submit', function() {
            let btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memproses...';
        });
    </script>
@endpush
