@extends('layouts.app')

@section('title', 'Upload Penjualan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
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
                        <h5 class="mb-0">Upload Penjualan dari CSV</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Format Template</h6>
                            <p class="mb-2">Kolom yang diperlukan:</p>
                            <ul class="mb-2">
                                <li><strong>Tanggal</strong> - Format: DD/MM/YYYY (contoh: 09/02/2020)</li>
                                <li><strong>Nama Panggilan</strong> - Nama customer</li>
                                <li><strong>Nomor Transaksi</strong> - Nomor invoice/faktur asli</li>
                                <li><strong>Tag</strong> - Penanda tenant (prioritas utama)</li>
                                <li><strong>Nama Produk</strong> - Nama produk</li>
                                <li><strong>Kuantitas</strong> - Jumlah</li>
                                <li><strong>Satuan</strong> - Unit (PCS, UNIT, SET, dll)</li>
                                <li><strong>Harga per Unit</strong> - Harga per unit sebelum pajak</li>
                                <li><strong>Tarif Pajak</strong> - Persentase pajak (opsional)</li>
                                <li><strong>Jumlah Pajak</strong> - Jumlah pajak per baris (opsional)</li>
                                <li><strong>Sisa Tagihan Hari Ini</strong> - Saldo piutang (untuk status PAID/UNPAID)</li>
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

                        <form id="upload-form" action="{{ route('sales.upload.store') }}" method="POST" enctype="multipart/form-data">
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
                                <a href="{{ route('sales.upload.template') }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-download"></i> Download Template
                                </a>
                                <a href="{{ route('sales.index') }}" class="btn btn-secondary">
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
        document.getElementById('upload-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            let fileInput = document.getElementById('file');
            let file = fileInput.files[0];
            
            if (!file) return;

            // If file is small (< 1MB), just submit normally for simplicity (optional, but good for backward compatibility)
            // Actually, let's use chunked for everything to be consistent or just for > 1MB.
            // But since we modified the controller to prefer is_chunked, let's just do chunked for everything > 1MB.
            // If < 1MB, we can standard submit.
            // Note: Standard submit might trigger the 'file' validation which expects a file object, 
            // processUploadedFile expects file object.
            
            const CHUNK_SIZE = 1 * 1024 * 1024; // 1MB
            
            if (file.size <= CHUNK_SIZE) {
                // Determine if we should just submit the form normally
                // But wait, the controller now branches based on is_chunked or 'file' input.
                // Standard submit sends 'file'.
                this.submit();
                return;
            }

            // Start Chunked Upload
            let btn = this.querySelector('button[type="submit"]');
            let originalBtnText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Initializing...';
            
            let totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            let chunkIndex = 0;
            let fileId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            function uploadNextChunk() {
                let start = chunkIndex * CHUNK_SIZE;
                let end = Math.min(start + CHUNK_SIZE, file.size);
                let chunk = file.slice(start, end);
                
                let formData = new FormData();
                formData.append('is_chunked', '1');
                formData.append('file_id', fileId);
                formData.append('file_name', file.name);
                formData.append('chunk_index', chunkIndex);
                formData.append('total_chunks', totalChunks);
                formData.append('chunk', chunk, file.name); // Important: name needed for Laravel validation if strict
                formData.append('_token', document.querySelector('input[name="_token"]').value);

                // Update UI
                let percent = Math.round((chunkIndex / totalChunks) * 100);
                btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Uploading ${percent}%`;

                fetch("{{ route('sales.upload.store') }}", {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                         throw new Error(data.error);
                    }
                    
                    if (data.status === 'completed' && data.redirect_url) {
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Selesai!';
                        window.location.href = data.redirect_url;
                        return;
                    }
                    
                    // Next chunk
                    chunkIndex++;
                    if (chunkIndex < totalChunks) {
                        uploadNextChunk();
                    } else {
                        // Should have been completed in the last step, but if logic differs
                    }
                })
                .catch(error => {
                    console.error('Upload error:', error);
                    alert('Upload gagal: ' + error.message);
                    btn.disabled = false;
                    btn.innerHTML = originalBtnText;
                });
            }
            
            uploadNextChunk();
        });
    </script>
@endpush
