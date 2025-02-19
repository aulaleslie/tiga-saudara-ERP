@extends('layouts.app')

@section('title', 'Create Adjustment')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('adjustments.index') }}">Penyesuaian Barang Rusak</a></li>
        <li class="breadcrumb-item active">Tambahkan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <!-- Location Selection -->
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-body">
                        <form id="location-form">
                            <div class="form-group">
                                <label for="location_id">Pilih Lokasi <span class="text-danger">*</span></label>
                                <select name="location_id" id="location_id" class="form-control" required {{ old('location_id') ? 'disabled' : '' }}>
                                    <option value="" disabled {{ old('location_id') ? '' : 'selected' }}>Pilih Lokasi</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}" {{ old('location_id') == $location->id ? 'selected' : '' }}>
                                            {{ $location->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('location_id')
                                <div class="text-danger">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Hidden Input to Preserve Location -->
                            <input type="hidden" name="location_id" id="location_id_hidden" value="{{ old('location_id') }}">
                            <button type="button" id="select-location" class="btn btn-primary" {{ old('location_id') ? 'hidden' : '' }}>
                                Pilih Lokasi
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Product Search and Adjustment Form -->
            <div class="col-12">
                <div id="adjustment-form" class="{{ old('location_id') ? '' : 'd-none' }}">
                    <livewire:search-product :locationId="old('location_id')"/>

                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    @include('utils.alerts')
                                    <form action="{{ route('adjustments.storeBreakage') }}" method="POST">
                                        @csrf

                                        <!-- Hidden input for location_id -->
                                        <input type="hidden" name="location_id" id="location_id_hidden_form" value="{{ old('location_id') }}">

                                        <div class="form-row">
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <label for="reference">Keterangan <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="reference" required readonly value="BRK">
                                                </div>
                                            </div>
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <label for="date">Tanggal <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Livewire Component for Products -->
                                        <livewire:adjustment.breakage-product-table
                                            :type="'sub'"
                                            :locationId="old('location_id')"
                                            :serial_numbers="old('serial_numbers')"
                                            :is_taxables="old('is_taxables')"/>

                                        <div class="form-group">
                                            <label for="note">Catatan (Jika Dibutuhkan)</label>
                                            <textarea name="note" id="note" rows="5" class="form-control">{{ old('note') }}</textarea>
                                        </div>

                                        <div class="mt-3">
                                            <a href="{{ route('adjustments.index') }}" class="btn btn-secondary mr-2">
                                                Kembali
                                            </a>
                                            @canany('break.create')
                                                <button type="submit" class="btn btn-primary">
                                                    Buat Penyesuaian Barang Rusak <i class="bi bi-check"></i>
                                                </button>
                                            @endcanany
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> <!-- End Adjustment Form -->
            </div>
        </div>
    </div>
@endsection

@section('third_party_scripts')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var locationId = document.getElementById('location_id_hidden').value;

            if (locationId) {
                document.getElementById('adjustment-form').classList.remove('d-none');
                document.getElementById('location_id').setAttribute('disabled', 'disabled');
                document.getElementById('select-location').classList.add('d-none');

                // Ensure hidden field in the adjustment form is updated
                document.getElementById('location_id_hidden_form').value = locationId;

                // Trigger Livewire update
                Livewire.dispatch('locationSelected', {locationId: locationId});
            }
        });

        document.getElementById('select-location').addEventListener('click', function () {
            var locationId = document.getElementById('location_id').value;

            if (locationId) {
                document.getElementById('adjustment-form').classList.remove('d-none');
                document.getElementById('location_id_hidden').value = locationId;
                document.getElementById('location_id_hidden_form').value = locationId;
                document.getElementById('location_id').setAttribute('disabled', 'disabled');
                document.getElementById('select-location').classList.add('d-none');

                // Trigger Livewire update
                Livewire.dispatch('locationSelected', {locationId: locationId});
            }
        });
    </script>
@endsection
