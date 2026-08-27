@extends('layouts.app')

@section('title', 'Sumber Terjual Konsinyasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item">Konsinyasi</li>
        <li class="breadcrumb-item active">Sumber Terjual</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-0">Sumber Penjualan Konsinyasi Eligible</h4>
                        <small class="text-muted">Data penjualan dan POS yang bersumber dari lokasi konsinyasi untuk alokasi billing supplier.</small>
                    </div>
                    <div>
                        <form method="POST" action="{{ route('consignments.sold-sources.discover') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-arrow-repeat"></i> Jalankan Deteksi
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="{{ route('consignments.sold-sources.index') }}" class="row mb-4">
                    <div class="col-md-3 mb-2">
                        <select name="product_id" class="form-control">
                            <option value="">-- Semua Produk --</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}" {{ request('product_id') == $p->id ? 'selected' : '' }}>
                                    {{ $p->product_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="location_id" class="form-control">
                            <option value="">-- Semua Lokasi Konsinyasi --</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>
                                    {{ $loc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select name="has_blocker" class="form-control">
                            <option value="">-- Status Blocker --</option>
                            <option value="1" {{ request('has_blocker') === '1' ? 'selected' : '' }}>Ada Blocker/Konflik</option>
                            <option value="0" {{ request('has_blocker') === '0' ? 'selected' : '' }}>Normal/Eligible</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-filter"></i> Filter</button>
                        <a href="{{ route('consignments.sold-sources.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>No. Penjualan / Dispatch</th>
                                <th>Lokasi</th>
                                <th>Produk</th>
                                <th>Original Sold</th>
                                <th>Retur Efektif</th>
                                <th>Pending Reserved</th>
                                <th>Approved Allocated</th>
                                <th>Remaining Qty</th>
                                <th>Blocker</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sources as $src)
                                <tr>
                                    <td>
                                        <div class="font-weight-bold">
                                            {{ $src->sale->reference ?? "Sale #{$src->sale_id}" }}
                                        </div>
                                        <small class="text-muted">Dispatch #{{ $src->dispatch_detail_id }}</small>
                                    </td>
                                    <td>{{ $src->location->name ?? '-' }}</td>
                                    <td>
                                        <div class="font-weight-bold">{{ $src->product->product_name ?? '-' }}</div>
                                        <small class="text-muted">{{ $src->product->product_code ?? '-' }}</small>
                                    </td>
                                    <td>{{ number_format($src->eligibility['original_sold'] ?? $src->original_base_quantity, 3) }}</td>
                                    <td>{{ number_format($src->eligibility['effective_returned'] ?? 0, 3) }}</td>
                                    <td>{{ number_format($src->eligibility['pending_reserved'] ?? 0, 3) }}</td>
                                    <td>{{ number_format($src->eligibility['approved_allocated'] ?? 0, 3) }}</td>
                                    <td class="font-weight-bold text-success">{{ number_format($src->eligibility['remaining_quantity'] ?? 0, 3) }}</td>
                                    <td>
                                        @if($src->has_reconstruction_blocker || ($src->eligibility['has_conflict'] ?? false))
                                            <span class="badge badge-danger" title="{{ $src->blocker_reason ?? $src->eligibility['conflict_reason'] }}">
                                                BLOCKER
                                            </span>
                                        @else
                                            <span class="badge badge-success">OK</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Tidak ada data sumber terjual konsinyasi.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $sources->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
