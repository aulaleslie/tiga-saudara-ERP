@extends('layouts.app')

@section('title', 'Import Status')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.imports.index') }}">Upload</a></li>
        <li class="breadcrumb-item active">Batch #{{ $batch->id }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <a href="{{ route('sales.imports.index') }}" class="btn btn-secondary me-2">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
                <h4 class="d-inline-block align-middle mb-0">Batch #{{ $batch->id }}</h4>
            </div>
            <div>
                <span class="badge 
                    @if($batch->status === 'completed') bg-success
                    @elseif($batch->status === 'failed') bg-danger
                    @elseif($batch->status === 'processing') bg-warning
                    @else bg-secondary
                    @endif">
                    {{ ucfirst($batch->status) }}
                </span>
                <span class="ms-2">{{ $batch->success_count }}/{{ $batch->total_rows }} berhasil</span>
                @if($batch->error_count > 0)
                    <span class="text-danger ms-2">({{ $batch->error_count }} error)</span>
                @endif
            </div>
        </div>

        {{-- Summary Card --}}
        <div class="card mb-4">
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

        {{-- All Rows Table --}}
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-list-ul"></i> Semua Baris Import</h6>
            </div>
            <div class="card-body">
                {{-- Filter Form --}}
                <form action="{{ route('sales.imports.show', $batch) }}" method="GET" class="mb-3">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select name="status" class="form-select form-control">
                                <option value="">Status: Semua</option>
                                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="valid" {{ request('status') == 'valid' ? 'selected' : '' }}>Valid</option>
                                <option value="invalid" {{ request('status') == 'invalid' ? 'selected' : '' }}>Invalid (Error)</option>
                                <option value="processed" {{ request('status') == 'processed' ? 'selected' : '' }}>Processed</option>
                                <option value="skipped" {{ request('status') == 'skipped' ? 'selected' : '' }}>Skipped</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Cari Error, Customer, Produk..." value="{{ request('search') }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                        <div class="col-md-2">
                            @if(request()->hasAny(['status', 'search']))
                                <a href="{{ route('sales.imports.show', $batch) }}" class="btn btn-outline-secondary w-100">Reset</a>
                            @endif
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                                <th>Customer</th>
                                <th>Produk</th>
                                <th>Kuantitas</th>
                                <th>Harga</th>
                                <th>Error</th>
                                <th>Sale</th>
                                <th>Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td>{{ $row->row_number }}</td>
                                    <td>
                                        @if($row->status === 'processed')
                                            <span class="badge bg-success">processed</span>
                                        @elseif($row->status === 'invalid')
                                            <span class="badge bg-danger">invalid</span>
                                        @elseif($row->status === 'valid')
                                            <span class="badge bg-info">valid</span>
                                        @elseif($row->status === 'skipped')
                                            <span class="badge bg-warning">skipped</span>
                                        @else
                                            <span class="badge bg-secondary">{{ $row->status ?? 'pending' }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $row->raw_json['tanggal'] ?? '-' }}</td>
                                    <td>{{ Str::limit($row->raw_json['customer'] ?? '-', 20) }}</td>
                                    <td>{{ Str::limit($row->raw_json['produk'] ?? '-', 25) }}</td>
                                    <td>{{ $row->raw_json['kuantitas'] ?? '-' }}</td>
                                    <td>{{ number_format((float) ($row->raw_json['harga_satuan'] ?? 0), 0, ',', '.') }}</td>
                                    <td style="max-width:200px;">
                                        @if($row->error_message)
                                            <small class="text-danger">{{ $row->error_message }}</small>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if($row->sale_id)
                                            <a href="{{ route('sales.show', $row->sale_id) }}" class="btn btn-sm btn-outline-primary">
                                                #{{ $row->sale_id }}
                                            </a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td style="max-width:300px;">
                                        <code class="small">{{ json_encode($row->raw_json, JSON_UNESCAPED_UNICODE) }}</code>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted">Tidak ada data</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                {{ $rows->links() }}
            </div>
        </div>

        <div class="mt-4">
            <a href="{{ route('sales.imports.index') }}" class="btn btn-primary">
                <i class="bi bi-upload"></i> Upload Lagi
            </a>
            <a href="{{ route('sales.index') }}" class="btn btn-secondary">
                Kembali ke Penjualan
            </a>
        </div>
    </div>
@endsection
