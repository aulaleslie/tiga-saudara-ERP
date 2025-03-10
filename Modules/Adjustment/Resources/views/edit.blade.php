@extends('layouts.app')

@section('title', 'Edit Adjustment')

@push('page_css')
    @livewireStyles
@endpush

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('adjustments.index') }}">Penyesuaian</a></li>
        <li class="breadcrumb-item active">Ubah</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <div class="col-12">
                <!-- Pass the location_id to the SearchProduct Livewire component -->
                <livewire:search-product :locationId="$adjustment->location_id"/>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')
                        <form action="{{ route('adjustments.update', $adjustment) }}" method="POST">
                            @csrf
                            @method('patch')
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Keterangan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required value="{{ $adjustment->getAttributes()['reference'] }}" readonly>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="from-group">
                                        <div class="form-group">
                                            <label for="date">Tanggak <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="date" required value="{{ $adjustment->getAttributes()['date'] }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <livewire:adjustment.product-table :adjustedProducts="$adjustment->adjustedProducts->toArray()" :locationId="$adjustment->location_id"/>
                            <div class="form-group">
                                <label for="note">Catatan (Jika Dibutuhkan)</label>
                                <textarea name="note" id="note" rows="5" class="form-control">{{ $adjustment->note }}</textarea>
                            </div>
                            <div class="mt-3">
                                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary mr-2">
                                    Kembali
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    Perbaharui Penyesuaian <i class="bi bi-check"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
