@extends('layouts.app')

@section('title', 'Edit Penyesuaian')

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
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')
                        <form action="{{ route('adjustments.update', $adjustment) }}" method="POST" id="adjustment-edit-form">
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
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date" required value="{{ old('date', $adjustment->getAttributes()['date']) }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="location">Lokasi <span class="text-danger">*</span></label>
                                        @livewire('modules.setting.location-search-dropdown', [
                                            'selected' => old('location_id', $adjustment->location_id),
                                            'consignmentFilter' => 'standard',
                                            'placeholder' => 'Pilih lokasi...',
                                            'dispatchTo' => \App\Livewire\Adjustment\AdjustmentProductTable::class,
                                        ])
                                        @error('location_id') <span class="text-danger small">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>

                            <br>

                            <livewire:adjustment.adjustment-product-table
                                :adjustment="$adjustment"
                                :adjustedProducts="$adjustment->adjustedProducts->toArray()"
                                :locationId="old('location_id', $adjustment->location_id)"/>

                            <div class="form-group mt-3">
                                <label for="note">Catatan (Jika Dibutuhkan)</label>
                                <textarea name="note" id="note" rows="4" class="form-control">{{ old('note', $adjustment->note) }}</textarea>
                            </div>

                            <div class="mt-3">
                                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary mr-2">
                                    Kembali
                                </a>
                                <x-button type="submit" class="btn btn-primary" processing-text="Menyimpan..." form="adjustment-edit-form">
                                    Perbaharui Proposal <i class="bi bi-check"></i>
                                </x-button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    @livewireScripts
    <script>
        // Initialize form submission lock
        initFormSubmissionLock('adjustment-edit-form', 'adjustment:submit-error');
    </script>
@endpush
