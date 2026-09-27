@extends('layouts.app')

@section('title', 'Upload Produk')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item active">Upload Produk</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @can("products.create")
                            <a href="{{ route('products.upload.page') }}" class="btn btn-primary">
                                Upload Produk Baru <i class="bi bi-plus"></i>
                            </a>
                            <a href="{{ route('products.sales-hpp-snapshot.upload.page') }}" class="btn btn-secondary text-white">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Upload HPP Snapshot
                            </a>
                        @endcan
                        @can("products.edit")
                            <a href="{{ route('products.sales-price-snapshot.upload.page') }}" class="btn btn-warning text-white">
                                <i class="bi bi-box-seam"></i> Upload Harga Jual & Stok Snapshot
                            </a>
                            <a href="{{ route('products.dual-company-tier-price.upload.page') }}" class="btn btn-dark text-white">
                                <i class="bi bi-tags"></i> Upload Harga Tier Dua Perusahaan
                            </a>
                        @endcan

                        <hr>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Dibuat Oleh</th>
                                        <th>Tipe Import</th>
                                        <th>Lokasi</th>
                                        <th>Status</th>
                                        <th>Progress</th>
                                        <th>Total</th>
                                        <th>Berhasil</th>
                                        <th>Gagal</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($batches as $b)
                                        <tr>
                                            <td>#{{ $b->id }}</td>
                                            <td>{{ optional($b->user)->name ?? '-' }}</td>
                                            <td>
                                                @if($b->import_type === 'stock_snapshot')
                                                    <span class="badge bg-info text-white">Stok Snapshot (Legacy)</span>
                                                @elseif($b->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT)
                                                    <span class="badge bg-secondary text-white">HPP Snapshot</span>
                                                @elseif($b->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT)
                                                    <span class="badge bg-success text-white">Harga Jual & Stok Snapshot</span>
                                                @elseif($b->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE)
                                                    <span class="badge bg-dark text-white">Harga Tier Dua Perusahaan</span>
                                                @else
                                                    <span class="badge bg-primary text-white">Produk</span>
                                                @endif
                                            </td>
                                            <td>{{ optional($b->location)->name ?? '-' }}</td>
                                            <td>
                                                @php
                                                    $statusClass = match($b->status) {
                                                        'completed' => 'bg-success',
                                                        'processing' => 'bg-info',
                                                        'failed' => 'bg-danger',
                                                        'pending' => 'bg-warning',
                                                        default => 'bg-secondary'
                                                    };
                                                @endphp
                                                <span class="badge {{ $statusClass }}">{{ ucfirst($b->status) }}</span>
                                            </td>
                                            <td>
                                                <div class="progress" style="min-width: 80px; height: 20px;">
                                                    <div class="progress-bar" role="progressbar" style="width: {{ $b->progress }}%;" aria-valuenow="{{ $b->progress }}" aria-valuemin="0" aria-valuemax="100">
                                                        {{ $b->progress }}%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ $b->total_rows }}</td>
                                            <td><span class="text-success">{{ $b->success_rows }}</span></td>
                                            <td><span class="text-danger">{{ $b->error_rows }}</span></td>
                                            <td>
                                                <a href="{{ route('products.imports.show', $b) }}" class="btn btn-sm btn-info">
                                                    <i class="bi bi-eye"></i> Detail
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                Belum ada batch upload. Klik "Upload Produk Baru" atau "Upload Harga Jual & Stok Snapshot" untuk memulai.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-center mt-3">
                            {{ $batches->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
