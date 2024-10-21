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
                                <label for="location_id">Pilih Lolasi <span class="text-danger">*</span></label>
                                <select name="location_id" id="location_id" class="form-control" required>
                                    <option value="" disabled selected>Pilih Lokasi</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" id="select-location" class="btn btn-primary">
                                Pilih Lokasi
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Product Search and Adjustment Form -->
            <div class="col-12">
                <div id="adjustment-form" class="d-none">
                    <livewire:search-product :locationId="old('location_id')"/>

                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    @include('utils.alerts')
                                    <form action="{{ route('adjustments.storeBreakage') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="location_id" id="location_id_hidden">
                                        <div class="form-row">
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <label for="reference">Referensi <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="reference" required
                                                           readonly value="BRK">
                                                </div>
                                            </div>
                                            <div class="col-lg-6">
                                                <div class="form-group">
                                                    <label for="date">Tanggal <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="date" required
                                                           value="{{ now()->format('Y-m-d') }}">
                                                </div>
                                            </div>
                                        </div>
                                        <livewire:adjustment.breakage-product-table :type="'sub'" :locationId="old('location_id')"/>
                                        <div class="form-group">
                                            <label for="note">Catatan (Jika Dibutuhkan)</label>
                                            <textarea name="note" id="note" rows="5" class="form-control"></textarea>
                                        </div>
                                        <div class="mt-3">
                                            <a href="{{ route('adjustments.index') }}" class="btn btn-secondary mr-2">
                                                Kembali
                                            </a>
                                            <button type="submit" class="btn btn-primary">
                                                Tambahkan <i class="bi bi-check"></i>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('third_party_scripts')
    <script>
        document.getElementById('select-location').addEventListener('click', function () {
            var locationId = document.getElementById('location_id').value;
            if (locationId) {
                // Show the adjustment form and pass the location ID
                document.getElementById('adjustment-form').classList.remove('d-none');
                document.getElementById('location_id_hidden').value = locationId;

                // Disable the location dropdown and hide the select button
                document.getElementById('location_id').setAttribute('disabled', 'disabled');
                document.getElementById('select-location').classList.add('d-none');

                // Trigger Livewire update
                Livewire.dispatch('locationSelected', {locationId: locationId});
            }
        });
    </script>
@endsection
