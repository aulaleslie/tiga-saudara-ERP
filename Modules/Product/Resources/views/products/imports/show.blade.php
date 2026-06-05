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
                @if($batch->import_type === 'stock_snapshot')
                    <span class="badge bg-info text-white" style="font-size: 0.85rem;">
                        <i class="bi bi-box-seam"></i> Stok Snapshot
                    </span>
                @else
                    <span class="badge bg-primary text-white" style="font-size: 0.85rem;">
                        <i class="bi bi-box"></i> Produk
                    </span>
                @endif
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
                    @if($batch->import_type === 'stock_snapshot')
                        {{-- Stock Snapshot Enhanced Row Table --}}
                        <table class="table table-sm mb-0 table-striped">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Status</th>
                            <th>Produk</th>
                            <th>Pemilik</th>
                            <th>Lokasi</th>
                            <th>Total Qty</th>
                            <th>Prev Qty</th>
                            <th>After Qty</th>
                            <th>Tax / Non-Tax</th>
                            <th>Txn ID</th>
                            <th>Error</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            @php
                                $meta = $r->result_metadata ?? [];
                            @endphp
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
                                <td>
                                    @if(!empty($meta['clean_product_name']))
                                        <span title="Product ID: {{ $r->product_id }}">{{ $meta['clean_product_name'] }}</span>
                                        @if(!empty($meta['raw_marker']))
                                            <br><small class="text-muted">Marker: <code>{{ $meta['raw_marker'] }}</code></small>
                                        @endif
                                    @elseif($r->product_id)
                                        <span>ID: {{ $r->product_id }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['owner_setting_name']))
                                        <small>{{ $meta['owner_setting_name'] }}</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['target_location_name']))
                                        <small>{{ $meta['target_location_name'] }}</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['total_quantity']))
                                        <strong>{{ $meta['total_quantity'] }}</strong>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['previous_quantity']))
                                        {{ $meta['previous_quantity'] }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['after_quantity']))
                                        {{ $meta['after_quantity'] }}
                                        @if(isset($meta['delta_quantity']) && $meta['delta_quantity'] != 0)
                                            <br><small class="{{ $meta['delta_quantity'] > 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $meta['delta_quantity'] > 0 ? '+' : '' }}{{ $meta['delta_quantity'] }}
                                            </small>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['after_quantity_tax']) || isset($meta['after_quantity_non_tax']))
                                        <small>
                                            T: {{ $meta['after_quantity_tax'] ?? 0 }}
                                            @if(isset($meta['delta_quantity_tax']) && $meta['delta_quantity_tax'] != 0)
                                                (<span class="{{ $meta['delta_quantity_tax'] > 0 ? 'text-success' : 'text-danger' }}">{{ $meta['delta_quantity_tax'] > 0 ? '+' : '' }}{{ $meta['delta_quantity_tax'] }}</span>)
                                            @endif
                                            <br>
                                            NT: {{ $meta['after_quantity_non_tax'] ?? 0 }}
                                            @if(isset($meta['delta_quantity_non_tax']) && $meta['delta_quantity_non_tax'] != 0)
                                                (<span class="{{ $meta['delta_quantity_non_tax'] > 0 ? 'text-success' : 'text-danger' }}">{{ $meta['delta_quantity_non_tax'] > 0 ? '+' : '' }}{{ $meta['delta_quantity_non_tax'] }}</span>)
                                            @endif
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if($r->created_txn_id)
                                        <small class="text-muted">#{{ $r->created_txn_id }}</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td style="max-width:300px;"><small class="text-danger">{{ $r->error_message }}</small></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @else
                        {{-- Default Product Import Row Table --}}
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
                    @endif
                </div>
            </div>
            <div class="card-footer">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
@endsection
