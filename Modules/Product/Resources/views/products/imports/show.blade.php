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
                @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT)
                    <span class="badge bg-secondary text-white" style="font-size: 0.85rem;">
                        <i class="bi bi-file-earmark-spreadsheet"></i> HPP Snapshot
                    </span>
                @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT)
                    <span class="badge bg-success text-white" style="font-size: 0.85rem;">
                        <i class="bi bi-box-seam"></i> Harga Jual & Stok Snapshot
                    </span>
                @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE)
                    <span class="badge bg-dark text-white" style="font-size: 0.85rem;">
                        <i class="bi bi-tags"></i> Harga Tier Dua Perusahaan
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

        @if($batch->error_message)
            <div class="alert alert-danger">
                <strong>Batch Error:</strong> {{ $batch->error_message }}
            </div>
        @endif

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
                                    @elseif(($meta['report_type'] ?? null) === 'sales_hpp_uncovered_sale_detail')
                                        <span title="Product ID: {{ $r->product_id }}">{{ $meta['matched_product_name'] ?? '-' }}</span>
                                        <br><small class="text-warning">Sales tanpa HPP snapshot</small>
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
                    @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT)
                        {{-- HPP Snapshot Enhanced Row Table --}}
                        <table class="table table-sm mb-0 table-striped">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Status</th>
                            <th>Produk</th>
                            <th>Pemilik</th>
                            <th>Txn ID</th>
                            <th>Qty (Mutasi)</th>
                            <th>HPP (Imported)</th>
                            <th>Prev Unit Cost</th>
                            <th>After Unit Cost</th>
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
                                    @if(!empty($meta['matched_sale_id']))
                                        <small class="text-muted">#{{ $meta['matched_sale_id'] }}</small>
                                        @if(!empty($meta['imported_sales_reference_number']))
                                            <br><small class="text-muted">{{ $meta['imported_sales_reference_number'] }}</small>
                                        @endif
                                        @if(!empty($meta['sale_date']))
                                            <br><small class="text-muted">{{ $meta['sale_date'] }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['source_quantity']))
                                        {{ $meta['source_quantity'] }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['imported_hpp']))
                                        {{ format_currency($meta['imported_hpp']) }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['previous_cost_unit']))
                                        {{ format_currency($meta['previous_cost_unit']) }}
                                        @if(!empty($meta['previous_source']))
                                            <br><small class="text-muted">Src: {{ $meta['previous_source'] }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['after_cost_unit']))
                                        <span class="text-success">{{ format_currency($meta['after_cost_unit']) }}</span>
                                        <br><small class="text-success">Src: HPP_SNAPSHOT_IMPORT</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td style="max-width:300px;"><small class="text-danger">{{ $r->error_message }}</small></td>
                            </tr>
                        @endforeach
                        </tbody>
                        </table>
                    @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE)
                        {{-- Dual-Company Tier Price Row Table (no undo: prices are not generically reversible) --}}
                        <table class="table table-sm mb-0 table-striped">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Status</th>
                            <th>Worksheet / Perusahaan</th>
                            <th>Produk</th>
                            <th>Tier Diisi</th>
                            <th>Harga Sebelumnya</th>
                            <th>Harga Setelahnya</th>
                            <th>Berubah</th>
                            <th>Error</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($rows as $r)
                            @php
                                $meta = $r->result_metadata ?? [];
                                $tierLabels = [
                                    'sale_price' => 'Harga Jual',
                                    'tier_1_price' => 'Tier 1',
                                    'tier_2_price' => 'Tier 2',
                                ];
                            @endphp
                            <tr>
                                <td>{{ $r->row_number }}</td>
                                <td>
                                    @if($r->status === 'imported')
                                        <span class="badge bg-success">imported</span>
                                    @elseif($r->status === 'error')
                                        <span class="badge bg-danger">error</span>
                                    @elseif($r->status === 'skipped' && ($meta['outcome'] ?? '') === 'duplicate')
                                        <span class="badge bg-warning text-dark">duplicate</span>
                                    @elseif($r->status === 'skipped')
                                        <span class="badge bg-secondary">skipped</span>
                                    @else
                                        <span class="badge bg-secondary">{{ $r->status ?? 'queued' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['worksheet']))
                                        <small>{{ $meta['worksheet'] }}</small>
                                        @if(!empty($meta['source_row']))
                                            <br><small class="text-muted">Baris file: {{ $meta['source_row'] }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['matched_product_name']))
                                        <span title="Product ID: {{ $r->product_id }}">{{ $meta['matched_product_name'] }}</span>
                                        @if(!empty($meta['match_strategy']))
                                            <br><small class="text-info">Match: {{ $meta['match_strategy'] }}</small>
                                        @endif
                                    @elseif(!empty($meta['raw_product_name']))
                                        <span class="text-muted">{{ $meta['raw_product_name'] }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                    @if(!empty($meta['ambiguous_candidates']))
                                        <br><small class="text-danger">
                                            Kandidat: {{ implode(', ', array_map(fn($c) => $c['id'], $meta['ambiguous_candidates'])) }}
                                        </small>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['supplied_tiers']))
                                        <small>
                                        @foreach($meta['supplied_tiers'] as $column => $value)
                                            {{ $tierLabels[$column] ?? $column }}: {{ format_currency($value) }}<br>
                                        @endforeach
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['previous_tiers']))
                                        <small>
                                        @foreach($tierLabels as $column => $label)
                                            {{ $label }}: {{ format_currency($meta['previous_tiers'][$column] ?? 0) }}<br>
                                        @endforeach
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($meta['resulting_tiers']))
                                        <small class="text-success">
                                        @foreach($tierLabels as $column => $label)
                                            {{ $label }}: {{ format_currency($meta['resulting_tiers'][$column] ?? 0) }}<br>
                                        @endforeach
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(array_key_exists('price_changed', $meta))
                                        @if($meta['price_changed'])
                                            <span class="badge bg-success">ya</span>
                                        @else
                                            <span class="badge bg-secondary">tidak</span>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td style="max-width:300px;"><small class="text-danger">{{ $r->error_message }}</small></td>
                            </tr>
                        @endforeach
                        </tbody>
                        </table>
                    @elseif($batch->import_type === \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT)
                        {{-- Sales Price & Stock Snapshot Enhanced Row Table --}}
                        <table class="table table-sm mb-0 table-striped">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Status</th>
                            <th>Produk</th>
                            <th>Pemilik</th>
                            <th>Lokasi</th>
                            <th>Imported Price</th>
                            <th>Prev Tiers</th>
                            <th>New Tiers</th>
                            <th>Imported Stock</th>
                            <th>Prev Stock</th>
                            <th>After Stock</th>
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
                                    @elseif($r->status === 'skipped' && ($meta['outcome'] ?? '') === 'duplicate')
                                        <span class="badge bg-warning text-dark">duplicate</span>
                                    @elseif($r->status === 'skipped')
                                        <span class="badge bg-secondary">skipped</span>
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
                                        @if(!empty($meta['match_strategy']))
                                            <br><small class="text-info">Match: {{ $meta['match_strategy'] }}</small>
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
                                    @elseif(!empty($meta['setting_id']))
                                        <small>Setting ID: {{ $meta['setting_id'] }}</small>
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
                                    @if(isset($meta['sell_price']))
                                        {{ format_currency($meta['sell_price']) }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['prev_tiers']))
                                        <small>
                                        Sale: {{ format_currency($meta['prev_tiers']['sale_price'] ?? 0) }}<br>
                                        T1: {{ format_currency($meta['prev_tiers']['tier_1_price'] ?? 0) }}<br>
                                        T2: {{ format_currency($meta['prev_tiers']['tier_2_price'] ?? 0) }}
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['new_tiers']))
                                        <small class="text-success">
                                        Sale: {{ format_currency($meta['new_tiers']['sale_price'] ?? 0) }}<br>
                                        T1: {{ format_currency($meta['new_tiers']['tier_1_price'] ?? 0) }}<br>
                                        T2: {{ format_currency($meta['new_tiers']['tier_2_price'] ?? 0) }}
                                        </small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($meta['imported_stock']))
                                        <strong>{{ $meta['imported_stock'] }}</strong>
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
