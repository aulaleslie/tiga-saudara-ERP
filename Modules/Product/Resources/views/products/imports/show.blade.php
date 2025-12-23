@extends('layouts.app')
@section('title',"Batch #{$batch->id}")

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <a href="{{ route('products.imports.index') }}" class="btn btn-secondary me-2">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
                <h4 class="d-inline-block align-middle mb-0">Batch #{{ $batch->id }}</h4>
            </div>
            <div>
                <span class="badge bg-secondary">{{ $batch->status }}</span>
                <span class="ms-2">{{ $batch->progress }}% ({{ $batch->processed_rows }}/{{ $batch->total_rows }})</span>
                @if($batch->canUndo())
                    <form class="d-inline" method="POST" action="{{ route('products.imports.undo',$batch) }}">
                        @csrf
                        <button class="btn btn-sm btn-warning ms-2" type="submit">Undo</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form action="{{ route('products.imports.show', $batch) }}" method="GET" class="mb-3">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select name="status" class="form-select form-control">
                                <option value="">Status: Semua</option>
                                <option value="imported" {{ request('status') == 'imported' ? 'selected' : '' }}>Imported</option>
                                <option value="error" {{ request('status') == 'error' ? 'selected' : '' }}>Error</option>
                                <option value="skipped" {{ request('status') == 'skipped' ? 'selected' : '' }}>Skipped</option>
                                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Cari Product ID, Error, atau Payload..." value="{{ request('search') }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                        <div class="col-md-2">
                            @if(request()->hasAny(['status', 'search']))
                                <a href="{{ route('products.imports.show', $batch) }}" class="btn btn-outline-secondary w-100">Reset</a>
                            @endif
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm mb-0 table-striped">
                    <thead>
                    <tr>
                        <th>#</th><th>Status</th><th>Error</th><th>Product ID</th><th>Payload</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $r)
                        <tr>
                            <td>{{ $r->row_number }}</td>
                            <td>
                                @if($r->status === 'imported')
                                    <span class="badge bg-success">imported</span>
                                @elseif($r->status === 'error')
                                    <span class="badge bg-danger">error</span>
                                @else
                                    <span class="badge bg-secondary">{{ $r->status ?? 'queued' }}</span>
                                @endif
                            </td>
                            <td style="max-width:300px;"><small class="text-danger">{{ $r->error_message }}</small></td>
                            <td>{{ $r->product_id ?? '-' }}</td>
                            <td style="max-width:520px;"><code class="small">{{ json_encode($r->raw_json, JSON_UNESCAPED_UNICODE) }}</code></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-footer">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
@endsection
