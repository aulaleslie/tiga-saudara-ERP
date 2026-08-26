@extends('layouts.app')

@section('title', 'Riwayat Pembaruan Produk & Harga')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-dark">
                        <i class="bi bi-clock-history mr-2 text-primary"></i> Riwayat Pembaruan Produk & Harga
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Filters Form -->
                    <form method="GET" action="{{ route('products.price-feed.index') }}" class="mb-4">
                        <div class="form-row">
                            <div class="form-group col-md-3">
                                <label for="search" class="small font-weight-bold">Pencarian Token</label>
                                <input type="text" name="search" id="search" class="form-control form-control-sm" placeholder="Nama / Kode Produk / Paket..." value="{{ $filters['search'] ?? '' }}">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="setting_id" class="small font-weight-bold">Bisnis / Unit</label>
                                <select name="setting_id" id="setting_id" class="form-control form-control-sm">
                                    <option value="">-- Semua Bisnis --</option>
                                    @foreach($businesses as $b)
                                        <option value="{{ $b['id'] }}" {{ ($filters['setting_id'] ?? '') == $b['id'] ? 'selected' : '' }}>
                                            {{ $b['company_name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="event_type" class="small font-weight-bold">Tipe Event</label>
                                <select name="event_type" id="event_type" class="form-control form-control-sm">
                                    <option value="">-- Semua Tipe --</option>
                                    <option value="product_created" {{ ($filters['event_type'] ?? '') == 'product_created' ? 'selected' : '' }}>Produk Baru</option>
                                    <option value="product_price_updated" {{ ($filters['event_type'] ?? '') == 'product_price_updated' ? 'selected' : '' }}>Update Harga Produk</option>
                                    <option value="bundle_created" {{ ($filters['event_type'] ?? '') == 'bundle_created' ? 'selected' : '' }}>Paket Baru</option>
                                    <option value="bundle_price_updated" {{ ($filters['event_type'] ?? '') == 'bundle_price_updated' ? 'selected' : '' }}>Update Harga Paket</option>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="start_date" class="small font-weight-bold">Dari Tanggal</label>
                                <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="{{ $filters['start_date'] ?? '' }}">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="end_date" class="small font-weight-bold">Sampai Tanggal</label>
                                <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" value="{{ $filters['end_date'] ?? '' }}">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <a href="{{ route('products.price-feed.index') }}" class="btn btn-outline-secondary btn-sm mr-2">Reset</a>
                            <button type="submit" class="btn btn-primary btn-sm px-3">Filter</button>
                        </div>
                    </form>

                    <!-- Event List -->
                    @if($events->count() > 0)
                        <div class="list-group list-group-flush border-top mb-3">
                            @foreach($events as $event)
                                @include('product::feed.includes.event-row', ['event' => $event])
                            @endforeach
                        </div>
                        <div class="d-flex justify-content-center">
                            {{ $events->withQueryString()->links() }}
                        </div>
                    @else
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-4 d-block mb-2 text-secondary"></i>
                             Tidak ada riwayat pembaruan produk & harga yang ditemukan.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@include('product::feed.includes.detail-modal')

@endsection
