@extends('layouts.app')
@section('title',"Batch #{$batch->id}")

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>Batch #{{ $batch->id }}</h4>
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
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
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
            <div class="card-footer">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
@endsection
