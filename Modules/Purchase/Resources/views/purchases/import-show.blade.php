@extends('layouts.app')

@section('title', 'Import Status')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.imports.index') }}">Upload</a></li>
        <li class="breadcrumb-item active">Batch #{{ $batch->id }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Import Batch #{{ $batch->id }}</h5>
                        <span class="badge 
                            @if($batch->status === 'completed') bg-success
                            @elseif($batch->status === 'failed') bg-danger
                            @elseif($batch->status === 'processing') bg-warning
                            @else bg-secondary
                            @endif">
                            {{ ucfirst($batch->status) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-muted small">Total Rows</div>
                                <div class="h4">{{ $batch->total_rows }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Berhasil</div>
                                <div class="h4 text-success">{{ $batch->success_count }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Gagal</div>
                                <div class="h4 text-danger">{{ $batch->error_count }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Dibuat</div>
                                <div class="h6">{{ $batch->created_at->format('d/m/Y H:i') }}</div>
                            </div>
                        </div>

                        @if($batch->status === 'completed')
                            <div class="mt-3">
                                <div class="progress" style="height: 25px;">
                                    @php
                                        $successPct = $batch->total_rows > 0 ? round(($batch->success_count / $batch->total_rows) * 100) : 0;
                                        $errorPct = $batch->total_rows > 0 ? round(($batch->error_count / $batch->total_rows) * 100) : 0;
                                    @endphp
                                    <div class="progress-bar bg-success" style="width: {{ $successPct }}%">
                                        {{ $successPct }}% Berhasil
                                    </div>
                                    <div class="progress-bar bg-danger" style="width: {{ $errorPct }}%">
                                        {{ $errorPct }}% Gagal
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                @if($batch->rows->where('status', 'invalid')->count() > 0)
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Baris dengan Error</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Row #</th>
                                            <th>Tanggal</th>
                                            <th>Supplier</th>
                                            <th>Produk</th>
                                            <th>Error</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($batch->rows->where('status', 'invalid') as $row)
                                            <tr>
                                                <td>{{ $row->row_number }}</td>
                                                <td>{{ $row->raw_json['tanggal'] ?? '-' }}</td>
                                                <td>{{ $row->raw_json['supplier'] ?? '-' }}</td>
                                                <td>{{ $row->raw_json['produk'] ?? '-' }}</td>
                                                <td class="text-danger">{{ $row->error_message }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                @if($batch->rows->where('status', 'processed')->count() > 0)
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-check-circle"></i> Baris Berhasil Diproses</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Row #</th>
                                            <th>Tanggal</th>
                                            <th>Supplier</th>
                                            <th>Produk</th>
                                            <th>Kuantitas</th>
                                            <th>Harga</th>
                                            <th>Purchase</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($batch->rows->where('status', 'processed')->take(100) as $row)
                                            <tr>
                                                <td>{{ $row->row_number }}</td>
                                                <td>{{ $row->raw_json['tanggal'] ?? '-' }}</td>
                                                <td>{{ Str::limit($row->raw_json['supplier'] ?? '-', 20) }}</td>
                                                <td>{{ Str::limit($row->raw_json['produk'] ?? '-', 30) }}</td>
                                                <td>{{ $row->raw_json['kuantitas'] ?? '-' }}</td>
                                                <td>{{ number_format($row->raw_json['harga_satuan'] ?? 0, 0, ',', '.') }}</td>
                                                <td>
                                                    @if($row->purchase_id)
                                                        <a href="{{ route('purchases.show', $row->purchase_id) }}" class="btn btn-sm btn-outline-primary">
                                                            #{{ $row->purchase_id }}
                                                        </a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if($batch->rows->where('status', 'processed')->count() > 100)
                                <div class="card-footer text-muted">
                                    Menampilkan 100 dari {{ $batch->rows->where('status', 'processed')->count() }} baris
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="mt-4">
                    <a href="{{ route('purchases.imports.index') }}" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Upload Lagi
                    </a>
                    <a href="{{ route('purchases.index') }}" class="btn btn-secondary">
                        Kembali ke Pembelian
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
