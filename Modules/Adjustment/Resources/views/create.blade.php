@extends('layouts.app')

@section('title', 'Buat Penyesuaian')

@section('content')
    <div class="container-fluid mb-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        @include('utils.alerts')
                        <form action="{{ route('adjustments.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', $idempotencyToken) }}">

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Keterangan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required readonly value="ADJ">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="location">Lokasi</label>
                                        <livewire:auto-complete.location-loader :locationId="old('location_id')" />
                                        @error('location_id') <span class="text-danger">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-12">
                                    <livewire:purchase.search-product />
                                </div>
                            </div>

                            <br>

                            <div class="form-row">
                                <div class="col-lg-12">
                                    <livewire:adjustment.adjustment-product-table
                                        :type="'sub'"
                                        :locationId="old('location_id')"
                                        :serial_numbers="old('serial_numbers')"
                                        :product_ids="old('product_ids')"
                                        :quantities="old('quantities')"
                                        :is_taxables="old('is_taxables')"/>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="note">Catatan (Jika Dibutuhkan)</label>
                                <textarea name="note" id="note" rows="5" class="form-control">{{ old('note') }}</textarea>
                            </div>



                            <div class="mt-3">
                                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary mr-2">
                                    Kembali
                                </a>
                                @can('adjustments.create')
                                    <button type="submit" class="btn btn-primary">
                                        Buat Penyesuaian <i class="bi bi-check"></i>
                                    </button>
                                @endcan
                            </div>
                        </form>
                    </div>
                </div> <!-- End Card -->
            </div>
        </div>
    </div>
@endsection
