@extends('layouts.app')

@section('title', 'Upload Pengeluaran')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('expenses.index') }}">Pengeluaran</a></li>
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
                        <h5 class="mb-0">Upload Pengeluaran dari CSV</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Format Template</h6>
                            <p class="mb-2">Kolom yang diperlukan:</p>
                            <ul class="mb-2">
                                <li><strong>Tanggal</strong> - Format: DD/MM/YYYY (contoh: 19/03/2020)</li>
                                <li><strong>Transaksi</strong> - Harus "Expense"</li>
                                <li><strong>Nomor</strong> - Nomor dokumen sumber</li>
                                <li><strong>Kategori</strong> - Kategori pengeluaran</li>
                                <li><strong>Deskripsi</strong> - Keterangan biaya</li>
                                <li><strong>Supplier</strong> - Nama supplier</li>
                                <li><strong>Jumlah</strong> - Nilai pengeluaran</li>
                                <li><strong>Tax</strong> - Pajak (saat ini harus 0)</li>
                                <li><strong>Status</strong> - Harus "Paid"</li>
                                <li><strong>Sisa Tagihan</strong> - Harus "0"</li>
                            </ul>
                        </div>

                        <form id="upload-form" action="{{ route('expenses.imports.upload') }}" method="POST" enctype="multipart/form-data">
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
                                <a href="{{ route('expenses.index') }}" class="btn btn-secondary">
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
